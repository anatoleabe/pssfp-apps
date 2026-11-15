<?php

declare(strict_types=1);

use App\Filament\Resources\ArchiveAcmaResource;
use App\Filament\Resources\ArchiveAcmaResource\Pages\ListArchivesAcma;
use App\Filament\Resources\ArchiveAcmaResource\Pages\ViewArchiveAcma;
use App\Filament\Resources\ArchiveAcmaResource\RelationManagers\PaiementsRelationManager;
use App\Filament\Resources\ArchiveAcmaResource\RelationManagers\ResultatsRelationManager;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\AcmaComplement;
use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\AcmaResultatUe;
use App\Models\Auditeur;
use App\Models\Role;
use App\Models\User;
use App\Services\Acma\ArchivesAcma;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Support\Journaux;
use App\Support\ModulePermissionMap;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Acma\TransitAcma;

uses()->group('filament', 'acma');

/* Écran « Archives ACMA » (lot 15d) : consultation, compléments, arbitrage, historique sur la fiche de l'auditeur. Jeu fictif. */

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Storage::fake('local');
    Notification::fake();
    Queue::fake();
    Http::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $plateforme = function (string $matricule, string $nom, string $prenom, string $naissance): Auditeur {
        $auditeur = Auditeur::factory()->create();
        $auditeur->candidature->forceFill(['matricule' => $matricule, 'nom' => $nom, 'prenom' => $prenom, 'epouse' => null, 'date_naissance' => $naissance])->save();

        return $auditeur;
    };
    $this->lie = $plateforme('90EFP001', 'ALPHA', 'Béta', '1985-03-12');
    $this->autre = $plateforme('90EFP011', 'ESSAI', 'Epsilon', '1983-03-03');
    TransitAcma::construire();
    app(RepriseAcma::class)->executer(($this->compte)('admin'), null, true);
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->agent = ($this->compte)('scolarite_agent');
    $this->dossier = fn (string $m): AcmaDossier => AcmaDossier::query()->where('matricule', $m)->firstOrFail();
    $this->fiche = fn (string $m) => $this->livewire(ViewArchiveAcma::class, ['record' => ($this->dossier)($m)->getRouteKey()]);
});

it('ouvre les archives à la scolarité et à l\'administration, et à personne d\'autre', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $reponse = $this->get(ArchiveAcmaResource::getUrl('index'));
    $ouvert ? $reponse->assertOk()->assertSee('Archives ACMA') : $reponse->assertForbidden();
    $fiche = $this->get(ArchiveAcmaResource::getUrl('view', ['record' => ($this->dossier)('90EFP001')]));
    $ouvert ? $fiche->assertOk() : $fiche->assertForbidden();
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['super_admin', true],
    ['service_financier', false],
    ['admission_committee', false],
    ['librarian', false],
]);

it('cherche un ancien auditeur par matricule ou par nom, et filtre les cas à traiter', function (): void {
    $this->actingAs($this->agent);

    $this->livewire(ListArchivesAcma::class)
        ->searchTable('90EFP008')->assertCanSeeTableRecords([($this->dossier)('90EFP008')])->assertCanNotSeeTableRecords([($this->dossier)('90EFP001')])
        ->searchTable('delta')->assertCanSeeTableRecords([($this->dossier)('90EFP008')])
        ->searchTable(null)
        ->filterTable('a_arbitrer')->assertCanSeeTableRecords([($this->dossier)('90EFP011')])->assertCanNotSeeTableRecords([($this->dossier)('90EFP001')])
        ->resetTableFilters()
        ->filterTable('lie')->assertCanSeeTableRecords([($this->dossier)('90EFP001')])->assertCanNotSeeTableRecords([($this->dossier)('90EFP008')])
        ->resetTableFilters()
        ->filterTable('decision_non_enregistree')->assertCanSeeTableRecords([($this->dossier)('90EFP008'), ($this->dossier)('99XX12')])
        ->assertCanNotSeeTableRecords([($this->dossier)('90EFP014')]);
});

