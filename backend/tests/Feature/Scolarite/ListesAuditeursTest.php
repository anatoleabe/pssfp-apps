<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\CriteresListe;
use App\Services\Scolarite\ListesAuditeurs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses()->group('scolarite');

const LISTES_FFCP = 'Fiscalité, Finance et Comptabilité Publique';
const LISTES_MPPP = 'Marchés Publics et Partenariats Public-Privés';

/*
 * Jeu construit (barème M1 : 185 000 + 500 000 + 500 000 ; M2 : 135 000 + 500 000 + 500 000) :
 *
 *   FFCP  ABENA    M1  versé 1 185 000              soldé
 *   FFCP  BEKONO   M1  versé   185 000              en retard (1re tranche échue)
 *   FFCP  CHOUPO   M2  remise 135 000, rien versé   inscription non soldée
 *   FFCP  GWET     —   sans inscription             barème manquant
 *   MPPP  DIBANGO  M1  versé   685 000              à jour
 *   MPPP  ESSOMBA  M2  versé 1 135 000              soldé
 *   MPPP  FOUDA    M1  exclu
 *   P13   HOUNKPE  M1  autre promotion
 *   FFCP  IKWA     M1  versé   185 000              dossier retiré (hors liste par défaut)
 */
beforeEach(function (): void {
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    $this->anterieure = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
    $this->baremes = [
        'M1' => BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2020-01-01']),
        'M2' => BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']),
        'P13' => BaremeScolarite::factory()->create(['annee_academique' => '2025-2026', 'niveau' => 'M1']),
    ];
    $this->rang = 0;
    $this->auditeur = function (string $nom, string $specialite, ?string $niveau, int $verse = 0, int $remise = 0, string $statut = Auditeur::STATUT_ACTIF, ?CampagneCandidature $campagne = null): Auditeur {
        $n = ++$this->rang;
        $c = Candidature::factory()->forCampagne($campagne ?? $this->campagne)->submitted()->create([
            'nom' => $nom, 'prenom' => 'Essai', 'specialite' => $specialite, 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+23769955'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'email' => 'auditeur'.$n.'@example.org',
        ]);
        $c->forceFill(['matricule' => '26TST'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'rang_retenu' => $n, 'matricule_attribue_at' => now()])->save();
        $a = Auditeur::query()->create(['candidature_id' => $c->id]);
        $a->forceFill(['statut' => $statut])->save();
        if ($niveau !== null) {
            $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $a->id, 'bareme_id' => $this->baremes[$niveau]->id]);
            $i->forceFill(['remise_montant' => $remise])->save();
            if ($verse > 0) {
                Versement::factory()->create(['inscription_annuelle_id' => $i->id, 'montant' => $verse]);
            }
        }

        return $a;
    };
    ($this->auditeur)('Abena', LISTES_FFCP, 'M1', 1185000);
    ($this->auditeur)('Bekono', LISTES_FFCP, 'M1', 185000);
    ($this->auditeur)('Choupo', LISTES_FFCP, 'M2', 0, 135000);
    ($this->auditeur)('Gwet', LISTES_FFCP, null);
    ($this->auditeur)('Dibango', LISTES_MPPP, 'M1', 685000);
    ($this->auditeur)('Essomba', LISTES_MPPP, 'M2', 1135000);
    ($this->auditeur)('Fouda', LISTES_MPPP, 'M1', 0, 0, Auditeur::STATUT_EXCLU);
    $p13 = ($this->auditeur)('Hounkpe', LISTES_FFCP, null, 0, 0, Auditeur::STATUT_ACTIF, $this->anterieure);
    InscriptionAnnuelle::factory()->create(['auditeur_id' => $p13->id, 'bareme_id' => $this->baremes['P13']->id]);
    ($this->auditeur)('Ikwa', LISTES_FFCP, 'M1', 185000)->candidature->forceFill(['withdrawn_at' => now()])->save();

    $this->criteres = fn (array $donnees = []): CriteresListe => CriteresListe::depuis($donnees + ['campagne_id' => $this->campagne->id]);
    $this->noms = fn (array $tableau): array => collect($tableau['groupes'])->flatMap(fn (array $g) => array_column($g['lignes'], 'nom'))->all();
    $this->listes = fn (): ListesAuditeurs => app(ListesAuditeurs::class);
});

