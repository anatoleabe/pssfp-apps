<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Filament\Resources\MaquetteResource;
use App\Filament\Resources\MaquetteResource\Pages\ListMaquettes;
use App\Filament\Resources\MaquetteResource\Pages\ViewMaquette;
use App\Filament\Resources\MaquetteResource\RelationManagers\UesRelationManager;
use App\Filament\Resources\ParcoursResource;
use App\Filament\Resources\RegleEvaluationResource;
use App\Filament\Resources\RegleEvaluationResource\Pages\EditRegleEvaluation;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\RegleEvaluation;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

uses()->group('filament', 'lmd');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $this->tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
});

it('ouvre le module Parcours LMD à la scolarité seulement', function (string $role, int $statut): void {
    $this->actingAs(($this->compte)($role));

    foreach ([MaquetteResource::getUrl('index'), MaquetteResource::getUrl('view', ['record' => $this->tc]), ParcoursResource::getUrl('index'), RegleEvaluationResource::getUrl('index')] as $url) {
        $this->get($url)->assertStatus($statut);
    }
})->with([['scolarite_responsable', 200], ['scolarite_agent', 200], ['admin', 200], ['admission_committee', 403], ['librarian', 403], ['caisse', 403]]);

it('liste les maquettes avec leurs contrôles', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ListMaquettes::class)->assertCanSeeTableRecords(Maquette::all())->assertSee('Conforme');
});

it('fait valider la maquette par le responsable, qui inscrit alors les auditeurs du parcours', function (): void {
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory(), 'bareme_id' => $bareme->id]);

    $this->actingAs(($this->compte)('scolarite_agent'));
    $this->livewire(ViewMaquette::class, ['record' => $this->tc->getRouteKey()])->assertActionHidden('valider');

    $this->actingAs(($this->compte)('scolarite_responsable'));
    $this->livewire(ViewMaquette::class, ['record' => $this->tc->getRouteKey()])->callAction('valider')->assertHasNoActionErrors();

    expect($this->tc->refresh()->statut)->toBe(Maquette::VALIDEE)
        ->and($inscription->inscriptionsPedagogiques()->count())->toBe(6);
});

it('n\'ouvre l\'édition des UE qu\'en brouillon', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));
    $page = ['ownerRecord' => $this->tc, 'pageClass' => ViewMaquette::class];

    $this->livewire(UesRelationManager::class, $page)->assertTableActionVisible('edit', $this->tc->ues()->first());

    $this->tc->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    $this->livewire(UesRelationManager::class, ['ownerRecord' => $this->tc->refresh(), 'pageClass' => ViewMaquette::class])
        ->assertTableActionHidden('edit', $this->tc->ues()->first())
        ->assertTableActionHidden('delete', $this->tc->ues()->first());
});

it('refuse une pondération par défaut qui ne fait pas 100 %', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));
    $regle = RegleEvaluation::pourAnnee('2026-2027');

    $this->livewire(EditRegleEvaluation::class, ['record' => $regle->getRouteKey()])
        ->fillForm(['pexam_defaut' => 60])
        ->call('save')
        ->assertHasFormErrors(['pexam_defaut']);

    $this->livewire(EditRegleEvaluation::class, ['record' => $regle->getRouteKey()])
        ->fillForm(['mode_rattrapage' => RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN, 'jury.quorum_pct' => 66])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($regle->refresh()->mode_rattrapage)->toBe(RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN)
        ->and($regle->jury['quorum_pct'])->toBe(66)
        ->and($regle->jury['delai_requetes_jours'])->toBe(10)
        ->and((float) $regle->pexam_defaut)->toBe(0.55);
});

it('réserve les règles d\'évaluation au titulaire de lmd.regles', function (): void {
    $regle = RegleEvaluation::pourAnnee('2026-2027');
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->get(RegleEvaluationResource::getUrl('edit', ['record' => $regle]))->assertForbidden();
});

it('montre le parcours sur la fiche auditeur et propose l\'inscription au parcours', function (): void {
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory(), 'bareme_id' => $bareme->id]);
    $this->tc->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $inscription->auditeur->getRouteKey()])
        ->assertSee('Pas encore inscrit à un parcours.')
        ->assertActionHidden('changerParcours')
        ->callAction('inscrirePedagogique')
        ->assertHasNoActionErrors();

    expect($inscription->inscriptionsPedagogiques()->count())->toBe(6);
});