it('montre la fiche : identité, années et décisions, paiements, soutenances, documents, rapprochement', function (): void {
    $this->actingAs($this->agent);

    ($this->fiche)('90EFP001')
        ->assertSee('ESSAI ALPHA BÉTA')->assertSee('12/03/1985')
        ->assertSee('2024-2025 · M1 (FPTC4)')->assertSee('Admis (enregistrée par ACMA)')->assertSee('13,50 (enregistrée par ACMA)')
        ->assertSee('12,75 (indicatif : recalcul selon ACMA)')->assertSee('60 / 60')
        ->assertSee('Non enregistrée (cohorte sans décision enregistrée dans ACMA)')
        ->assertSee('Écart selon ACMA (non vérifié)')->assertSee('ce n\'est pas un arriéré')
        ->assertSee('Très bien')->assertSee('Mémoire fictif')->assertSee('Certificat')->assertSee('Lié automatiquement')
        ->assertSee('Lien automatique : matricule, date de naissance et noms concordants')
        ->assertActionVisible('ficheAuditeur')->assertActionHidden('arbitrer')->assertActionHidden('transcrireDecision')->assertActionHidden('completerNaissance');
    ($this->fiche)('90EFP002')->assertSee('À vérifier (ACMA : 0000-00-00)');
    ($this->fiche)('90EFP016')->assertSee('vers 1975');

    $this->livewire(ResultatsRelationManager::class, ['ownerRecord' => ($this->dossier)('90EFP003'), 'pageClass' => ViewArchiveAcma::class])
        ->assertCanSeeTableRecords(AcmaResultatUe::query()->where('en_conflit', true)->get())->assertSee('En conflit')->assertTableActionHidden('retenir', AcmaResultatUe::query()->where('en_conflit', true)->first());
    $this->livewire(ResultatsRelationManager::class, ['ownerRecord' => ($this->dossier)('90EFP014'), 'pageClass' => ViewArchiveAcma::class])
        ->assertSee('Reconstituée')->assertSee('non rattachée');
    $this->livewire(PaiementsRelationManager::class, ['ownerRecord' => ($this->dossier)('90EFP001'), 'pageClass' => ViewArchiveAcma::class])
        ->assertSee('négatif (annulation appariée)')->assertSee('doublon exact');
});

it('complète la date de naissance d\'après le dossier papier : motif exigé, dernier complément en vigueur, historique visible', function (): void {
    $this->actingAs($this->responsable);

    ($this->fiche)('90EFP002')->callAction('completerNaissance', data: ['date' => '1990-05-05', 'reference' => 'Dossier papier 2025', 'motif' => 'court'])
        ->assertHasActionErrors(['motif']);
    ($this->fiche)('90EFP002')->callAction('completerNaissance', data: ['date' => '1990-05-05', 'reference' => 'Dossier papier 2025', 'motif' => 'Date relevée sur l\'acte de naissance du dossier'])
        ->assertHasNoActionErrors();
    $this->travel(1)->minutes();
    ($this->fiche)('90EFP002')->callAction('completerNaissance', data: ['date' => '1990-06-05', 'reference' => 'Dossier papier 2025 bis', 'motif' => 'Correction : le mois avait été mal relu'])
        ->assertHasNoActionErrors();

    expect(ArchivesAcma::dateNaissance(($this->dossier)('90EFP002')))->toStartWith('05/06/1990 — d\'après Dossier papier 2025 bis')
        ->and(AcmaComplement::query()->where('champ', 'date_naissance')->count())->toBe(2)
        ->and(($this->dossier)('90EFP002')->date_naissance)->toBeNull()
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'complement_ajoute')->count())->toBe(2);
    ($this->fiche)('90EFP002')->assertSee('Date relevée sur l&#039;acte de naissance du dossier', false)->assertSee('Correction : le mois avait été mal relu');

    expect(fn () => DB::transaction(fn () => DB::table('acma_complements')->update(['valeur' => '1990-01-01'])))->toThrow(QueryException::class, 'ajout seul')
        ->and(fn () => DB::transaction(fn () => DB::table('acma_complements')->delete()))->toThrow(QueryException::class, 'ajout seul');
    Notification::assertNothingSent();
});

