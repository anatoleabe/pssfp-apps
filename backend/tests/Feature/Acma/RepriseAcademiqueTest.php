<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\User;
use App\Services\Acma\Reprise\ContexteReprise;
use App\Services\Acma\Reprise\Etape;
use App\Services\Acma\Reprise\EtapeVersionsNotes;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Acma\Reprise\ResultatControle;
use App\Support\Journaux;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma');

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    Queue::fake();
    Http::fake();
    $this->usi = tap(User::factory()->create(['email' => 'usi-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole(Role::findOrCreate('admin', 'web')));
});

/** Auditeur de la plateforme (fictif) : matricule, nom, prénom et date de naissance de sa candidature. */
function auditeurAcma(string $matricule, string $nom, string $prenom, string $naissance): Auditeur
{
    $auditeur = Auditeur::factory()->create();
    $auditeur->candidature->forceFill(['matricule' => $matricule, 'nom' => $nom, 'prenom' => $prenom, 'epouse' => null, 'date_naissance' => $naissance])->save();

    return $auditeur;
}

function reprendre(User $auteur, bool $appliquer = true, ?array $etapes = null): array
{
    return app(RepriseAcma::class)->executer($auteur, $etapes, $appliquer);
}

function dossierAcma(string $matricule): ?object
{
    return DB::table('acma_dossiers')->where('matricule', $matricule)->first();
}

function inscriptionAcma(string $matricule, string $annee, string $niveau): ?object
{
    return DB::table('acma_inscriptions')->where('cle_source', "{$matricule}|{$annee}|{$niveau}")->first();
}

/** Une écriture que la base doit refuser (dans un point de sauvegarde, pour ne pas interrompre le test). */
function refusee(callable $ecriture, string $message): void
{
    try {
        DB::transaction($ecriture);
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($message);

        return;
    }
    test()->fail('Écriture acceptée alors qu\'elle devait être refusée : '.$message);
}

function statutControle(array $resultat, string $code): string
{
    return collect($resultat['controles'])->first(fn (ResultatControle $c): bool => $c->code === $code)->statut();
}

it('reprend le jeu fictif à blanc : rien n\'est écrit, le rapport est produit et l\'essai journalisé', function (): void {
    TransitAcma::construire();

    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email])
        ->expectsOutputToContain('Essai à blanc')->assertSuccessful();

    foreach (['acma_annees', 'acma_unites', 'acma_dossiers', 'acma_inscriptions', 'acma_resultats_ue', 'acma_versions_notes', 'acma_rapprochements'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('acma_reprises')->pluck('statut')->all())->toBe(['a_blanc'])
        ->and(Storage::disk('local')->exists('acma/controles-'.now()->format('Ymd').'.md'))->toBeTrue()
        ->and(DB::table('activity_log')->where('log_name', Journaux::ARCHIVES)->where('event', 'reprise_essai')->count())->toBe(1);
    $rapport = Storage::disk('local')->get('acma/controles-'.now()->format('Ymd').'.md');
    foreach (['90EFP001', 'ESSAI', 'ALPHA'] as $nominatif) {
        expect($rapport)->not->toContain($nominatif);
    }
});