it('liste par défaut les auditeurs actifs de la promotion, par spécialité, dans l\'ordre alphabétique', function (): void {
    $tableau = ($this->listes)()->tableau(($this->criteres)());

    expect(array_column($tableau['groupes'], 'titre'))->toBe([LISTES_FFCP, LISTES_MPPP])
        ->and(array_column($tableau['groupes'][0]['lignes'], 'nom'))->toBe(['ABENA Essai', 'BEKONO Essai', 'CHOUPO Essai', 'GWET Essai'])
        ->and(array_column($tableau['groupes'][1]['lignes'], 'nom'))->toBe(['DIBANGO Essai', 'ESSOMBA Essai'])
        ->and($tableau['totaux']['effectif'])->toBe(6);
});

it('calcule les sous-totaux par spécialité et le total général', function (): void {
    $tableau = ($this->listes)()->tableau(($this->criteres)());

    expect($tableau['groupes'][0]['totaux'])->toBe(['effectif' => 4, 'du' => 3370000, 'remise' => 135000, 'verse' => 1370000, 'a_confirmer' => 0, 'reste' => 2000000, 'avance' => 0])
        ->and($tableau['groupes'][1]['totaux'])->toBe(['effectif' => 2, 'du' => 2320000, 'remise' => 0, 'verse' => 1820000, 'a_confirmer' => 0, 'reste' => 500000, 'avance' => 0])
        ->and($tableau['totaux'])->toBe(['effectif' => 6, 'du' => 5690000, 'remise' => 135000, 'verse' => 3190000, 'a_confirmer' => 0, 'reste' => 2500000, 'avance' => 0]);
});

it('regroupe par niveau, les auditeurs sans inscription à part', function (): void {
    $tableau = ($this->listes)()->tableau(($this->criteres)(['regroupement' => CriteresListe::PAR_NIVEAU]));

    expect(array_column($tableau['groupes'], 'titre'))->toBe(['Master 1', 'Master 2', 'Sans inscription (barème manquant)'])
        ->and($tableau['groupes'][0]['totaux'])->toBe(['effectif' => 3, 'du' => 3555000, 'remise' => 0, 'verse' => 2055000, 'a_confirmer' => 0, 'reste' => 1500000, 'avance' => 0])
        ->and($tableau['groupes'][1]['totaux'])->toBe(['effectif' => 2, 'du' => 2135000, 'remise' => 135000, 'verse' => 1135000, 'a_confirmer' => 0, 'reste' => 1000000, 'avance' => 0])
        ->and($tableau['groupes'][2]['totaux'])->toBe(['effectif' => 1, 'du' => 0, 'remise' => 0, 'verse' => 0, 'a_confirmer' => 0, 'reste' => 0, 'avance' => 0])
        ->and($tableau['totaux']['reste'])->toBe(2500000);
});

it('présente une seule liste sans regroupement', function (): void {
    $tableau = ($this->listes)()->tableau(($this->criteres)(['regroupement' => CriteresListe::SANS_REGROUPEMENT]));

    expect($tableau['groupes'])->toHaveCount(1)
        ->and($tableau['groupes'][0]['titre'])->toBeNull()
        ->and(($this->noms)($tableau))->toBe(['ABENA Essai', 'BEKONO Essai', 'CHOUPO Essai', 'DIBANGO Essai', 'ESSOMBA Essai', 'GWET Essai']);
});

it('filtre par niveau, spécialité, statut et promotion', function (array $donnees, array $attendus): void {
    expect(($this->noms)(($this->listes)()->tableau(($this->criteres)($donnees + ['regroupement' => CriteresListe::SANS_REGROUPEMENT]))))->toBe($attendus);
})->with([
    'niveau M2' => [['niveau' => 'M2'], ['CHOUPO Essai', 'ESSOMBA Essai']],
    'spécialité' => [['specialite' => LISTES_MPPP], ['DIBANGO Essai', 'ESSOMBA Essai']],
    'niveau et spécialité' => [['niveau' => 'M1', 'specialite' => LISTES_FFCP], ['ABENA Essai', 'BEKONO Essai']],
    'exclus en plus' => [['statuts' => [Auditeur::STATUT_ACTIF, Auditeur::STATUT_EXCLU], 'specialite' => LISTES_MPPP], ['DIBANGO Essai', 'ESSOMBA Essai', 'FOUDA Essai']],
    'exclus seuls' => [['statuts' => [Auditeur::STATUT_EXCLU]], ['FOUDA Essai']],
]);