it('transcrit la décision d\'un PV signé pour une année sans décision enregistrée', function (): void {
    $this->actingAs($this->responsable);
    $inscription = AcmaInscription::query()->where('cle_source', '90EFP008|2022-2023|FPTC4')->firstOrFail();

    ($this->fiche)('90EFP008')->callAction('transcrireDecision', data: [
        'inscription' => $inscription->uuid, 'decision' => 'admis', 'moyenne' => '13.7', 'reference' => 'PV n° 7/2023', 'motif' => 'PV de délibération signé, archives de la scolarité',
    ])->assertHasNoActionErrors();

    expect(ArchivesAcma::decision($inscription)['libelle'])->toStartWith('Admis — décision transcrite du PV n° 7/2023, le ')
        ->and(ArchivesAcma::decision($inscription)['libelle'])->toEndWith('par Agent scolarite_responsable')
        ->and(ArchivesAcma::moyenne($inscription))->toStartWith('13,70 — transcrite du PV n° 7/2023')
        ->and($inscription->fresh()->decision)->toBe('non_enregistree');
    ($this->fiche)('90EFP008')->assertSee('décision transcrite du PV n° 7/2023');

    $decidee = AcmaInscription::query()->where('cle_source', '90EFP001|2024-2025|FPTC4')->firstOrFail();
    expect(fn () => app(ArchivesAcma::class)->transcrireDecision($decidee, 'non_admis', null, 'PV n° 1', 'Essai sur une décision enregistrée', $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'enregistrée dans ACMA');
});

it('retient une note en conflit, une fois, par complément', function (): void {
    $this->actingAs($this->responsable);
    $conflit = AcmaResultatUe::query()->where('cle_source', 'n:1007')->firstOrFail();

    $this->livewire(ResultatsRelationManager::class, ['ownerRecord' => ($this->dossier)('90EFP003'), 'pageClass' => ViewArchiveAcma::class])
        ->callTableAction('retenir', $conflit, data: ['reference' => 'Relevé papier 2026', 'motif' => 'Note du relevé signé par le directeur'])
        ->assertHasNoTableActionErrors()
        ->assertTableActionHidden('retenir', $conflit)
        ->assertSee('Retenue');
    expect(ArchivesAcma::retenue($conflit))->toBeTrue()
        ->and(fn () => app(ArchivesAcma::class)->retenirNote(AcmaResultatUe::query()->where('cle_source', 'n:1001')->firstOrFail(), 'Réf', 'Note sans conflit', $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'pas en conflit');
});

it('arbitre le rapprochement : lier, ne pas lier, écarter, avec motif, et journalise', function (): void {
    $this->actingAs($this->responsable);

    ($this->fiche)('90EFP011')->callAction('arbitrer', data: ['decision' => 'lie', 'auditeur' => $this->autre->uuid, 'motif' => 'court'])->assertHasActionErrors(['motif']);
    ($this->fiche)('90EFP011')->callAction('arbitrer', data: ['decision' => 'lie', 'auditeur' => $this->autre->uuid, 'motif' => 'Date de naissance mal saisie dans ACMA, acte vu'])
        ->assertHasNoActionErrors();
    expect(($this->dossier)('90EFP011'))->statut_rapprochement->toBe('lie_arbitre')->auditeur_id->toBe($this->autre->id)
        ->and(DB::table('acma_rapprochements')->where('decide_par', $this->responsable->id)->first())->decision->toBe('lie')->cas->toBe('R4');

    ($this->fiche)('90EFP012')->callAction('arbitrer', data: ['decision' => 'ecarte', 'motif' => 'Fiche de test créée par erreur dans ACMA'])->assertHasNoActionErrors();
    ($this->fiche)('90EFP001')->callAction('arbitrer', data: ['decision' => 'non_lie', 'motif' => 'Homonyme : personnes différentes, pièces vues'])->assertHasNoActionErrors();
    expect(($this->dossier)('90EFP012')->statut_rapprochement)->toBe('ecarte')
        ->and(($this->dossier)('90EFP001'))->statut_rapprochement->toBe('sans_auditeur')->auditeur_id->toBeNull()
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'rapprochement_decide')->count())->toBe(3);

    // Une reprise rejouée garde les arbitrages.
    app(RepriseAcma::class)->executer(($this->compte)('admin'), null, true);
    expect(($this->dossier)('90EFP011')->statut_rapprochement)->toBe('lie_arbitre')->and(($this->dossier)('90EFP001')->statut_rapprochement)->toBe('sans_auditeur');

    expect(fn () => app(ArchivesAcma::class)->arbitrer(($this->dossier)('90EFP013'), 'ecarte', null, 'Tentative de l\'agent', $this->agent))
        ->toThrow(AuthorizationException::class);
});