it('applique la reprise : dossiers fusionnés, exclusions, identités normalisées, inscriptions, contrôles bloquants verts', function (): void {
    TransitAcma::construire();

    $r = reprendre($this->usi);

    expect($r['statut'])->toBe('appliquee')
        ->and(collect($r['controles'])->filter(fn (ResultatControle $c) => $c->enEchec())->all())->toBe([])
        ->and(DB::table('acma_dossiers')->count())->toBe(15)
        ->and(DB::table('acma_annees')->count())->toBe(13)
        ->and(DB::table('acma_unites')->count())->toBe(10)
        ->and(DB::table('acma_inscriptions')->count())->toBe(17)
        ->and($r['comptes']['dossiers']['exclusions'])->toMatchArray(['neutralisee_zz' => 1, 'seulement_2026_2027' => 1, 'sans_inscription_datee' => 1, 'filiere_test' => 1]);
    foreach (['ZZ90EFP004', '26EFP905', '90EFP006', 'POURTEST1'] as $exclu) {
        expect(dossierAcma($exclu))->toBeNull();
    }

    $fusionne = dossierAcma('90EFP003');
    expect(json_decode($fusionne->fiches_fusionnees))->toBe(['90EFP003', '90efp003'])->and($fusionne->matricule_source)->toBe('90EFP003');
    $minuscule = dossierAcma('90EFP002');
    expect($minuscule->matricule_source)->toBe('90efp002 ')->and($minuscule->date_naissance)->toBeNull()->and($minuscule->date_naissance_a_verifier)->toBeTrue()
        ->and($minuscule->nom_complet)->toBe('ESSAI GAMMA')->and($minuscule->sexe)->toBe('F');
    expect(dossierAcma('90EFP001'))->nom_complet->toBe('ESSAI ALPHA BÉTA')->date_naissance->toBe('1985-03-12')
        ->premiere_annee->toBe('2024-2025')->derniere_annee->toBe('2025-2026');
    expect(dossierAcma('90EFP008')->sexe)->toBe('M');
    expect(dossierAcma('90EFP016'))->date_naissance->toBeNull()->annee_naissance->toBe(1975)->date_naissance_precision->toBe('vers')->date_naissance_a_verifier->toBeFalse();
    expect(json_decode(dossierAcma('90EFP010-')->signalements))->toBe(['caractere_parasite']);
    expect(dossierAcma('90EFP007'))->premiere_annee->toBeNull()->signalements->toContain('sans_inscription_datee');
    expect(dossierAcma('99XX12'))->not->toBeNull()->and(dossierAcma('99XX012'))->not->toBeNull()
        ->and($r['comptes']['dossiers']['collisions_sans_zeros'])->toBe(1);

    $double = inscriptionAcma('90EFP003', '2025-2026', 'FPTC4');
    expect(json_decode($double->ids_acma))->toBe([5, 6])->and($r['comptes']['inscriptions']['lignes_en_double_reunies'])->toBe(1);
    expect(inscriptionAcma('90EFP015', '2025-2026', 'GTFP5')->deux_niveaux_meme_annee)->toBeTrue()
        ->and(inscriptionAcma('90EFP015', '2025-2026', 'FPTC4')->deux_niveaux_meme_annee)->toBeTrue()
        ->and(inscriptionAcma('90EFP001', '2025-2026', 'AUCO5'))->niveau->toBe('M2')->parcours->toBe('ACFP');
    expect($r['comptes']['inscriptions']['ignorees'])->toBe(['sans_annee_ni_niveau' => 2, 'annee_invalide' => 1, 'annee_non_reprise' => 2, 'dossier_exclu' => 2]);
    expect(DB::table('acma_inscriptions')->where('annee', '2026-2027')->count())->toBe(0)
        ->and(statutControle($r, 'C11'))->toBe('conforme')
        ->and(statutControle($r, 'C02'))->toBe('conforme')
        ->and(statutControle($r, 'C03'))->toBe('conforme')
        ->and(collect($r['controles'])->firstWhere('code', 'C03')->obtenu)->toBe('5')
        ->and(statutControle($r, 'C04a'))->toBe('conforme');

    $unite = DB::table('acma_unites')->where('id_prog_acma', 302)->first();
    expect($unite)->ponderations_hors_norme->toBeTrue()->semestre->toBe(4)->intitule_fr->toBe('Contrôle de gestion')->intitule_en->toBe('Management control');
    expect(DB::table('acma_unites')->where('id_prog_acma', 301)->value('semestre'))->toBe(3)
        ->and(DB::table('acma_unites')->where('id_prog_acma', 601)->value('groupe_autre_niveau'))->toBeTrue()
        ->and(DB::table('acma_unites')->where('id_prog_acma', 102)->value('intitule_en'))->toBeNull();
});