it('ne mélange pas les promotions', function (): void {
    $tableau = ($this->listes)()->tableau(CriteresListe::depuis(['campagne_id' => $this->anterieure->id]));

    expect(($this->noms)($tableau))->toBe(['HOUNKPE Essai']);
});

it('filtre par situation, avec la même logique que la liste des auditeurs', function (string $situation, array $attendus): void {
    $tableau = ($this->listes)()->tableau(($this->criteres)(['situation' => $situation, 'regroupement' => CriteresListe::SANS_REGROUPEMENT]));

    expect(($this->noms)($tableau))->toBe($attendus);
})->with([
    'soldé' => [CriteresListe::SOLDE, ['ABENA Essai', 'ESSOMBA Essai']],
    'non soldé' => [CriteresListe::NON_SOLDE, ['BEKONO Essai', 'CHOUPO Essai', 'DIBANGO Essai']],
    'en retard' => [CriteresListe::EN_RETARD, ['BEKONO Essai']],
]);

it('donne à chaque ligne ses montants et le libellé de sa situation', function (): void {
    $lignes = collect(($this->listes)()->tableau(($this->criteres)())['groupes'])->flatMap(fn (array $g) => $g['lignes'])->keyBy('nom');

    expect($lignes['BEKONO Essai'])->toMatchArray(['niveau' => 'M1', 'specialite' => LISTES_FFCP, 'du' => 1185000, 'remise' => 0, 'verse' => 185000, 'reste' => 1000000, 'situation' => 'En retard', 'statut' => 'Actif'])
        ->and($lignes['CHOUPO Essai'])->toMatchArray(['du' => 1000000, 'remise' => 135000, 'verse' => 0, 'reste' => 1000000, 'situation' => 'Inscription non soldée'])
        ->and($lignes['GWET Essai'])->toMatchArray(['niveau' => null, 'du' => null, 'reste' => null, 'situation' => 'Barème manquant'])
        ->and($lignes['ABENA Essai']['matricule'])->toBe('26TST0001');
});

it('montre « Versé » imputé à l\'année et l\'avance du compte dans sa propre colonne, avec ses totaux', function (): void {
    ($this->auditeur)('Kamga', LISTES_MPPP, 'M1', 1300000);
    $criteres = ($this->criteres)(['regroupement' => CriteresListe::SANS_REGROUPEMENT]);
    $tableau = ($this->listes)()->tableau($criteres);
    $kamga = collect($tableau['groupes'][0]['lignes'])->firstWhere('nom', 'KAMGA Essai');

    expect($kamga)->toMatchArray(['du' => 1185000, 'verse' => 1185000, 'reste' => 0, 'avance' => 115000, 'situation' => 'Soldé'])
        ->and($tableau['totaux']['avance'])->toBe(115000)
        ->and($tableau['totaux']['verse'])->toBe(3190000 + 1185000)
        ->and(($this->listes)()->html(ListesAuditeurs::SITUATION_FINANCIERE, $criteres))
        ->toMatch('/<th class="n">Reste<\/th><th class="n">Avance<\/th>/u')
        ->toMatch('/Total général[^<]*<\/td>\s*<td[^>]*>7 auditeurs<\/td>(\s*<td[^>]*>[^<]*<\/td>){4}\s*<td[^>]*>115 000 FCFA<\/td>/u');
});

it('charge la liste en un nombre fixe de requêtes, quel que soit l\'effectif', function (): void {
    $compter = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ($this->listes)()->tableau(($this->criteres)());
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $avant = $compter();
    foreach (['Kamga', 'Lobe', 'Mbarga', 'Nkoulou'] as $nom) {
        ($this->auditeur)($nom, LISTES_MPPP, 'M1', 1300000);
    }

    expect($compter())->toBe($avant);
});

