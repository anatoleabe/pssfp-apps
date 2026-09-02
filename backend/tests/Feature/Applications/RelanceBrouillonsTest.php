<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Services\CandidatureService;
use App\Services\RelanceCandidatureService;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

uses()->group('applications', 'relance');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-test',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    // Passerelle SMS espionnée : aucun appel réseau ne doit partir des tests.
    $this->smsEnvoyes = collect();
    $this->smsSpy = new class($this->smsEnvoyes) implements SmsServiceInterface
    {
        public bool $doitEchouer = false;

        public function __construct(private $journal) {}

        public function send(string $phoneE164, string $message): void
        {
            if ($this->doitEchouer) {
                throw new RuntimeException('passerelle indisponible');
            }
            $this->journal->push(['phone' => $phoneE164, 'message' => $message]);
        }
    };
    $this->app->instance(SmsServiceInterface::class, $this->smsSpy);
});

function brouillonRelancable(int $campagneId, array $overrides = []): Candidature
{
    $candidature = Candidature::factory()->create(array_merge([
        'campagne_id' => $campagneId,
        'statut' => Candidature::STATUT_POSTULANT,
        'phone_e164' => '+2376'.fake()->numerify('98######'),
        'phone_country' => 'CM',
        'photo_path' => 'candidat-photos/test/photo.jpg',
        'civilite' => 'M.',
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'date_naissance' => '1990-06-15',
        'lieu_naissance' => 'Yaoundé',
        'genre' => 'M',
        'statut_matrimonial' => 'Célibataire',
        'nationalite' => 'CM',
        'pays_origine' => 'CM',
        'pays_residence' => 'CM',
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
        'adresse' => 'BP 1234 Yaoundé',
        'ville_residence' => 'Yaoundé',
        'indicatif1' => '+237',
        'telephone1' => '691234567',
        'email' => 'paul.ndongo@example.com',
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Finances publiques',
        'annee_diplome' => 2015,
        'statut_actuel' => 'Etudiant',
        'moyen_connaissance' => 'Site officiel du PSSFP',
        'engagement_nom' => 'Paul Ndongo',
    ], $overrides));

    DB::table('candidatures')->where('id', $candidature->id)->update(['form_version' => 1]);

    return $candidature->refresh();
}

it('n’envoie aucun SMS en mode simulation', function (): void {
    brouillonRelancable($this->campagne->id);
    brouillonRelancable($this->campagne->id, ['photo_path' => null]);

    $rapport = app(RelanceCandidatureService::class)
        ->relancer($this->campagne, RelanceCandidatureService::CAUSES, false);

    expect($this->smsEnvoyes)->toHaveCount(0)
        ->and($rapport['envoyes'])->toBe(0)
        ->and($rapport['details'])->toHaveCount(2)
        ->and(CandidatureRelance::count())->toBe(0);
});

it('envoie un SMS par candidat en mode réel et trace l’envoi', function (): void {
    $pret = brouillonRelancable($this->campagne->id);

    $rapport = app(RelanceCandidatureService::class)
        ->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    expect($rapport['envoyes'])->toBe(1)
        ->and($this->smsEnvoyes)->toHaveCount(1)
        ->and($this->smsEnvoyes->first()['phone'])->toBe($pret->phone_e164)
        ->and(CandidatureRelance::where('candidature_id', $pret->id)->first()->statut)
        ->toBe(CandidatureRelance::STATUT_ENVOYE);
});

it('ne relance jamais deux fois le même candidat pour la même cause', function (): void {
    brouillonRelancable($this->campagne->id);
    $service = app(RelanceCandidatureService::class);

    $premier = $service->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);
    $second = $service->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    expect($premier['envoyes'])->toBe(1)
        ->and($second['envoyes'])->toBe(0)
        ->and($second['ignores_deja_relances'])->toBe(1)
        ->and($this->smsEnvoyes)->toHaveCount(1);
});

it('autorise une nouvelle tentative après un échec', function (): void {
    brouillonRelancable($this->campagne->id);
    $service = app(RelanceCandidatureService::class);

    $this->smsSpy->doitEchouer = true;
    $echec = $service->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    $this->smsSpy->doitEchouer = false;
    $reprise = $service->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    expect($echec['echecs'])->toBe(1)
        ->and($reprise['envoyes'])->toBe(1)
        ->and($this->smsEnvoyes)->toHaveCount(1);
});

it('envoie un message différent selon la cause du blocage', function (): void {
    brouillonRelancable($this->campagne->id);
    brouillonRelancable($this->campagne->id, ['photo_path' => null]);

    app(RelanceCandidatureService::class)
        ->relancer($this->campagne, RelanceCandidatureService::CAUSES, true);

    $messages = $this->smsEnvoyes->pluck('message');

    expect($messages)->toHaveCount(2)
        ->and($messages->filter(fn (string $m): bool => str_contains($m, 'pas encore soumis')))->toHaveCount(1)
        ->and($messages->filter(fn (string $m): bool => str_contains($m, 'photo')))->toHaveCount(1);
});

