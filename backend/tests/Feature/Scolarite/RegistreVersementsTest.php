<?php

declare(strict_types=1);

use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $agent = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->agent = $agent('scolarite_agent');
    $this->responsable = $agent('scolarite_responsable');
    $this->admin = $agent('admin');

    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699000001', 'email' => 'awa@example.org',
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->dossier->refresh();
    $this->dossier->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
    $this->inscription = $this->dossier->auditeur->inscriptionCourante;

    $this->sms = [];
    $this->smsEnPanne = false;
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            if ($this->smsEnPanne) {
                throw new RuntimeException('passerelle '.$texte);
            }
            $this->sms[] = [$tel, $texte];
        });
    });
    $this->registre = fn (): RegistreVersements => app(RegistreVersements::class);
    $this->saisie = fn (array $champs = []): array => $champs + [
        'montant' => 185000, 'date_versement' => '2026-11-02', 'mode' => 'cremincam_agence',
        'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ];
});

it('ouvre un auditeur et son inscription M1 à l\'attribution du matricule', function (): void {
    $i = $this->inscription;

    expect($i)->toBeInstanceOf(InscriptionAnnuelle::class)
        ->and($i->niveau)->toBe('M1')
        ->and($i->annee_academique)->toBe('2026-2027')
        ->and($i->montant_inscription)->toBe(185000)
        ->and($i->montant_tranche2)->toBe(500000);
});

it('attribue le matricule sans inscription quand le barème de l\'année n\'existe pas', function (): void {
    $sans = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2031-2032'], 'promotion_numero' => 19]);
    $autre = Candidature::factory()->forCampagne($sans)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699000002', 'email' => 'bruno@example.org',
    ]);

    app(AttributionMatricules::class)->attribuer($sans, '31', $this->admin);

    $autre->refresh();
    expect($autre->matricule)->toStartWith('31')
        ->and($autre->auditeur)->not->toBeNull()
        ->and($autre->auditeur->inscriptionCourante)->toBeNull();

    // Le barème saisi ensuite, l'inscription se crée depuis la fiche.
    BaremeScolarite::factory()->create(['annee_academique' => '2031-2032', 'niveau' => 'M1']);
    $creee = app(OuvertureAuditeur::class)->creerInscription($autre->auditeur);
    expect($creee?->annee_academique)->toBe('2031-2032');
});

it('n\'ouvre pas deux fois le même auditeur', function (): void {
    $auditeur = app(OuvertureAuditeur::class)->ouvrir($this->dossier);

    expect($auditeur->id)->toBe($this->dossier->auditeur->id)
        ->and(InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->count())->toBe(1);
});

it('enregistre un versement libre et met la situation à jour aussitôt', function (): void {
    $r = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['montant' => 100000, 'reference' => ' bor-12 ']), $this->agent);

    expect($r->versement->reference)->toBe('BOR-12')
        ->and($r->versement->saisi_par)->toBe($this->agent->id)
        ->and($this->inscription->fresh()->situation()->verse)->toBe(100000)
        ->and(Activity::query()->where('event', 'versement_enregistre')->count())->toBe(1);
});

it('refuse une référence déjà saisie, avec un message clair', function (): void {
    ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['reference' => 'BOR-1']), $this->agent);

    expect(fn () => ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['reference' => 'bor-1 ']), $this->agent))
        ->toThrow(InvalidArgumentException::class, 'déjà été saisie');
});

it('accepte de nouveau la référence d\'un versement annulé', function (): void {
    $r = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['reference' => 'BOR-2']), $this->agent);
    ($this->registre)()->annuler($r->versement, 'Erreur de montant', $this->responsable);

    $nouveau = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['reference' => 'BOR-2', 'montant' => 90000]), $this->agent);

    expect($nouveau->versement->montant)->toBe(90000);
});

