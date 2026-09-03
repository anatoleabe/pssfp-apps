<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Mail\NotificationCandidatMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Services\NotificationCandidatsService;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;

uses()->group('applications', 'notification');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('services.sms.provider', 'echosms');

    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

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

    Mail::fake();
});

function candidatANotifier(int $campagneId, array $overrides = []): Candidature
{
    return Candidature::factory()->create(array_merge([
        'campagne_id' => $campagneId,
        'statut' => Candidature::STATUT_POSTULANT,
        'phone_e164' => '+2376'.fake()->numerify('98######'),
        'phone_country' => 'CM',
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'email' => 'paul'.fake()->numerify('###').'@example.com',
        'specialite' => array_values((array) config('specialites'))[0],
    ], $overrides));
}

function adminNotificateur(): User
{
    $u = User::factory()->create();
    $u->assignRole('admin');

    return $u;
}

it('envoie un SMS à chaque candidat sélectionné', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $b = candidatANotifier($this->campagne->id);

    $rapport = app(NotificationCandidatsService::class)->envoyer(
        collect([$a, $b]),
        NotificationCandidatsService::CANAL_SMS,
        'PSSFP : message de test.',
        null,
        adminNotificateur(),
    );

    expect($rapport['sms_envoyes'])->toBe(2)
        ->and($this->smsEnvoyes)->toHaveCount(2)
        ->and($rapport['emails_envoyes'])->toBe(0);
});

it('envoie un e-mail quand le canal le demande', function (): void {
    $a = candidatANotifier($this->campagne->id);

    $rapport = app(NotificationCandidatsService::class)->envoyer(
        collect([$a]),
        NotificationCandidatsService::CANAL_EMAIL,
        'Corps du message.',
        'Objet de test',
        adminNotificateur(),
    );

    expect($rapport['emails_envoyes'])->toBe(1)
        ->and($this->smsEnvoyes)->toHaveCount(0);

    Mail::assertQueued(NotificationCandidatMail::class, fn ($m): bool => $m->sujetMessage === 'Objet de test');
});

it('envoie sur les deux canaux quand demandé', function (): void {
    $a = candidatANotifier($this->campagne->id);

    $rapport = app(NotificationCandidatsService::class)->envoyer(
        collect([$a]),
        NotificationCandidatsService::CANAL_LES_DEUX,
        'Message.',
        'Objet',
        adminNotificateur(),
    );

    expect($rapport['sms_envoyes'])->toBe(1)
        ->and($rapport['emails_envoyes'])->toBe(1);
});

it('remplace les variables par les données du candidat', function (): void {
    $a = candidatANotifier($this->campagne->id, ['prenom' => 'Awa', 'nom' => 'Mbala']);

    app(NotificationCandidatsService::class)->envoyer(
        collect([$a]),
        NotificationCandidatsService::CANAL_SMS,
        'Bonjour {prenom} {nom}, dossier {numero_dossier}, cloture {date_cloture}.',
        null,
        adminNotificateur(),
    );

    $message = $this->smsEnvoyes->first()['message'];

    expect($message)->toContain('Awa')
        ->and($message)->toContain('Mbala')
        ->and($message)->toContain($a->numero_dossier)
        ->and($message)->toContain($this->campagne->closes_at->format('d/m/Y'))
        ->and($message)->not->toContain('{prenom}');
});

it('trace chaque envoi avec son texte et son auteur', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $admin = adminNotificateur();

    app(NotificationCandidatsService::class)->envoyer(
        collect([$a]),
        NotificationCandidatsService::CANAL_SMS,
        'Texte conserve pour audit.',
        null,
        $admin,
    );

    $trace = CandidatureRelance::where('candidature_id', $a->id)->first();

    expect($trace->cause)->toBe(CandidatureRelance::CAUSE_MANUELLE)
        ->and($trace->canal)->toBe(CandidatureRelance::CANAL_SMS)
        ->and($trace->message)->toBe('Texte conserve pour audit.')
        ->and($trace->envoye_par)->toBe($admin->id)
        ->and($trace->statut)->toBe(CandidatureRelance::STATUT_ENVOYE);
});

it('autorise plusieurs notifications manuelles au même candidat', function (): void {
    // Contrairement aux relances automatiques, l'anti-doublon ne s'applique
    // pas : un agent peut légitimement réécrire aux mêmes personnes.
    $a = candidatANotifier($this->campagne->id);
    $service = app(NotificationCandidatsService::class);
    $admin = adminNotificateur();

    $service->envoyer(collect([$a]), NotificationCandidatsService::CANAL_SMS, 'Premier.', null, $admin);
    $service->envoyer(collect([$a]), NotificationCandidatsService::CANAL_SMS, 'Second.', null, $admin);

    expect($this->smsEnvoyes)->toHaveCount(2)
        ->and(CandidatureRelance::where('candidature_id', $a->id)->count())->toBe(2);
});

