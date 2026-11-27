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
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\RegleEvaluation;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\NumerotationSemestres;
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
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);

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

it('affiche les semestres de la maquette dans la numérotation de sa promotion', function (): void {
    $promotion = function (int $numero, string $annee): CampagneCandidature {
        $c = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique '.$annee], 'promotion_numero' => $numero]);
        Candidature::factory()->forCampagne($c)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);

        return $c;
    };
    $p13 = $promotion(13, '2025-2026');
    $p14 = $promotion(14, '2026-2027');
    $gtfp = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'GTFP'))->firstOrFail();
    $this->actingAs(($this->compte)('scolarite_agent'));

    // Réglage par défaut : S1-S2 en M1, S3-S4 en M2.
    $this->get(MaquetteResource::getUrl('view', ['record' => $this->tc]))->assertOk()
        ->assertSee('Semestre 1 · 30 crédits')->assertSee('Semestre 2 · 30 crédits')->assertDontSee('Semestre 7');
    $this->get(MaquetteResource::getUrl('view', ['record' => $gtfp]))->assertOk()
        ->assertSee('Semestre 3 · 30 crédits')->assertSee('Semestre 4 · 30 crédits')->assertDontSee('Semestre 9');

    // La 14e promotion passe en LMD : son M1 devient S7-S8 ; le M2 de la 13e reste en S3-S4.
    $p14->update(['numerotation_semestres' => NumerotationSemestres::LMD]);
    $this->get(MaquetteResource::getUrl('view', ['record' => $this->tc]))->assertOk()
        ->assertSee('Semestre 7 · 30 crédits')->assertSee('Semestre 8 · 30 crédits')->assertDontSee('Semestre 1 ·');
    $this->get(MaquetteResource::getUrl('view', ['record' => $gtfp]))->assertOk()
        ->assertSee('Semestre 3 · 30 crédits')->assertDontSee('Semestre 9');

    $page = fn (Maquette $m): array => ['ownerRecord' => $m, 'pageClass' => ViewMaquette::class];
    $this->livewire(UesRelationManager::class, $page($this->tc))
        ->assertTableColumnFormattedStateSet('semestre', 'S7', $this->tc->ues()->where('semestre', 7)->firstOrFail());
    $this->livewire(UesRelationManager::class, $page($gtfp))
        ->assertTableColumnFormattedStateSet('semestre', 'S3', $gtfp->ues()->where('semestre', 9)->firstOrFail());
    expect($this->tc->ues()->pluck('semestre')->unique()->sort()->values()->all())->toBe([7, 8]);
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
    $inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => Auditeur::factory()->campagneCommune(), 'bareme_id' => $bareme->id]);
    $this->tc->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ViewAuditeur::class, ['record' => $inscription->auditeur->getRouteKey()])
        ->assertSee('Pas encore inscrit à un parcours.')
        ->assertInfolistActionHidden('candidature.specialite', 'changerSpecialite')
        ->callAction('inscrirePedagogique')
        ->assertHasNoActionErrors();

    expect($inscription->inscriptionsPedagogiques()->count())->toBe(6);
});