it('reconstitue la note écrasée, garde le conflit hors des crédits et ne recalcule jamais la décision', function (): void {
    TransitAcma::construire();
    reprendre($this->usi);

    // Note de 2024-2025 écrasée dans `notes` : dernière version de l'historique.
    $reconstituee = DB::table('acma_resultats_ue')->where('cle_source', 'h:101:90EFP014')->first();
    expect($reconstituee)->origine->toBe('historique')->cc->toBe('10.00')->examen->toBe('9.00')->moyenne->toBe('9.30')->acquise->toBeFalse()
        ->and($reconstituee->inscription_id)->toBe(inscriptionAcma('90EFP014', '2024-2025', 'FPTC4')->id);
    // Programme supprimé : note non rattachée.
    expect(DB::table('acma_notes_non_rattachees')->where('cle_source', 'h:999:90EFP014')->first())->origine->toBe('historique')->motif->toBe('programme_disparu')->code_ue->toBe('XX999');
    // Programme d'un niveau où l'auditeur n'est pas inscrit cette année-là : gardée, non rattachée, hors crédits.
    expect(DB::table('acma_notes_non_rattachees')->where('cle_source', 'h:301:90EFP014')->value('motif'))->toBe('sans_inscription');

    // Conflit : les deux résultats restent, hors des crédits.
    $conflit = inscriptionAcma('90EFP003', '2025-2026', 'FPTC4');
    expect(DB::table('acma_resultats_ue')->where('inscription_id', $conflit->id)->where('en_conflit', true)->count())->toBe(2)
        ->and($conflit)->credits_acquis->toBe(0)->moyenne_recalculee->toBeNull()->nb_conflits->toBe(2)
        ->and(json_decode($conflit->signalements))->toContain('conflit_de_notes');

    // Moyenne pondérée indicative, crédits recalculés, décision d'ACMA.
    expect(inscriptionAcma('90EFP001', '2024-2025', 'FPTC4'))->credits_programme->toBe(60)->credits_acquis->toBe(60)
        ->moyenne_recalculee->toBe('12.75')->decision->toBe('admis')->moyenne_acma->toBe('13.500');
    // Rattrapage appliqué (ok = 1) ; note hors bornes écartée du calcul ; cohorte M2 2025-2026 sans décision.
    $m2 = inscriptionAcma('90EFP001', '2025-2026', 'AUCO5');
    expect($m2)->credits_programme->toBe(44)->credits_acquis->toBe(22)->nb_rattrapages->toBe(1)->moyenne_recalculee->toBe('9.50')
        ->decision->toBe('non_enregistree')->decision_motif->toBe('cohorte_sans_decision');
    expect(DB::table('acma_resultats_ue')->where('cle_source', 'n:1003')->first())->session->toBe('rattrapage')->moyenne->toBe('12.50')
        ->and(DB::table('acma_resultats_ue')->where('cle_source', 'n:1004')->first())->hors_bornes->toBeTrue()->cc->toBeNull()->cc_brut->toBe(25.0)->moyenne->toBe('6.50');
    // Rattrapage saisi mais non appliqué (ok = 0) : session normale.
    expect(DB::table('acma_resultats_ue')->where('cle_source', 'n:1005')->first())->session->toBe('normale')->moyenne->toBe('8.70');
    // M1 2022-2023 : admis dans ACMA, mais cohorte sans décision enregistrée (liste fermée, Q3).
    expect(inscriptionAcma('90EFP008', '2022-2023', 'FPTC4'))->admis_acma->toBeTrue()->decision->toBe('non_enregistree')->credits_acquis->toBe(60);
    expect(inscriptionAcma('90EFP015', '2025-2026', 'FPTC4'))->decision->toBe('non_enregistree')->decision_motif->toBe('sans_note');
    expect(inscriptionAcma('90EFP014', '2025-2026', 'FPTC4'))->decision->toBe('admis')->credits_acquis->toBe(60);
    expect(inscriptionAcma('90EFP013', '2025-2026', 'FPTC4'))->decision->toBe('non_admis')->moyenne_recalculee->toBe('11.93');

    // Versions : les notes de 2026-2027 et des fiches exclues ne sont pas reprises.
    expect(DB::table('acma_versions_notes')->count())->toBe(7)
        ->and(DB::table('acma_versions_notes')->where('id_prog_acma', 999)->value('unite_id'))->toBeNull()
        ->and(DB::table('acma_resultats_ue')->count())->toBe(15);
});

