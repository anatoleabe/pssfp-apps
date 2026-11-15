<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Acma\ChargementTransit;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Acma\Reprise\ResultatControle;
use App\Support\Journaux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\Support\Acma\JeuAcmaFictif;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma');

beforeEach(function (): void {
    Storage::fake('local');
    $this->usi = tap(User::factory()->create(['email' => 'usi-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole(Role::findOrCreate('admin', 'web')));
});

function reprise15c(User $auteur, bool $appliquer = true): array
{
    return app(RepriseAcma::class)->executer($auteur, null, $appliquer);
}

function paiementAcma(int $id): object
{
    return DB::table('acma_paiements')->where('id_acma', $id)->first();
}

function controle15c(array $resultat, string $code): ResultatControle
{
    return collect($resultat['controles'])->first(fn (ResultatControle $c): bool => $c->code === $code);
}

/** Auditeur fictif de la plateforme, de la promotion donnée, inscrit en 2026-2027. */
function auditeurPromotion(string $matricule, int $promotion, string $niveau): InscriptionAnnuelle
{
    $auditeur = Auditeur::factory()->create();
    $auditeur->candidature->forceFill(['matricule' => $matricule, 'nom' => 'ESSAI', 'prenom' => 'Plateforme'])->save();
    $auditeur->candidature->campagne->forceFill(['promotion_numero' => $promotion])->save();
    $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => '2026-2027', 'niveau' => $niveau], ['montant_inscription' => $niveau === 'M1' ? 185000 : 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);

    return InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => $bareme->id]);
}

function versementPlateforme(InscriptionAnnuelle $inscription, int $montant, string $date, string $reference): Versement
{
    return Versement::factory()->create(['inscription_annuelle_id' => $inscription->id, 'montant' => $montant, 'date_versement' => $date, 'reference' => $reference, 'mode' => 'especes']);
}

it('reprend barèmes et paiements avec leurs drapeaux, au franc près (C06), sans créer de versement', function (): void {
    TransitAcma::construire();
    $versementsAvant = Versement::query()->count();

    $r = reprise15c($this->usi);

    expect($r['statut'])->toBe('appliquee')
        ->and(controle15c($r, 'C06')->statut())->toBe('conforme')
        ->and(controle15c($r, 'C06')->obtenu)->toBe('11 ; 2 635 000 ; 2 770 000')
        ->and(controle15c($r, 'C11')->statut())->toBe('conforme')
        ->and(DB::table('acma_paiements')->count())->toBe(11)
        ->and(Versement::query()->count())->toBe($versementsAvant);
    expect($r['comptes']['paiements']['ignorees'])->toBe(['annee_invalide' => 0, 'annee_non_reprise' => 6, 'dossier_exclu' => 1])
        ->and($r['comptes']['paiements']['anomalies'])->toBe(['negatifs' => 2, 'apparies' => 1, 'total_nul' => 1, 'date_aberrante' => 3, 'hors_annee' => 3, 'doublon_exact' => 2, 'niveau_sans_inscription' => 1]);

    expect(paiementAcma(1))->total->toBe(735000)->montant_scolarite->toBe(685000)->inscriptions->toBe(50000)->date_recu->toBe('2024-11-02')->negatif->toBeFalse()
        ->and(paiementAcma(2))->negatif->toBeTrue()->contrepartie_id_acma->toBe(3)
        ->and(paiementAcma(3)->contrepartie_id_acma)->toBe(2)
        ->and(paiementAcma(6))->negatif->toBeTrue()->contrepartie_id_acma->toBeNull()
        ->and(paiementAcma(7))->total_nul->toBeTrue()->date_recu->toBe('2230-01-01')->date_aberrante->toBeTrue()->hors_annee->toBeTrue()
        ->and(paiementAcma(8))->date_aberrante->toBeTrue()->hors_annee->toBeTrue()
        ->and(paiementAcma(9))->date_recu->toBeNull()->date_aberrante->toBeTrue()->hors_annee->toBeFalse()
        ->and(paiementAcma(10))->hors_annee->toBeTrue()->date_aberrante->toBeFalse()
        ->and(paiementAcma(4)->doublon_exact)->toBeTrue()->and(paiementAcma(5)->doublon_exact)->toBeTrue()
        ->and(paiementAcma(11))->niveau_sans_inscription->toBeTrue()->total->toBe(185000)->phase->toBe('FRAIS INSCRIPTION');
    expect(DB::table('acma_paiements')->where('annee', '2026-2027')->exists())->toBeFalse();

    $bareme = DB::table('acma_baremes')->where('cle_source', '2025-2026|FPTC4')->first();
    expect(DB::table('acma_baremes')->count())->toBe(6)
        ->and($bareme)->du_total->toBe(1185000)->tranche2->toBe(35000)->heterogene->toBeFalse()
        ->and(json_decode($bareme->categories))->toBe(['AC', 'TC']);

    // Rejeu : rien n'est réécrit.
    $rejeu = reprise15c($this->usi);
    foreach (['paiements' => 'paiements', 'baremes' => 'baremes', 'soutenances' => 'soutenances', 'documents' => 'documents'] as $etape => $table) {
        expect($rejeu['comptes'][$etape][$table])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'retirees' => 0]);
    }
});

