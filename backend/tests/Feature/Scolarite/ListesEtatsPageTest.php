<?php

declare(strict_types=1);

use App\Filament\Pages\Scolarite\ListesEtats;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\CriteresListe;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    $this->anterieure = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    foreach ([[$this->campagne, 'Économie Publique et Gestion Publique'], [$this->anterieure, 'Audit et contrôle des Finances Publiques']] as $n => [$campagne, $specialite]) {
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create([
            'nom' => 'Nom'.$n, 'prenom' => 'Essai', 'specialite' => $specialite, 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+23769966000'.$n, 'email' => 'liste'.$n.'@example.org',
        ]);
        $c->forceFill(['matricule' => '26PAG000'.$n, 'rang_retenu' => 1, 'matricule_attribue_at' => now()])->save();
        $a = Auditeur::query()->create(['candidature_id' => $c->id]);
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $a->id, 'bareme_id' => $bareme->id]);
        Versement::factory()->create(['inscription_annuelle_id' => $i->id, 'montant' => 185000]);
    }
});

it('ouvre l\'écran aux rôles qui exportent la situation, pas aux autres', function (string $role, bool $ouvert): void {
    $this->actingAs(($this->compte)($role));

    $this->get(ListesEtats::getUrl())->assertStatus($ouvert ? 200 : 403);
})->with([
    ['scolarite_agent', true],
    ['scolarite_responsable', true],
    ['admin', true],
    ['caisse', false],
    ['receptionniste', false],
    ['editor', false],
]);

it('ferme l\'écran à un agent de scolarité privé du droit d\'export', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $agent->syncRoles([]);
    $agent->givePermissionTo(['view_any_auditeur', 'view_auditeur', 'scolarite.versement_saisir']);
    $this->actingAs($agent->fresh());

    $this->get(ListesEtats::getUrl())->assertForbidden();
    expect(ListesEtats::canAccess())->toBeFalse();
});

it('propose par défaut la dernière promotion, ses spécialités et les auditeurs actifs', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $page = $this->livewire(ListesEtats::class)
        ->assertFormSet([
            'campagne_id' => $this->campagne->id, 'niveau' => null, 'specialite' => null,
            'situation' => CriteresListe::TOUTES, 'statuts' => [Auditeur::STATUT_ACTIF], 'regroupement' => CriteresListe::PAR_SPECIALITE,
        ])
        ->assertFormFieldExists('specialite', fn (Select $champ): bool => $champ->getOptions() === ['Économie Publique et Gestion Publique' => 'Économie Publique et Gestion Publique'])
        ->assertSee('1 auditeur correspond à ces critères');

    $page->fillForm(['campagne_id' => $this->anterieure->id])
        ->assertFormFieldExists('specialite', fn (Select $champ): bool => array_keys($champ->getOptions()) === ['Audit et contrôle des Finances Publiques']);
});

it('télécharge la situation financière en PDF et journalise l\'édition', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $this->actingAs($agent);

    $this->livewire(ListesEtats::class)
        ->fillForm(['niveau' => 'M1', 'regroupement' => CriteresListe::PAR_NIVEAU])
        ->callAction('situationFinanciere')
        ->assertHasNoFormErrors()
        ->assertFileDownloaded('situation-financiere-P14-'.now('Africa/Douala')->format('Y-m-d').'.pdf');

    $journal = Activity::query()->where('event', 'liste_editee')->sole();
    expect($journal->causer_id)->toBe($agent->id)
        ->and($journal->properties['niveau'])->toBe('M1')
        ->and($journal->properties['regroupement'])->toBe(CriteresListe::PAR_NIVEAU)
        ->and($journal->properties['lignes'])->toBe(1);
});

it('télécharge la liste nominative en PDF', function (): void {
    $this->actingAs(($this->compte)('scolarite_responsable'));

    $this->livewire(ListesEtats::class)
        ->callAction('listeNominative')
        ->assertFileDownloaded('liste-nominative-P14-'.now('Africa/Douala')->format('Y-m-d').'.pdf');

    expect(Activity::query()->where('event', 'liste_editee')->sole()->properties['liste'])->toBe('nominative');
});

it('exige une promotion et au moins un statut', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'));

    $this->livewire(ListesEtats::class)
        ->fillForm(['campagne_id' => null, 'statuts' => []])
        ->callAction('situationFinanciere')
        ->assertHasFormErrors(['campagne_id' => 'required', 'statuts' => 'required'])
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'liste_editee')->exists())->toBeFalse();
});