it('lie R1 et R2, laisse R3, R4 et R6 à arbitrer, liste R5 et produit le classeur des cas', function (): void {
    TransitAcma::construire();
    $r1 = auditeurAcma('90EFP001', 'ALPHA', 'Béta', '1985-03-12');
    $r2 = auditeurAcma('90EFP002', 'ESSAI', 'Gamma', '1990-05-05');
    $r4 = auditeurAcma('90EFP011', 'ESSAI', 'Epsilon', '1983-03-03');
    $r3 = auditeurAcma('90EFP012', 'MBARGA', 'Paul', '1984-04-04');
    $r6 = auditeurAcma('26EFP913', 'ESSAI', 'Theta Eta', '1986-06-06');
    auditeurAcma('26EFP914', 'ESSAI', 'Absent', '1991-01-01');

    $r = reprendre($this->usi);

    expect(dossierAcma('90EFP001'))->statut_rapprochement->toBe('lie_auto')->auditeur_id->toBe($r1->id)
        ->and(dossierAcma('90EFP002'))->statut_rapprochement->toBe('lie_auto')->auditeur_id->toBe($r2->id)
        ->and(dossierAcma('90EFP011'))->statut_rapprochement->toBe('a_arbitrer')->auditeur_id->toBeNull()
        ->and(dossierAcma('90EFP012'))->statut_rapprochement->toBe('a_arbitrer')->auditeur_id->toBeNull()
        ->and(dossierAcma('90EFP013'))->statut_rapprochement->toBe('a_arbitrer')->auditeur_id->toBeNull()
        ->and(dossierAcma('90EFP008')->statut_rapprochement)->toBe('sans_auditeur');
    expect(DB::table('acma_rapprochements')->orderBy('id')->get(['cas', 'auditeur_id', 'decision'])->map(fn ($x) => [$x->cas, $x->auditeur_id, $x->decision])->all())->toEqualCanonicalizing([
        ['R1', $r1->id, 'lie'], ['R2', $r2->id, 'lie'], ['R4', $r4->id, null], ['R3', $r3->id, null], ['R6', $r6->id, null],
    ]);
    expect($r['comptes']['rapprochement'])->toMatchArray(['R1' => 1, 'R2' => 1, 'R3' => 1, 'R4' => 1, 'R6' => 1, 'R5_absents_d_acma' => 2, 'actifs_2026_2027_hors_plateforme' => 2]);

    $classeur = IOFactory::load(Storage::disk('local')->path($r['classeur']));
    $aArbitrer = $classeur->getSheetByName('À arbitrer (R3, R4, R6)')->toArray();
    expect(array_column(array_slice($aArbitrer, 1), 0))->toEqualCanonicalizing(['R3', 'R4', 'R6'])
        ->and(collect($classeur->getSheetByName('Absents d\'ACMA (R5)')->toArray())->flatten()->all())->toContain('26EFP914')
        ->and(collect($classeur->getSheetByName('Fiches exclues')->toArray())->flatten()->all())->toContain('zz90EFP004')
        ->and(collect($classeur->getSheetByName('Actifs 2026-27 hors plateforme')->toArray())->flatten()->all())->toContain('26EFP905');
    // La plateforme n'est que lue.
    expect($r1->candidature->fresh()->nom)->toBe('ALPHA');
});