it('montre l\'historique ACMA sur la fiche de l\'auditeur lié, et pas ailleurs', function (): void {
    $this->actingAs($this->agent);
    $this->livewire(ViewAuditeur::class, ['record' => $this->lie->getRouteKey()])
        ->assertSee('Historique ACMA')->assertSee('2024-2025 · M1 (FPTC4) : Admis (enregistrée par ACMA), crédits 60 / 60')
        ->assertSee(ArchiveAcmaResource::getUrl('view', ['record' => ($this->dossier)('90EFP001')]), false);
    $this->livewire(ViewAuditeur::class, ['record' => $this->autre->getRouteKey()])->assertDontSee('Historique ACMA');

    $this->actingAs(($this->compte)('service_financier'));
    $this->livewire(ViewAuditeur::class, ['record' => $this->lie->getRouteKey()])->assertDontSee('Historique ACMA');
});

it('remet les classeurs de la reprise aux titulaires des permissions, et journalise le téléchargement', function (): void {
    $this->actingAs($this->agent);
    $this->livewire(ListArchivesAcma::class)->assertActionHidden('classeurRapprochement')->assertActionHidden('classeurEcarts');

    // Le classeur écrit par la reprise du beforeEach (son nom porte la date de la reprise).
    $classeur = basename((string) collect(Storage::disk('local')->files('acma'))->first(fn (string $f): bool => str_starts_with(basename($f), 'rapprochement-')));
    expect($classeur)->toStartWith('rapprochement-');

    $this->actingAs($this->responsable);
    $this->livewire(ListArchivesAcma::class)->assertActionVisible('classeurRapprochement')->assertActionHidden('classeurEcarts')
        ->callAction('classeurRapprochement')->assertFileDownloaded($classeur);
    expect(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'classeur_telecharge')->count())->toBe(1);
});

it('accorde les droits par migration additive, et se défait', function (): void {
    $complements = require database_path('migrations/2026_12_06_100000_create_acma_complements_table.php');
    $complements->down();
    $complements->up();
    app(ArchivesAcma::class)->completerDateNaissance(($this->dossier)('90EFP002'), '1990-05-05', 'Dossier papier', 'Date relevée sur l\'acte de naissance', $this->responsable);
    expect(fn () => $complements->down())->toThrow(RuntimeException::class, 'Compléments ou arbitrages');

    $migration = require database_path('migrations/2026_12_06_100100_grant_archives_acma_permissions.php');
    Permission::query()->whereIn('name', ['archives.consulter', 'archives.completer', 'archives.rapprocher'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration->up();
    $migration->up();
    $droits = fn (string $role): array => Role::findByName($role, 'web')->permissions->pluck('name')->filter(fn (string $p): bool => str_starts_with($p, 'archives.'))->sort()->values()->all();
    expect($droits('scolarite_agent'))->toBe(['archives.consulter'])
        ->and($droits('scolarite_responsable'))->toBe(['archives.completer', 'archives.consulter', 'archives.rapprocher'])
        ->and($droits('admin'))->toBe(['archives.completer', 'archives.consulter', 'archives.rapprocher'])
        ->and($droits('super_admin'))->toBe(['archives.completer', 'archives.consulter', 'archives.rapprocher'])
        ->and($droits('service_financier'))->toBe([]);

    $migration->down();
    expect(Permission::query()->where('name', 'like', 'archives.%')->exists())->toBeFalse();
});

it('présente le module en lecture seule à l\'écran des rôles, sans dérive', function (): void {
    $permissions = ModulePermissionMap::toPermissions(['archives' => ModulePermissionMap::LEVEL_READ], ['archives.completer']);

    expect($permissions)->toContain('archives.consulter')->toContain('archives.completer')
        ->and(ModulePermissionMap::toLevels($permissions)['archives'])->toBe(ModulePermissionMap::LEVEL_READ)
        ->and(ModulePermissionMap::toLevels(['archives.completer'])['archives'])->toBe(ModulePermissionMap::LEVEL_NONE);
});