it('met en page la situation financière avec l\'en-tête du PSSFP, les sous-totaux et le total général', function (): void {
    $html = ($this->listes)()->html(ListesAuditeurs::SITUATION_FINANCIERE, ($this->criteres)());

    expect($html)->toContain('REPUBLIQUE DU CAMEROUN')
        ->toContain('data:image/png;base64,')
        ->toContain('Situation financière des auditeurs')
        ->toContain('14e promotion (2026-2027)')
        ->toContain('Document interne')
        ->toContain('heure de Yaoundé')
        ->toContain('1 185 000 FCFA')
        ->toMatch('/Sous-total[^<]*<\/td>\s*<td[^>]*>4 auditeurs<\/td>\s*<td[^>]*>3 370 000 FCFA<\/td>\s*<td[^>]*>135 000 FCFA<\/td>\s*<td[^>]*>1 370 000 FCFA<\/td>\s*<td[^>]*>2 000 000 FCFA<\/td>/u')
        ->toMatch('/Total général[^<]*<\/td>\s*<td[^>]*>6 auditeurs<\/td>\s*<td[^>]*>5 690 000 FCFA<\/td>\s*<td[^>]*>135 000 FCFA<\/td>\s*<td[^>]*>3 190 000 FCFA<\/td>\s*<td[^>]*>2 500 000 FCFA<\/td>/u')
        ->not->toContain('+23769955')
        ->not->toContain('@example.org');
});

it('rappelle les critères appliqués en tête de liste', function (): void {
    $html = ($this->listes)()->html(ListesAuditeurs::NOMINATIVE, ($this->criteres)([
        'niveau' => 'M1', 'specialite' => LISTES_MPPP, 'situation' => CriteresListe::NON_SOLDE,
        'statuts' => [Auditeur::STATUT_ACTIF, Auditeur::STATUT_ABANDON], 'regroupement' => CriteresListe::PAR_NIVEAU,
    ]));

    expect($html)->toContain('Master 1')
        ->toContain(e(LISTES_MPPP))
        ->toContain('Non soldé')
        ->toContain('Actif, Abandon')
        ->toContain('par niveau');
});

it('édite une liste nominative sans téléphone, sans e-mail et sans montant', function (): void {
    $html = ($this->listes)()->html(ListesAuditeurs::NOMINATIVE, ($this->criteres)());

    expect($html)->toContain('Liste nominative des auditeurs')
        ->toContain('REPUBLIQUE DU CAMEROUN')
        ->toContain('26TST0001')
        ->toContain('ABENA Essai')
        ->toContain('Actif')
        ->not->toContain('+23769955')
        ->not->toContain('699 55')
        ->not->toContain('@example.org')
        ->not->toContain('FCFA');
});