it('ne réécrit rien au second passage, met à jour et marque retiré sur un export modifié, garde les arbitrages', function (): void {
    TransitAcma::construire();
    $auditeur = auditeurAcma('90EFP011', 'ESSAI', 'Epsilon', '1983-03-03');
    reprendre($this->usi);
    $avant = DB::table('acma_dossiers')->orderBy('id')->get(['id', 'updated_at'])->all();

    $second = reprendre($this->usi);
    foreach (['annees', 'unites'] as $t) {
        expect($second['comptes']['referentiels'][$t])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'retirees' => 0]);
    }
    expect($second['comptes']['dossiers']['dossiers'])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'inchangees' => 15])
        ->and($second['comptes']['inscriptions']['inscriptions'])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'inchangees' => 17])
        ->and($second['comptes']['resultats']['resultats'])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'inchangees' => 15])
        ->and($second['comptes']['resultats']['inscriptions_recalculees'])->toBe(['modifiees' => 0, 'inchangees' => 17])
        ->and($second['comptes']['versions_notes']['versions'])->toMatchArray(['inserees' => 0, 'modifiees' => 0, 'inchangees' => 7])
        ->and($second['comptes']['rapprochement']['rapprochements_ajoutes'])->toBe(0)
        ->and(DB::table('acma_dossiers')->orderBy('id')->get(['id', 'updated_at'])->all())->toEqual($avant);

    // Arbitrage (15d le fera depuis l'écran) : lier malgré la date différente.
    DB::table('acma_rapprochements')->insert(['dossier_id' => dossierAcma('90EFP011')->id, 'auditeur_id' => $auditeur->id, 'cas' => 'R4', 'decision' => 'lie', 'motif' => 'Erreur de saisie de la date dans ACMA, pièce vue', 'decide_par' => $this->usi->id, 'created_at' => now()]);

    // Export plus récent : une note modifiée, une version disparue.
    TransitAcma::construireAvec(function (array &$lignes): void {
        $lignes['notes'][0]['exam'] = '15';
        array_shift($lignes['notes_hist']);
    });
    $troisieme = reprendre($this->usi);

    expect($troisieme['comptes']['resultats']['resultats'])->toMatchArray(['modifiees' => 1, 'inserees' => 0])
        ->and($troisieme['comptes']['versions_notes']['versions'])->toMatchArray(['retirees' => 1, 'inserees' => 0])
        ->and(DB::table('acma_versions_notes')->count())->toBe(7)
        ->and(DB::table('acma_versions_notes')->whereNotNull('retire_de_la_source_at')->count())->toBe(1)
        ->and(DB::table('acma_resultats_ue')->where('cle_source', 'n:1001')->value('examen'))->toBe('15.00')
        ->and(dossierAcma('90EFP011'))->statut_rapprochement->toBe('lie_arbitre')->auditeur_id->toBe($auditeur->id);
});

it('garde l\'archive en lecture seule hors de la reprise, et refuse toute écriture après le gel', function (): void {
    TransitAcma::construire();
    $auditeur = auditeurAcma('90EFP012', 'ESSAI', 'Zeta', '1984-04-04');
    reprendre($this->usi);

    refusee(fn () => DB::table('acma_unites')->update(['credits' => 1]), 'lecture seule');
    refusee(fn () => DB::table('acma_inscriptions')->delete(), 'lecture seule');
    refusee(fn () => DB::statement('TRUNCATE acma_resultats_ue CASCADE'), 'lecture seule');
    refusee(fn () => DB::table('acma_dossiers')->update(['nom_complet' => 'AUTRE']), 'lecture seule');
    refusee(fn () => DB::table('acma_reprises')->update(['statut' => 'gelee', 'gelee_at' => now()]), 'réservée aux commandes');
    refusee(fn () => DB::table('acma_reprises')->delete(), 'suppression interdite');
    $rapprochement = DB::table('acma_rapprochements')->value('id');
    refusee(fn () => DB::table('acma_rapprochements')->where('id', $rapprochement)->update(['motif' => 'Autre motif réécrit']), 'ajout seul');
    refusee(fn () => DB::table('acma_rapprochements')->where('id', $rapprochement)->delete(), 'ajout seul');

    // Le lien d'un dossier se modifie hors reprise (arbitrage), mais rien d'autre.
    DB::transaction(fn () => DB::table('acma_dossiers')->where('matricule', '90EFP012')->update(['auditeur_id' => $auditeur->id, 'statut_rapprochement' => 'lie_arbitre', 'updated_at' => now()]));
    expect(dossierAcma('90EFP012')->auditeur_id)->toBe($auditeur->id);

    // Gel (posé ici à la main ; acma:geler arrive avec 15c).
    DB::transaction(function (): void {
        RepriseAcma::mode(true);
        DB::table('acma_reprises')->where('statut', 'appliquee')->update(['statut' => 'gelee', 'gelee_at' => now()]);
        RepriseAcma::mode(false);
    });
    refusee(function (): void {
        RepriseAcma::mode(true);
        DB::table('acma_unites')->update(['credits' => 1]);
    }, 'gelée');
    refusee(function (): void {
        RepriseAcma::mode(true);
        DB::table('acma_reprises')->update(['statut' => 'appliquee', 'gelee_at' => null]);
    }, 'gelée');
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email, '--appliquer' => true])->expectsOutputToContain('Archive gelée le')->assertFailed();
    // Le lien reste modifiable après le gel.
    DB::transaction(fn () => DB::table('acma_dossiers')->where('matricule', '90EFP012')->update(['auditeur_id' => null, 'statut_rapprochement' => 'sans_auditeur']));
    expect(dossierAcma('90EFP012')->statut_rapprochement)->toBe('sans_auditeur');
});

