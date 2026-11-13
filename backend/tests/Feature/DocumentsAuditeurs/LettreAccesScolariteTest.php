<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ListAuditeurs;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentsAuditeurs\DocumentsAuditeurs;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\AttributionMatricules;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('filament', 'documents-auditeurs');

/*
 * Demande du 13/11/2026 : la scolarité télécharge, depuis la fiche, la lettre
 * d'accès FOAD (codes eCampus) d'un auditeur dont l'inscription est soldée,
 * en PDF seulement (pas de Word modifiable sur la fiche).
 * Un auditeur à la fois : les téléchargements groupés et par promotion
 * restent à l'administration.
 */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['date_lettres' => '2026-11-04'],
    ]);
    $retenu = fn (string $nom): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE,
        'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => Str::random(8).'@example.org',
    ]);
    $this->paye = $retenu('Abega');
    $this->impaye = $retenu('Biya');
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $admin);
    $this->paye->refresh();
    $this->impaye->refresh();
    solderInscription($this->paye);

    $this->agent = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
});

it('laisse la scolarité télécharger la lettre d\'accès d\'un auditeur payé, depuis sa fiche', function (string $role): void {
    $this->actingAs($agent = ($this->agent)($role));

    $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->paye)->getRouteKey()])
        ->assertActionVisible('lettreAccesFoad')
        ->callAction('lettreAccesFoad')
        ->assertFileDownloaded('acces-foad-'.mb_strtolower((string) $this->paye->matricule).'.pdf');

    $trace = Activity::query()->where('event', 'acces_foad_exporte')->sole();
    expect($trace->subject_id)->toBe($this->paye->id)
        ->and($trace->causer_id)->toBe($agent->id);
})->with(['scolarite_agent', 'scolarite_responsable']);

it('ne propose sur la fiche que le PDF, à l\'administration aussi', function (): void {
    $this->actingAs(($this->agent)('admin'));

    $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->paye)->getRouteKey()])
        ->assertActionHasLabel('lettreAccesFoad', 'Lettre d\'accès FOAD (PDF)')
        ->callAction('lettreAccesFoad')
        ->assertFileDownloaded('acces-foad-'.mb_strtolower((string) $this->paye->matricule).'.pdf');
});

it('la cache tant que l\'inscription n\'est pas soldée', function (): void {
    $this->actingAs(($this->agent)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->impaye)->getRouteKey()])
        ->assertActionHidden('lettreAccesFoad');
});

it('refuse à la scolarité les lettres d\'accès en groupe ou par promotion', function (): void {
    $agent = ($this->agent)('scolarite_agent');
    $this->actingAs($agent);

    $this->livewire(ListAuditeurs::class)->assertTableBulkActionHidden('accesFoadBulk');
    expect(fn () => app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, $agent, [$this->paye->id, $this->impaye->id]))
        ->toThrow(HttpException::class);
    expect(fn () => app(DocumentsAuditeurs::class)->generer($this->campagne, ParametresLettres::DOCUMENT_ACCES, $agent))
        ->toThrow(HttpException::class);
});

it('ne donne pas pour autant les autres droits sur les codes', function (): void {
    $this->actingAs(($this->agent)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => ficheDe($this->paye)->getRouteKey()])
        ->assertActionHidden('identifiantsMoodle')
        ->assertActionHidden('envoyerIdentifiantsMoodle');
});

it('donne la permission aux deux rôles de scolarité sur une base neuve', function (): void {
    expect(Role::findByName('scolarite_agent', 'web')->hasPermissionTo('scolarite.lettre_acces'))->toBeTrue()
        ->and(Role::findByName('scolarite_responsable', 'web')->hasPermissionTo('scolarite.lettre_acces'))->toBeTrue()
        ->and(Role::findByName('admission_committee', 'web')->hasPermissionTo('scolarite.lettre_acces'))->toBeFalse();
});