it('interpole la date de clôture réelle de la campagne', function (): void {
    brouillonRelancable($this->campagne->id);

    app(RelanceCandidatureService::class)
        ->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    expect($this->smsEnvoyes->first()['message'])
        ->toContain($this->campagne->closes_at->format('d/m/Y'));
});

it('garde chaque message sous la limite d’un SMS', function (): void {
    brouillonRelancable($this->campagne->id);
    brouillonRelancable($this->campagne->id, ['photo_path' => null]);

    app(RelanceCandidatureService::class)
        ->relancer($this->campagne, RelanceCandidatureService::CAUSES, true);

    foreach ($this->smsEnvoyes as $sms) {
        expect(mb_strlen($sms['message']))->toBeLessThanOrEqual(160);
    }
});

it('ignore un dossier sans numéro exploitable plutôt que de le mettre en échec', function (): void {
    // Donnée héritée mal formée : indicatif sans « + », donc non E.164 une
    // fois recomposé. Les champs restent remplis, le dossier reste « prêt ».
    brouillonRelancable($this->campagne->id, [
        'phone_e164' => '',
        'indicatif1' => '237',
        'telephone1' => '691234567',
    ]);

    $rapport = app(RelanceCandidatureService::class)
        ->relancer($this->campagne, [CandidatureService::DRAFT_READY], true);

    expect($rapport['ignores_sans_numero'])->toBe(1)
        ->and($rapport['envoyes'])->toBe(0)
        ->and($rapport['echecs'])->toBe(0)
        ->and(CandidatureRelance::count())->toBe(0);
});

it('ne relance pas les dossiers déjà soumis', function (): void {
    $soumis = brouillonRelancable($this->campagne->id);
    $soumis->update(['statut' => Candidature::STATUT_CANDIDAT, 'submitted_at' => now()]);

    $rapport = app(RelanceCandidatureService::class)
        ->relancer($this->campagne, RelanceCandidatureService::CAUSES, true);

    expect($rapport['envoyes'])->toBe(0)
        ->and($this->smsEnvoyes)->toHaveCount(0);
});

it('respecte la limite demandée', function (): void {
    brouillonRelancable($this->campagne->id);
    brouillonRelancable($this->campagne->id);
    brouillonRelancable($this->campagne->id);

    $rapport = app(RelanceCandidatureService::class)
        ->relancer($this->campagne, [CandidatureService::DRAFT_READY], true, 2);

    expect($rapport['envoyes'])->toBe(2)
        ->and($this->smsEnvoyes)->toHaveCount(2);
});

it('la commande simule par défaut, sans --envoyer', function (): void {
    brouillonRelancable($this->campagne->id);

    $this->artisan('candidatures:relancer-brouillons')
        ->expectsOutputToContain('MODE SIMULATION')
        ->assertSuccessful();

    expect($this->smsEnvoyes)->toHaveCount(0)
        ->and(CandidatureRelance::count())->toBe(0);
});

it('la commande envoie réellement avec --envoyer', function (): void {
    brouillonRelancable($this->campagne->id);

    $this->artisan('candidatures:relancer-brouillons --envoyer --campagne=p14-test')
        ->expectsConfirmation('Confirmer l\'envoi ?', 'yes')
        ->assertSuccessful();

    expect($this->smsEnvoyes)->toHaveCount(1);
});

it('la commande refuse une cause inconnue', function (): void {
    $this->artisan('candidatures:relancer-brouillons --cause=nimportequoi')
        ->assertFailed();

    expect($this->smsEnvoyes)->toHaveCount(0);
});

it('la commande échoue proprement si aucune campagne ne correspond', function (): void {
    $this->artisan('candidatures:relancer-brouillons --campagne=inexistante')
        ->assertFailed();
});

it('n’expose jamais le numéro complet dans la sortie de la commande', function (): void {
    $draft = brouillonRelancable($this->campagne->id);

    $this->artisan('candidatures:relancer-brouillons --campagne=p14-test')
        ->doesntExpectOutputToContain($draft->phone_e164)
        ->assertSuccessful();
});

it('n’envoie rien si la confirmation interactive est refusée', function (): void {
    brouillonRelancable($this->campagne->id);

    $this->artisan('candidatures:relancer-brouillons --envoyer --campagne=p14-test')
        ->expectsConfirmation('Confirmer l\'envoi ?', 'no')
        ->assertSuccessful();

    expect($this->smsEnvoyes)->toHaveCount(0)
        ->and(CandidatureRelance::count())->toBe(0);
});