it('bloque l\'étape sur un montant décimal, sans rien écrire', function (): void {
    TransitAcma::construireAvec(fn (array &$l) => $l['du'][0]['Montant'] = '685000.5');

    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email, '--appliquer' => true])
        ->expectsOutputToContain('Montant non entier dans ACMA (paiement 1, colonne Montant)')->assertFailed();

    expect(DB::table('acma_paiements')->count())->toBe(0)->and(DB::table('acma_dossiers')->count())->toBe(0);
});

it('reprend soutenances (dates en lettres, mentions, plusieurs PV) et documents d\'ACMA (C07)', function (): void {
    TransitAcma::construire();
    $r = reprise15c($this->usi);

    expect(controle15c($r, 'C07')->statut())->toBe('conforme')
        ->and(DB::table('acma_soutenances')->count())->toBe(4)
        ->and(DB::table('acma_soutenances')->where('dossier_id', DB::table('acma_dossiers')->where('matricule', '90EFP001')->value('id'))->count())->toBe(2);
    $s = fn (int $id) => DB::table('acma_soutenances')->where('id_acma', $id)->first();
    expect($s(1))->date->toBe('2026-07-15')->date_origine->toBe('texte')->mention->toBe('Très bien')->mention_source->toBe('TRES-BIEN')->note->toBe(16.5)
        ->and(json_decode($s(1)->membres))->toBe(['MEMBRE UN'])
        ->and($s(2))->date->toBe('2026-08-12')->mention->toBe('Très bien')
        ->and($s(3))->date->toBe('2019-08-03')->mention->toBe('Bien')
        ->and($s(5))->date->toBe('2025-12-09')->mention->toBe('Très bien')->niveau->toBe('M2');

    expect(DB::table('acma_documents')->orderBy('type')->pluck('type')->all())->toBe(['attestation_reussite', 'certificat', 'lettre_stage', 'mise_a_disposition', 'non_perception_bourse']);
    $certificat = DB::table('acma_documents')->where('type', 'certificat')->first();
    expect($certificat)->date->toBeNull()->date_source->toBe('2024-2025')
        ->and(json_decode($certificat->details, true))->toEqual(['specialite' => 'Tronc commun', 'parcours' => 'Master', 'annee_academique' => '2024-2025']);
    expect(DB::table('acma_documents')->where('type', 'lettre_stage')->value('details'))->toContain('Structure fictive')
        ->and(DB::table('acma_documents')->pluck('details')->implode(' '))->not->toContain('NOM COPIE');

    refuseeFinanciere(fn () => DB::table('acma_paiements')->update(['total' => 0]));
    refuseeFinanciere(fn () => DB::table('acma_documents')->delete());
});

function refuseeFinanciere(callable $ecriture): void
{
    try {
        DB::transaction($ecriture);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('lecture seule');

        return;
    }
    test()->fail('Écriture acceptée dans l\'archive financière.');
}

it('produit le rapport des écarts 2026-2027 sans rien modifier', function (): void {
    TransitAcma::construireAvec(function (array &$l): void {
        $l['du'][] = ['IddroitU' => '19', 'matricule' => '26EFP905', 'Niveau' => 'FPTC4', 'anneacademic' => '2026-2027', 'Montant' => '50000', 'dateRecu' => '2026-11-10', 'NumeroRecu' => 'R-SEUL', 'codephase' => 'AVANCE FRAIS SCOLARITE'];
    });
    $p14 = auditeurPromotion('26EFP905', 14, 'M1');
    $p13 = auditeurPromotion('90EFP001', 13, 'M2');
    versementPlateforme($p14, 685000, '2026-10-02', 'CR-2026-001');
    versementPlateforme($p14, 300000, '2026-10-23', 'XYZ-9');
    versementPlateforme($p14, 300000, '2026-11-20', 'AUTRE-1');
    versementPlateforme($p14, 120000, '2026-11-05', 'PLAT-ONLY');
    versementPlateforme($p13, 200000, '2026-10-16', 'R 18');
    $avant = [Versement::query()->count(), InscriptionAnnuelle::query()->count(), DB::table('acma_transit.du')->count()];

    $this->artisan('acma:ecarts-2026', ['--auteur' => $this->usi->email])->expectsOutputToContain('Classeur (nominatif)')->assertSuccessful();

    expect([Versement::query()->count(), InscriptionAnnuelle::query()->count(), DB::table('acma_transit.du')->count()])->toBe($avant)
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'ecarts_2026_produits')->count())->toBe(1);
    $classeur = IOFactory::load(Storage::disk('local')->path('acma/ecarts-2026-2027-'.now()->format('Ymd').'.xlsx'));
    $feuille = fn (string $nom): array => array_slice($classeur->getSheetByName($nom)->toArray(null, true, false), 1);

    $synthese = collect($feuille('Synthèse'))->keyBy(0);
    expect($synthese['P14'])->toEqual(['P14', 5, 1150000, 1335000, 4, 1405000, -70000])
        ->and($synthese['P13'])->toEqual(['P13', 1, 250000, 250000, 1, 200000, 50000])
        ->and($synthese['P12 et antérieures'])->toEqual(['P12 et antérieures', 1, 350000, 350000, 0, 0, 350000]);
    expect(array_column($feuille('Absents de la plateforme'), 4))->toBe(['R-SEUL'])
        ->and($feuille('Montants différents'))->toHaveCount(1)
        ->and($feuille('Montants différents')[0][5])->toEqual(250000)
        ->and($feuille('Montants différents')[0][7])->toEqual(50000)
        ->and(array_column($feuille("Absents d'ACMA"), 3))->toBe(['PLAT-ONLY'])
        ->and(array_column($feuille('Auditeurs hors plateforme'), 0))->toBe(['24EFP917'])
        ->and(array_column($feuille('Inscriptions'), 2))->toBe(['Inscrit dans la plateforme, pas dans ACMA'])
        ->and($feuille('Notes 2026-2027')[0])->toEqual(['IPS03', 1, 0, 'Grille vide : aucune valeur, non reprise']);
});