it('isole un échec sans interrompre les autres envois', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $b = candidatANotifier($this->campagne->id);

    $this->smsSpy->doitEchouer = true;
    $rapport = app(NotificationCandidatsService::class)->envoyer(
        collect([$a, $b]),
        NotificationCandidatsService::CANAL_SMS,
        'Message.',
        null,
        adminNotificateur(),
    );

    expect($rapport['sms_echecs'])->toBe(2)
        ->and($rapport['sms_envoyes'])->toBe(0)
        ->and(CandidatureRelance::where('statut', CandidatureRelance::STATUT_ECHEC)->count())->toBe(2);
});

it('ignore un candidat sans aucune coordonnée exploitable', function (): void {
    $a = candidatANotifier($this->campagne->id, [
        'phone_e164' => '',
        'indicatif1' => null,
        'telephone1' => null,
        'email' => null,
    ]);

    $rapport = app(NotificationCandidatsService::class)->envoyer(
        collect([$a]),
        NotificationCandidatsService::CANAL_LES_DEUX,
        'Message.',
        'Objet',
        adminNotificateur(),
    );

    expect($rapport['ignores'])->toBe(1)
        ->and($rapport['sms_envoyes'])->toBe(0)
        ->and($rapport['emails_envoyes'])->toBe(0);
});

it('signale les numéros hors couverture SMS dans l’aperçu', function (): void {
    // Les 7 échecs de la relance du 3 septembre étaient tous hors Cameroun :
    // l'aperçu doit le dire AVANT l'envoi, pas après.
    candidatANotifier($this->campagne->id, ['phone_e164' => '+237698000001']);
    $tchad = candidatANotifier($this->campagne->id, ['phone_e164' => '+235660000002']);

    $apercu = app(NotificationCandidatsService::class)->apercu(
        collect([Candidature::find($tchad->id), ...Candidature::where('phone_country', 'CM')->get()->all()]),
        NotificationCandidatsService::CANAL_SMS,
        'Message.',
    );

    expect($apercu['sms_hors_couverture'])->toBeGreaterThanOrEqual(1);
});

it('calcule le coût en SMS et alerte au-delà de 160 caractères', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $long = str_repeat('a', 200);

    $court = app(NotificationCandidatsService::class)
        ->apercu(collect([$a]), NotificationCandidatsService::CANAL_SMS, 'Court.');
    $longApercu = app(NotificationCandidatsService::class)
        ->apercu(collect([$a]), NotificationCandidatsService::CANAL_SMS, $long);

    expect($court['sms_par_message'])->toBe(1)
        ->and($court['cout_sms'])->toBe(1)
        ->and($longApercu['sms_par_message'])->toBe(2)
        ->and($longApercu['cout_sms'])->toBe(2);
});

it('mesure la longueur sur le message rendu, variables comprises', function (): void {
    // Un gabarit court peut dépasser 160 caractères une fois le nom inséré.
    $a = candidatANotifier($this->campagne->id, ['prenom' => str_repeat('A', 100)]);

    $apercu = app(NotificationCandidatsService::class)->apercu(
        collect([$a]),
        NotificationCandidatsService::CANAL_SMS,
        'Bonjour {prenom}, '.str_repeat('b', 70),
    );

    expect($apercu['sms_par_message'])->toBe(2);
});

it('n’expose le bouton qu’aux rôles autorisés', function (): void {
    foreach (['admin', 'super_admin'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        expect($u->can('candidature.notify'))->toBeTrue("{$role} devrait pouvoir notifier");
    }

    foreach (['admission_committee', 'editor', 'receptionniste', 'librarian'] as $role) {
        $u = User::factory()->create();
        $u->assignRole($role);
        expect($u->can('candidature.notify'))->toBeFalse("{$role} ne devrait pas pouvoir notifier");
    }
});

it('envoie depuis la liste admin via l’action groupée', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $b = candidatANotifier($this->campagne->id);

    $this->actingAs(adminNotificateur());

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('notifierCandidats', [$a, $b], data: [
            'canal' => NotificationCandidatsService::CANAL_SMS,
            'corps' => 'PSSFP : message groupe de test.',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect($this->smsEnvoyes)->toHaveCount(2);
});

it('exige un message non vide', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $this->actingAs(adminNotificateur());

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('notifierCandidats', [$a], data: [
            'canal' => NotificationCandidatsService::CANAL_SMS,
            'corps' => '',
        ])
        ->assertHasTableBulkActionErrors(['corps']);

    expect($this->smsEnvoyes)->toHaveCount(0);
});

it('exige un objet quand l’e-mail est demandé', function (): void {
    $a = candidatANotifier($this->campagne->id);
    $this->actingAs(adminNotificateur());

    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('notifierCandidats', [$a], data: [
            'canal' => NotificationCandidatsService::CANAL_EMAIL,
            'corps' => 'Un message.',
            'sujet' => '',
        ])
        ->assertHasTableBulkActionErrors(['sujet']);
});
