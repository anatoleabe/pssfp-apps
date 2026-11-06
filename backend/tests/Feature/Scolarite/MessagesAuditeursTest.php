<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Models\Versement;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\NotificationCandidatsService;
use App\Services\Scolarite\MessagesAuditeurs;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2026-12-15']);
    $retenu = fn (string $prenom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'prenom' => $prenom, 'nom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
    ]);
    $this->awa = $retenu('Awa', '+237699004001');
    $this->bruno = $retenu('Bruno', '+237699004002');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', ($this->compte)('admin'));
    $this->awa->refresh();
    $this->bruno->refresh();
    Versement::factory()->create(['inscription_annuelle_id' => $this->awa->auditeur->inscriptionCourante->id, 'montant' => 285000]);

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[$tel] = $texte;
        });
    });
    Mail::fake();
    $this->modele = 'PSSFP : {prenom}, il reste {reste} FCFA ({reste_inscription} pour l\'inscription), avant le {prochaine_echeance}. Matricule {matricule}.';
});

it('remplace les variables propres à chaque auditeur', function (): void {
    $texte = MessagesAuditeurs::rendre($this->modele, $this->awa->auditeur);

    expect($texte)->toBe('PSSFP : Awa, il reste 900 000 FCFA (0 pour l\'inscription), avant le 15/12/2026. Matricule '.$this->awa->matricule.'.');
    expect(MessagesAuditeurs::rendre($this->modele, $this->bruno->auditeur))
        ->toContain('il reste 1 185 000 FCFA (185 000 pour')
        ->toContain('avant le dès que possible');
});

it('envoie à chacun son texte, et trace l\'envoi dans le journal des messages', function (): void {
    $rapport = app(MessagesAuditeurs::class)->envoyer(
        collect([$this->awa->auditeur, $this->bruno->auditeur]), NotificationCandidatsService::CANAL_SMS, $this->modele, null, ($this->compte)('scolarite_responsable'),
    );

    expect($rapport['sms_envoyes'])->toBe(2)
        ->and($this->sms['+237699004001'])->toContain('Awa, il reste 900 000')
        ->and($this->sms['+237699004002'])->toContain('Bruno, il reste 1 185 000')
        ->and(CandidatureRelance::query()->count())->toBe(2)
        ->and(CandidatureRelance::query()->where('candidature_id', $this->awa->id)->value('message'))->toContain('900 000');
});

it('plafonne le nombre de destinataires', function (): void {
    $trop = collect(array_fill(0, MessagesAuditeurs::MAX + 1, $this->awa->auditeur));

    expect(fn () => app(MessagesAuditeurs::class)->envoyer($trop, NotificationCandidatsService::CANAL_SMS, 'x', null, ($this->compte)('scolarite_responsable')))
        ->toThrow(InvalidArgumentException::class, (string) MessagesAuditeurs::MAX);
    expect($this->sms)->toBeEmpty();
});

it('refuse un message vide ou une variable de mot de passe', function (string $corps): void {
    expect(fn () => app(MessagesAuditeurs::class)->envoyer(collect([$this->awa->auditeur]), NotificationCandidatsService::CANAL_SMS, $corps, null, ($this->compte)('scolarite_responsable')))
        ->toThrow(InvalidArgumentException::class);
})->with(['   ', 'Votre code : {mot_de_passe}', 'Votre code : {password}']);

it('réserve les messages au responsable et à l\'administration', function (): void {
    expect(fn () => app(MessagesAuditeurs::class)->envoyer(collect([$this->awa->auditeur]), NotificationCandidatsService::CANAL_SMS, 'Bonjour', null, ($this->compte)('scolarite_agent')))
        ->toThrow(HttpException::class);

    $this->actingAs(($this->compte)('scolarite_agent'));
    $this->livewire(ListAuditeurs::class)->assertTableBulkActionHidden('messageAuditeurs');
});

it('montre dans l\'aperçu le texte rendu pour la sélection', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ListAuditeurs::class)
        ->mountTableBulkAction('messageAuditeurs', [$this->bruno->auditeur])
        ->setTableBulkActionData(['canal' => NotificationCandidatsService::CANAL_SMS, 'corps' => 'Reste {reste} FCFA'])
        ->assertSee('1 destinataire(s)')
        ->assertSee('SMS au total')
        ->assertSee('Reste 1 185 000 FCFA');
});

it('envoie depuis la liste des auditeurs', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ListAuditeurs::class)
        ->callTableBulkAction('messageAuditeurs', [$this->awa->auditeur, $this->bruno->auditeur], data: [
            'canal' => NotificationCandidatsService::CANAL_SMS, 'corps' => 'PSSFP : {prenom}, reste {reste} FCFA.',
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified();

    expect($this->sms)->toHaveCount(2)
        ->and($this->sms['+237699004002'])->toBe('PSSFP : Bruno, reste 1 185 000 FCFA.');
});

it('écarte les dossiers retirés, exclus ou en abandon', function (): void {
    $this->bruno->forceFill(['withdrawn_at' => now()])->save();
    $this->awa->auditeur->forceFill(['statut' => 'abandon'])->save();

    $rapport = app(MessagesAuditeurs::class)->envoyer(
        collect([$this->awa->auditeur->fresh(), $this->bruno->auditeur->fresh()]), NotificationCandidatsService::CANAL_SMS, 'Bonjour {prenom}', null, ($this->compte)('scolarite_responsable'),
    );

    expect($rapport['ignores'])->toBe(2)->and($this->sms)->toBeEmpty();
});

it('limite le nombre d\'envois par heure et par agent', function (): void {
    $resp = ($this->compte)('scolarite_responsable');
    foreach (range(1, MessagesAuditeurs::ENVOIS_PAR_HEURE) as $_) {
        app(MessagesAuditeurs::class)->envoyer(collect([$this->awa->auditeur]), NotificationCandidatsService::CANAL_SMS, 'Bonjour', null, $resp);
    }

    expect(fn () => app(MessagesAuditeurs::class)->envoyer(collect([$this->awa->auditeur]), NotificationCandidatsService::CANAL_SMS, 'Bonjour', null, $resp))
        ->toThrow(InvalidArgumentException::class, 'Trop d');
});

it('ne réinterprète pas les variables des candidats dans un texte déjà rendu', function (): void {
    $this->awa->forceFill(['prenom' => '{numero_dossier}'])->save();

    app(MessagesAuditeurs::class)->envoyer(collect([$this->awa->fresh()->auditeur]), NotificationCandidatsService::CANAL_SMS, 'Bonjour {prenom}', null, ($this->compte)('scolarite_responsable'));

    expect($this->sms['+237699004001'])->toBe('Bonjour {numero_dossier}');
});
