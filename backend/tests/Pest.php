<?php

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Candidature;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\MessagesAuditeurs;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\ResultatConfirmation;
use App\Services\Scolarite\ResultatOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/**
 * Annule tous les versements du dossier : l'inscription n'est plus soldée. Leurs
 * imputations d'abord, dans l'ordre qu'exige le déclencheur de cohérence (ADR-0013).
 */
function annulerVersements(Candidature $dossier): void
{
    $ids = Versement::query()->whereHas('auditeur', fn ($q) => $q->where('candidature_id', $dossier->id))
        ->whereNull('annule_at')->pluck('id');
    ImputationVersement::query()->whereIn('versement_id', $ids)->whereNull('annule_at')
        ->update(['annule_at' => now(), 'annule_motif' => 'Annulation de test']);
    Versement::query()->whereKey($ids)->update(['annule_at' => now()]);
    $dossier->unsetRelation('auditeur');
}

/** Annule un versement à la main (test d'un modèle) : ses imputations d'abord, comme R4. */
function annulerVersement(Versement $versement, string $motif = 'Annulation de test'): void
{
    ImputationVersement::query()->where('versement_id', $versement->id)->whereNull('annule_at')
        ->update(['annule_at' => now(), 'annule_motif' => $motif]);
    $versement->forceFill(['annule_at' => now(), 'annule_motif' => $motif])->save();
}

/**
 * Scan fictif d'un bordereau, à déposer dans un formulaire Filament (disque des pièces simulé par le test) : le
 * service financier le joint à l'encaissement d'un paiement en banque (décision du 19/11/2026).
 */
function bordereauScanne(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('bordereau.pdf', "%PDF-1.4\n%%EOF\n");
}

/** Fiche auditeur d'un dossier (créée si le test a posé le matricule à la main). */
function ficheDe(Candidature $dossier): Auditeur
{
    return Auditeur::query()->firstOrCreate(['candidature_id' => $dossier->id]);
}

/**
 * Confirmation d'un versement par un agent de scolarité (décision du 15/11/2026), sur un scan fictif : comme
 * la scolarité au guichet, avec ses effets (régularisation de l'accès anticipé, SMS des accès).
 */
function confirmerVersement(Versement $versement): ResultatConfirmation
{
    $agent = tap(User::factory()->create(['email' => 'confirmation-'.Str::random(8).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_agent'));

    return app(ConfirmationsVersements::class)->confirmer($versement, Versement::DOSSIER_JUSTIFICATIFS.'/'.Str::ulid().'.pdf', true, $agent);
}

/**
 * Versement enregistré puis confirmé par la scolarité : l'argent compte pour l'accès, comme une saisie avant le
 * 15/11/2026. `sms` est celui des accès, parti à la confirmation.
 *
 * @param  array<string, mixed>  $saisie
 */
function verserConfirme(InscriptionAnnuelle $inscription, array $saisie, User $par): ResultatOperation
{
    $r = app(RegistreVersements::class)->enregistrer($inscription, $saisie, $par);
    $c = confirmerVersement($r->versement);

    return new ResultatOperation($r->versement, $c->sms ?? $r->sms);
}

/**
 * État d'accès selon la règle d'avant la confirmation des versements (15/11/2026) : tout l'argent
 * imputé compte. Oracle du test d'équivalence de la reprise ; reprend AccesEcampus::etat() en lisant
 * les paliers du compte au lieu des paliers confirmés.
 */
function etatAvecToutLArgent(Candidature $dossier): string
{
    $c = Candidature::query()->with('auditeur.inscriptionCourante.bareme', 'auditeur.inscriptionCourante.deblocageEnCours')->findOrFail($dossier->id);
    if ($c->statut !== Candidature::STATUT_ACCEPTE || $c->withdrawn_at !== null) {
        return AccesEcampus::INDISPONIBLE;
    }
    $inscription = $c->auditeur?->inscriptionCourante;
    $deblocage = $inscription?->deblocageEnCours !== null && ! in_array($c->auditeur?->statut, MessagesAuditeurs::STATUTS_EXCLUS, true);
    if ($inscription === null || (! $deblocage && ! $inscription->situation()->paliers[0]->solde())) {
        return AccesEcampus::BLOQUE;
    }

    return AccesEcampus::compteExiste($c) && filled($c->matricule) ? AccesEcampus::DISPONIBLE : AccesEcampus::EN_PREPARATION;
}

/**
 * Jeux d'essai seulement : remplace le matricule fictif d'un dossier (la fabrique d'auditeurs en donne un au hasard).
 * Le déclencheur « matricule figé » ne laisse changer un matricule attribué que dans la reprise du matricule ACMA ;
 * ce test-ci se place, le temps de son écriture, dans la même exception.
 *
 * @param  array<string, mixed>  $champs
 */
function matriculeDeTest(Candidature $c, array $champs): void
{
    DB::transaction(function () use ($c, $champs): void {
        DB::select("SELECT set_config('app.conversion_matricule', 'on', true)");
        $c->forceFill($champs)->save();
    });
}
