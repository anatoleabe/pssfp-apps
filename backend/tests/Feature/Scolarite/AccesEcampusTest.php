<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Services\DocumentsAuditeurs\FraisInscription;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\NotificationAcces;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->responsable = tap(User::factory()->create(['email' => 'resp-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_responsable'));
    $this->admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699000001', 'email' => 'awa@example.org',
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->dossier->refresh();
    $this->inscription = $this->dossier->auditeur->inscriptionCourante;

    $this->sms = [];
    $this->smsEnPanne = false;
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            if ($this->smsEnPanne) {
                throw new RuntimeException('passerelle');
            }
            $this->sms[] = [$tel, $texte];
        });
    });
    $this->verser = fn (int $montant = 185000) => app(RegistreVersements::class)->enregistrer($this->inscription->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-02', 'mode' => 'virement', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->responsable);
    $this->etat = fn (): string => AccesEcampus::etat(Candidature::query()->findOrFail($this->dossier->id));
    $this->compteCree = fn () => $this->dossier->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
});

it('reste bloqué sous le seuil d\'inscription', function (): void {
    ($this->compteCree)();
    ($this->verser)(184999);

    expect(($this->etat)())->toBe(AccesEcampus::BLOQUE);
});

it('devient disponible une fois l\'inscription soldée et le compte créé', function (string $statut): void {
    $this->dossier->forceFill(['ecampus_statut' => $statut])->save();
    ($this->verser)();

    expect(($this->etat)())->toBe(AccesEcampus::DISPONIBLE);
})->with([ComptesEcampus::CREE, ComptesEcampus::EXISTANT, ComptesEcampus::COHORTE_A_FINIR]);

it('reste en préparation tant que le compte eCampus n\'existe pas', function (?string $statut): void {
    $this->dossier->forceFill(['ecampus_statut' => $statut])->save();
    ($this->verser)();

    expect(($this->etat)())->toBe(AccesEcampus::EN_PREPARATION);
})->with([null, ComptesEcampus::ECHEC]);

it('est indisponible pour un dossier retiré', function (): void {
    ($this->compteCree)();
    ($this->verser)();
    $this->dossier->forceFill(['withdrawn_at' => now()])->save();

    expect(($this->etat)())->toBe(AccesEcampus::INDISPONIBLE);
});

it('est bloqué sans inscription, ou sans auditeur', function (): void {
    $this->dossier->auditeur->inscriptions()->delete();
    expect(($this->etat)())->toBe(AccesEcampus::BLOQUE);

    $this->dossier->auditeur()->delete();
    expect(($this->etat)())->toBe(AccesEcampus::BLOQUE);
});

it('une exonération totale débloque sans versement, une bourse partielle non', function (): void {
    ($this->compteCree)();
    $registre = app(RegistreVersements::class);

    $registre->accorderRemise($this->inscription, 200000, 'Bourse', $this->responsable);
    expect(($this->etat)())->toBe(AccesEcampus::BLOQUE);

    $registre->accorderRemise($this->inscription->fresh(), 1185000, 'Exonération', $this->responsable);
    expect(($this->etat)())->toBe(AccesEcampus::DISPONIBLE);
});

it('remet le code et l\'envoi manuel selon la nouvelle règle', function (): void {
    ($this->compteCree)();
    $frais = fn (): bool => FraisInscription::payes(Candidature::query()->findOrFail($this->dossier->id));
    expect($frais())->toBeFalse()
        ->and(IdentifiantsMoodle::remettables($this->dossier->fresh()))->toBeFalse();

    $v = ($this->verser)()->versement;
    expect($frais())->toBeTrue()
        ->and(IdentifiantsMoodle::remettables($this->dossier->fresh()))->toBeTrue();

    app(RegistreVersements::class)->annuler($v, 'Erreur', $this->responsable);
    expect($frais())->toBeFalse();
});

it('ne prend plus en compte l\'ancienne case seule', function (): void {
    $this->dossier->forceFill(['frais_inscription_payes_at' => now()])->save();

    expect(FraisInscription::payes($this->dossier->fresh()))->toBeFalse();
});

