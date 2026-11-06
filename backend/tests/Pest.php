<?php

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Le helper `livewire()` du plugin Pest est exposé directement par le trait
// `InteractsWithLivewire` ajouté dans Tests\TestCase.php. Tous les tests Feature
// (incluant Feature/Filament) en profitent automatiquement.

// Les services unitaires utilisent souvent config() / app() et nécessitent
// le bootstrap Laravel — sans `RefreshDatabase` (pas d'accès DB normalement).
pest()->extend(TestCase::class)
    ->in('Unit/Services');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Solde le palier d'inscription d'un retenu doté d'un matricule, comme l'aurait
 * fait la Scolarité : barème de l'année, auditeur, inscription, versement.
 */
function solderInscription(Candidature $dossier): InscriptionAnnuelle
{
    $annee = $dossier->campagne->anneeAcademique();
    $bareme = BaremeScolarite::query()->firstOrCreate(
        ['annee_academique' => $annee, 'niveau' => 'M1'],
        ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000],
    );
    $auditeur = Auditeur::query()->firstOrCreate(['candidature_id' => $dossier->id]);
    $inscription = InscriptionAnnuelle::query()->firstOrCreate(
        ['auditeur_id' => $auditeur->id, 'annee_academique' => $annee],
        [
            'bareme_id' => $bareme->id, 'niveau' => 'M1',
            'montant_inscription' => $bareme->montant_inscription,
            'montant_tranche1' => $bareme->montant_tranche1,
            'montant_tranche2' => $bareme->montant_tranche2,
        ],
    );
    Versement::factory()->create(['inscription_annuelle_id' => $inscription->id, 'montant' => $inscription->montant_inscription]);
    $dossier->unsetRelation('auditeur');

    return $inscription;
}

/** Annule tous les versements du dossier : l'inscription n'est plus soldée. */
function annulerVersements(Candidature $dossier): void
{
    Versement::query()
        ->whereHas('inscription.auditeur', fn ($q) => $q->where('candidature_id', $dossier->id))
        ->update(['annule_at' => now()]);
    $dossier->unsetRelation('auditeur');
}

/** Fiche auditeur d'un dossier (créée si le test a posé le matricule à la main). */
function ficheDe(Candidature $dossier): Auditeur
{
    return Auditeur::query()->firstOrCreate(['candidature_id' => $dossier->id]);
}