it('gèle l\'archive seulement après une reprise appliquée et des contrôles verts, puis purge le transit', function (): void {
    TransitAcma::construire();
    $this->artisan('acma:purger-transit', ['--auteur' => $this->usi->email])->expectsOutputToContain('Archive non gelée')->assertFailed();
    $this->artisan('acma:geler', ['--auteur' => $this->usi->email])->expectsOutputToContain('Aucune reprise appliquée')->assertFailed();

    reprise15c($this->usi);
    // Le transit change après la reprise : C06 n'est plus conforme, le gel est refusé.
    TransitAcma::construireAvec(fn (array &$l) => array_shift($l['du']));
    $this->artisan('acma:geler', ['--auteur' => $this->usi->email])->expectsOutputToContain('Contrôles bloquants en échec (C06)')->assertFailed();

    TransitAcma::construire();
    $this->artisan('acma:geler', ['--auteur' => $this->usi->email])
        ->expectsConfirmation('Geler définitivement l\'archive (reprise '.DB::table('acma_reprises')->where('statut', 'appliquee')->value('uuid').') ? Aucune reprise ne sera plus possible.', 'no')
        ->assertFailed();
    expect(DB::table('acma_reprises')->where('statut', 'gelee')->exists())->toBeFalse();

    $this->artisan('acma:geler', ['--auteur' => $this->usi->email])
        ->expectsQuestion('Geler définitivement l\'archive (reprise '.DB::table('acma_reprises')->where('statut', 'appliquee')->value('uuid').') ? Aucune reprise ne sera plus possible.', true)
        ->expectsOutputToContain('Archive gelée')->assertSuccessful();
    expect(DB::table('acma_reprises')->where('statut', 'gelee')->first())->gelee_par->toBe($this->usi->id)
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'archive_gelee')->count())->toBe(1);
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email])->expectsOutputToContain('Archive gelée le')->assertFailed();
    $this->artisan('acma:geler', ['--auteur' => $this->usi->email])->expectsOutputToContain('Archive gelée le')->assertFailed();

    $this->artisan('acma:purger-transit', ['--auteur' => $this->usi->email])
        ->expectsConfirmation('Supprimer le schéma de transit ACMA ? L\'archive gelée reste en place.', 'yes')
        ->expectsOutputToContain('Transit ACMA purgé')->assertSuccessful();
    expect(ChargementTransit::charge())->toBeFalse()
        ->and(DB::table('acma_paiements')->count())->toBe(11)
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'transit_purge')->count())->toBe(1);
});

it('cite seulement des comptes dans le rapport des contrôles', function (): void {
    TransitAcma::construire();
    reprise15c($this->usi, false);

    $rapport = Storage::disk('local')->get('acma/controles-'.now()->format('Ymd').'.md');
    expect($rapport)->toContain('| C06 |')->toContain('| C07 |')->toContain('| C12 |');
    foreach (JeuAcmaFictif::donneesNominatives() as $valeur) {
        expect(mb_strtoupper($rapport))->not->toContain(mb_strtoupper($valeur));
    }
});

it('ne garde que le dernier classeur nominatif de chaque type', function (): void {
    TransitAcma::construire();
    $this->travelTo(now()->setDate(2026, 11, 20));
    reprise15c($this->usi, false);
    $this->artisan('acma:ecarts-2026', ['--auteur' => $this->usi->email])->assertSuccessful();
    $this->travelTo(now()->setDate(2026, 11, 21));
    reprise15c($this->usi, false);
    $this->artisan('acma:ecarts-2026', ['--auteur' => $this->usi->email])->assertSuccessful();

    $classeurs = collect(Storage::disk('local')->files('acma'))->filter(fn (string $f): bool => str_ends_with($f, '.xlsx'))
        ->map(fn (string $f): string => basename($f))->sort()->values()->all();
    expect($classeurs)->toBe(['ecarts-2026-2027-20261121.xlsx', 'rapprochement-20261121.xlsx']);
});