it('commande l\'accès par l\'année la plus récente', function (): void {
    ($this->compteCree)();
    ($this->verser)();
    expect(($this->etat)())->toBe(AccesEcampus::DISPONIBLE);

    // Passage en M2 : nouvelle inscription, nouveau seuil.
    InscriptionAnnuelle::factory()->create([
        'auditeur_id' => $this->dossier->auditeur->id, 'annee_academique' => '2027-2028', 'niveau' => 'M2',
        'bareme_id' => BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028'])->id,
        'montant_inscription' => 135000,
    ]);
    expect(($this->etat)())->toBe(AccesEcampus::BLOQUE);
});

describe('SMS « accès disponibles »', function (): void {
    it('part une seule fois, au premier solde, sans mot de passe', function (): void {
        ($this->compteCree)();

        $r = ($this->verser)(100000);
        expect($r->sms)->toBeNull()->and($this->sms)->toBeEmpty();

        $r = ($this->verser)(85000);
        expect($r->sms)->toBeTrue()
            ->and($this->sms)->toHaveCount(1)
            ->and($this->sms[0][0])->toBe('+237699000001')
            ->and($this->sms[0][1])->toBe('PSSFP : vos acces FOAD sont disponibles dans votre espace apply.pssfp.org')
            ->and($this->sms[0][1])->not->toContain((string) $this->dossier->moodle_password)
            ->and(strlen($this->sms[0][1]))->toBeLessThanOrEqual(160);

        ($this->verser)(50000);
        expect($this->sms)->toHaveCount(1);
    });

    it('ne repart pas après annulation puis ressaisie', function (): void {
        ($this->compteCree)();
        $v = ($this->verser)()->versement;
        app(RegistreVersements::class)->annuler($v, 'Erreur', $this->responsable);
        ($this->verser)();

        expect($this->sms)->toHaveCount(1);
    });

    it('part aussi sur exonération totale', function (): void {
        ($this->compteCree)();
        $r = app(RegistreVersements::class)->accorderRemise($this->inscription, 1185000, 'Exonération', $this->responsable);

        expect($r->sms)->toBeTrue()->and($this->sms)->toHaveCount(1);
    });

    it('ne fait pas échouer le versement si la passerelle tombe, et peut être renvoyé', function (): void {
        ($this->compteCree)();
        $this->smsEnPanne = true;
        // Sans e-mail : aucun canal ne passe (l'e-mail de secours est couvert par LienLettreAccesTest).
        $this->dossier->forceFill(['email' => null])->save();

        $r = ($this->verser)();
        expect($r->sms)->toBeFalse()
            ->and($r->versement->exists)->toBeTrue()
            ->and($this->inscription->fresh()->acces_notifie_at)->toBeNull();

        $this->smsEnPanne = false;
        expect(app(NotificationAcces::class)->siDisponible($this->dossier->fresh()))->toBeTrue()
            ->and($this->inscription->fresh()->acces_notifie_at)->not->toBeNull()
            ->and($this->sms)->toHaveCount(1);
    });

    it('attend la création du compte eCampus quand le solde arrive avant', function (): void {
        config()->set('pssfp.ecampus.jeton', 'jeton');
        config()->set('pssfp.foad_url', 'https://ecampus.test');
        $r = ($this->verser)();
        expect($r->sms)->toBeNull()->and($this->sms)->toBeEmpty();

        Http::fake(['ecampus.test/*' => Http::sequence()
            ->push([['username' => mb_strtolower((string) $this->dossier->matricule), 'email' => 'awa@example.org', 'lastname' => mb_strtoupper((string) $this->dossier->nom), 'firstname' => (string) $this->dossier->prenom]])]);
        app(ComptesEcampus::class)->traiter($this->dossier->fresh()->load('campagne'), $this->admin);

        expect($this->dossier->fresh()->ecampus_statut)->toBe(ComptesEcampus::EXISTANT)
            ->and($this->sms)->toHaveCount(1);
    });
});