it('refuse les saisies invalides', function (array $champs, string $message): void {
    expect(fn () => ($this->registre)()->enregistrer($this->inscription, ($this->saisie)($champs), $this->agent))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'montant nul' => [['montant' => 0], 'montant'],
    'montant négatif' => [['montant' => -5], 'montant'],
    'date future' => [['date_versement' => '2099-01-01'], 'futur'],
    'mode réservé' => [['mode' => 'reprise'], 'mode'],
    'mode inconnu' => [['mode' => 'cheque-en-bois'], 'mode'],
    'payeur inconnu' => [['payeur' => 'lune'], 'payeur'],
    'référence vide' => [['reference' => '   '], 'référence'],
]);

it('refuse un versement sur un dossier retiré, sans effacer l\'historique', function (): void {
    ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(), $this->agent);
    $this->dossier->forceFill(['withdrawn_at' => now()])->save();

    expect(fn () => ($this->registre)()->enregistrer($this->inscription->fresh(), ($this->saisie)(), $this->agent))
        ->toThrow(InvalidArgumentException::class, 'retiré')
        ->and($this->inscription->fresh()->situation()->verse)->toBe(185000);
});

it('applique les droits sur chaque opération', function (): void {
    $sansDroit = tap(User::factory()->create(['email' => 'caisse-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('caisse'));
    $v = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(), $this->agent)->versement;

    expect(fn () => ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(), $sansDroit))->toThrow(HttpException::class)
        ->and(fn () => ($this->registre)()->annuler($v, 'motif', $this->agent))->toThrow(HttpException::class)
        ->and(fn () => ($this->registre)()->accorderRemise($this->inscription, 1000, 'motif', $this->agent))->toThrow(HttpException::class);
});

it('garde la ligne annulée, avec son motif, hors du cumul', function (): void {
    $v = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(), $this->agent)->versement;

    ($this->registre)()->annuler($v, 'Doublon banque', $this->responsable);

    $v->refresh();
    expect($v->annule_motif)->toBe('Doublon banque')
        ->and($v->annule_par)->toBe($this->responsable->id)
        ->and(Versement::query()->count())->toBe(1)
        ->and($this->inscription->fresh()->situation()->verse)->toBe(0)
        ->and(fn () => ($this->registre)()->annuler($v, 'Encore', $this->responsable))->toThrow(InvalidArgumentException::class);
});

it('exige un motif pour annuler et pour accorder une remise', function (): void {
    $v = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(), $this->agent)->versement;

    expect(fn () => ($this->registre)()->annuler($v, '  ', $this->responsable))->toThrow(InvalidArgumentException::class, 'motif')
        ->and(fn () => ($this->registre)()->accorderRemise($this->inscription, 1000, ' ', $this->responsable))->toThrow(InvalidArgumentException::class, 'motif');
});

it('accorde une remise plafonnée au total, tracée à chaque changement', function (): void {
    ($this->registre)()->accorderRemise($this->inscription, 200000, 'Bourse', $this->responsable);
    $i = $this->inscription->fresh();

    expect($i->situation()->duTotal)->toBe(985000)
        ->and($i->remise_motif)->toBe('Bourse')
        ->and($i->remise_par)->toBe($this->responsable->id)
        ->and(fn () => ($this->registre)()->accorderRemise($i, 1185001, 'Trop', $this->responsable))->toThrow(InvalidArgumentException::class, 'dépasse');

    ($this->registre)()->accorderRemise($i, 0, 'Bourse retirée', $this->responsable);
    expect(Activity::query()->where('event', 'remise_accordee')->count())->toBe(2)
        ->and($i->fresh()->situation()->duTotal)->toBe(1185000);
});

it('annonce qu\'une annulation ou une baisse de remise rebloquerait le code', function (): void {
    $v1 = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['montant' => 185000]), $this->agent)->versement;
    $v2 = ($this->registre)()->enregistrer($this->inscription, ($this->saisie)(['montant' => 50000]), $this->agent)->versement;

    expect(RegistreVersements::annulationRebloque($v2))->toBeFalse()
        ->and(RegistreVersements::annulationRebloque($v1))->toBeTrue();

    $exo = InscriptionAnnuelle::factory()->create(['remise_montant' => 1185000]);
    expect(RegistreVersements::remiseRebloque($exo, 1185000))->toBeFalse()
        ->and(RegistreVersements::remiseRebloque($exo, 0))->toBeTrue();
});