it('ne touche pas au modèle vivant, n\'envoie rien, et annule tout si un contrôle bloquant échoue', function (): void {
    TransitAcma::construire();
    auditeurAcma('90EFP001', 'ALPHA', 'Béta', '1985-03-12');

    $r = reprendre($this->usi);
    expect(statutControle($r, 'C09'))->toBe('conforme')->and(statutControle($r, 'C10'))->toBe('conforme');
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    // Une étape qui écrirait dans le modèle vivant : C09 échoue, l'application est refusée, rien n'est écrit.
    TransitAcma::construireAvec(fn (array &$l) => $l['notes'][0]['exam'] = '16');
    app()->bind(EtapeVersionsNotes::class, fn () => new class implements Etape
    {
        public function nom(): string
        {
            return 'versions_notes';
        }

        public function executer(ContexteReprise $contexte): array
        {
            User::factory()->create();

            return [];
        }
    });
    $refus = reprendre($this->usi);

    expect($refus['statut'])->toBe('refusee')->and(statutControle($refus, 'C09'))->toBe('ÉCHEC')
        ->and(DB::table('acma_resultats_ue')->where('cle_source', 'n:1001')->value('examen'))->toBe('13.00')
        ->and(DB::table('acma_reprises')->orderBy('id')->pluck('statut')->all())->toBe(['appliquee', 'refusee']);
});

it('refuse sans transit, avec une étape inconnue ou un code de niveau inconnu, et contrôle l\'archive en lecture seule', function (): void {
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email])->expectsOutputToContain('Transit ACMA absent')->assertFailed();

    TransitAcma::construireAvec(fn (array &$l) => $l['programe'][0]['codniveau'] = 'XXXX5');
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email])->expectsOutputToContain('Codes de niveau ACMA inconnus : XXXX5')->assertFailed();

    TransitAcma::construire();
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email, '--etapes' => 'dossiers,inconnue'])->expectsOutputToContain('Étapes inconnues : inconnue')->assertFailed();
    $this->artisan('acma:reprendre', ['--auteur' => $this->usi->email, '--appliquer' => true])->assertSuccessful();

    $this->artisan('acma:controler')->expectsOutputToContain('Aucun contrôle bloquant en échec')->assertSuccessful();
    expect(DB::table('acma_reprises')->count())->toBe(1);
});

it('se défait et se refait, mais refuse d\'être défaite une fois l\'archive gelée', function (): void {
    $migration = require database_path('migrations/2026_12_04_100000_create_acma_archive_academique_tables.php');

    $migration->down();
    expect(Schema::hasTable('acma_dossiers'))->toBeFalse()->and(Schema::hasTable('acma_reprises'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('acma_versions_notes'))->toBeTrue();

    TransitAcma::construire();
    reprendre($this->usi);
    DB::transaction(function (): void {
        RepriseAcma::mode(true);
        DB::table('acma_reprises')->update(['statut' => 'gelee', 'gelee_at' => now()]);
        RepriseAcma::mode(false);
    });
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Archive ACMA gelée');
});