it('produit les deux listes en PDF', function (string $liste): void {
    $pdf = ($this->listes)()->editer($liste, ($this->criteres)(), ($this->compte)('scolarite_agent'));

    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->with([ListesAuditeurs::SITUATION_FINANCIERE, ListesAuditeurs::NOMINATIVE]);

it('journalise l\'édition avec les critères, sans aucune donnée nominative', function (): void {
    $agent = ($this->compte)('scolarite_agent');

    ($this->listes)()->editer(ListesAuditeurs::SITUATION_FINANCIERE, ($this->criteres)(['specialite' => LISTES_FFCP]), $agent);

    $journal = Activity::query()->where('event', 'liste_editee')->sole();
    expect($journal->log_name)->toBe('scolarite')
        ->and($journal->causer_id)->toBe($agent->id)
        ->and($journal->properties->toArray())->toMatchArray([
            'liste' => ListesAuditeurs::SITUATION_FINANCIERE, 'campagne_id' => $this->campagne->id, 'promotion' => 14,
            'niveau' => null, 'specialite' => LISTES_FFCP, 'situation' => CriteresListe::TOUTES,
            'statuts' => [Auditeur::STATUT_ACTIF], 'regroupement' => CriteresListe::PAR_SPECIALITE, 'inclure_retires' => false, 'lignes' => 4,
        ]);
    $brut = json_encode($journal->toArray(), JSON_UNESCAPED_UNICODE);
    foreach (['Abena', 'ABENA', 'Bekono', 'Essai', '26TST', '+23769955', '@example.org'] as $nominatif) {
        expect($brut)->not->toContain($nominatif);
    }
});

it('refuse l\'édition à qui n\'a pas le droit d\'exporter', function (): void {
    $agent = ($this->compte)('scolarite_agent');
    $agent->syncRoles([]);
    $agent->givePermissionTo(['view_any_auditeur', 'view_auditeur']);

    expect(fn () => ($this->listes)()->editer(ListesAuditeurs::NOMINATIVE, ($this->criteres)(), $agent->fresh()))
        ->toThrow(HttpException::class);
    expect(Activity::query()->where('event', 'liste_editee')->exists())->toBeFalse();
});

it('refuse des critères hors liste', function (array $donnees): void {
    expect(fn () => CriteresListe::depuis($donnees + ['campagne_id' => $this->campagne->id]))->toThrow(InvalidArgumentException::class);
})->with([
    'sans promotion' => [['campagne_id' => null]],
    'promotion inconnue' => [['campagne_id' => 999999]],
    'niveau inconnu' => [['niveau' => 'M3']],
    'situation inconnue' => [['situation' => 'peut-etre']],
    'statut inconnu' => [['statuts' => ['fantome']]],
    'aucun statut' => [['statuts' => []]],
    'regroupement inconnu' => [['regroupement' => 'par_ville']],
    'case des dossiers retirés illisible' => [['inclure_retires' => 'peut-etre']],
]);

it('écarte par défaut les dossiers retirés, de la liste comme de l\'effectif', function (): void {
    $criteres = ($this->criteres)(['regroupement' => CriteresListe::SANS_REGROUPEMENT]);

    expect(($this->noms)(($this->listes)()->tableau($criteres)))->not->toContain('IKWA Essai')
        ->and(($this->listes)()->effectif($criteres))->toBe(6)
        ->and(($this->listes)()->effectif(($this->criteres)(['situation' => CriteresListe::EN_RETARD])))->toBe(1);
});

it('réintègre les dossiers retirés quand la case est cochée, en les signalant', function (): void {
    $criteres = ($this->criteres)(['inclure_retires' => true, 'regroupement' => CriteresListe::SANS_REGROUPEMENT]);
    $tableau = ($this->listes)()->tableau($criteres);
    $lignes = collect($tableau['groupes'][0]['lignes'])->keyBy('nom');

    expect(($this->noms)($tableau))->toBe(['ABENA Essai', 'BEKONO Essai', 'CHOUPO Essai', 'DIBANGO Essai', 'ESSOMBA Essai', 'GWET Essai', 'IKWA Essai'])
        ->and(($this->listes)()->effectif($criteres))->toBe(7)
        ->and($tableau['totaux'])->toBe(['effectif' => 7, 'du' => 6875000, 'remise' => 135000, 'verse' => 3375000, 'a_confirmer' => 0, 'reste' => 3500000, 'avance' => 0])
        ->and($lignes['IKWA Essai']['statut'])->toBe('Actif (dossier retiré)')
        ->and($lignes['ABENA Essai']['statut'])->toBe('Actif');
});

it('rappelle en tête de liste si les dossiers retirés sont exclus ou inclus', function (bool $inclure, string $libelle): void {
    $html = ($this->listes)()->html(ListesAuditeurs::NOMINATIVE, ($this->criteres)(['inclure_retires' => $inclure]));

    expect($html)->toContain('Dossiers retirés&nbsp;:</span> <strong>'.$libelle.'</strong>');
})->with([
    'par défaut' => [false, 'exclus'],
    'case cochée' => [true, 'inclus'],
]);

it('journalise l\'inclusion des dossiers retirés, toujours sans donnée nominative', function (): void {
    ($this->listes)()->editer(ListesAuditeurs::NOMINATIVE, ($this->criteres)(['inclure_retires' => true, 'specialite' => LISTES_FFCP]), ($this->compte)('scolarite_agent'));

    $journal = Activity::query()->where('event', 'liste_editee')->sole();
    expect($journal->properties['inclure_retires'])->toBeTrue()
        ->and($journal->properties['lignes'])->toBe(5)
        ->and(json_encode($journal->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('Ikwa')->not->toContain('IKWA');
});

it('propose les promotions qui ont des auditeurs et les spécialités de la promotion choisie', function (): void {
    CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2027-2028'], 'promotion_numero' => 15]);

    expect(ListesAuditeurs::promotions())->toBe([
        $this->campagne->id => '14e promotion (2026-2027)',
        $this->anterieure->id => '13e promotion (2025-2026)',
    ])
        ->and(ListesAuditeurs::specialites($this->campagne->id))->toBe([LISTES_FFCP => LISTES_FFCP, LISTES_MPPP => LISTES_MPPP])
        ->and(ListesAuditeurs::specialites($this->anterieure->id))->toBe([LISTES_FFCP => LISTES_FFCP])
        ->and(ListesAuditeurs::specialites(null))->toBe([]);
});
