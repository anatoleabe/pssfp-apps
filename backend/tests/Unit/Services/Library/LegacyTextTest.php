<?php

declare(strict_types=1);

use App\Services\Library\Import\LegacyText;
use App\Services\Library\Import\TitleLexicon;

uses()->group('module-3', 'library', 'biblio-import');

describe('title', function (): void {
    it('décode les entités, retire l\'extension .pdf et normalise les espaces', function (string $raw, string $expected): void {
        expect(LegacyText::title($raw))->toBe($expected);
    })->with([
        'entités' => ['Rapport d&rsquo;ex&eacute;cution &amp; bilan', "Rapport d'exécution & bilan"],
        'double encodage' => ['Guide &amp;eacute;tudiant', 'Guide étudiant'],
        'extension' => ['Note de service.PDF', 'Note de service'],
        'extension et copie' => ['Nomenclature des comptes 2019 pdf 1', 'Nomenclature des comptes 2019'],
        'espaces' => ["  Loi\u{00A0}de   finances\t2020 ", 'Loi de finances 2020'],
        'soulignés' => ['Guide_didactique_RGCP', 'Guide didactique RGCP'],
        'balises' => ['<b>Annuaire</b> statistique', 'Annuaire statistique'],
        'artefacts de téléversement' => ['Guide de gestion cle0a1b2c 1  cle3d4e5f', 'Guide de gestion'],
        'casse mixte conservée' => ['Bilan du PNUD-CAMEROUN sur l’Aide publique', "Bilan du PNUD-CAMEROUN sur l'Aide publique"],
        'première lettre' => ['décret du Premier ministre', 'Décret du Premier ministre'],
        'pdf collé' => ['Coûts variables rentabilitepdf', 'Coûts variables rentabilite'],
    ]);

    it('met en casse phrase un titre tout en majuscules en gardant les sigles', function (string $raw, string $expected): void {
        expect(LegacyText::title($raw))->toBe($expected);
    })->with([
        'sigles listés' => ['CIRCULAIRE MINFI RELATIVE A LA TVA EN ZONE CEMAC', 'Circulaire MINFI relative à la TVA en zone CEMAC'],
        'OHADA et BEAC' => ['ACTE UNIFORME OHADA ET REGLEMENT BEAC', 'Acte uniforme OHADA et règlement BEAC'],
        'FMI BAD BM' => ['RAPPORT CONJOINT FMI BAD BM', 'Rapport conjoint FMI BAD BM'],
        'PRC MINEPAT LMD' => ['DECRET PRC MINEPAT SUR LE SYSTEME LMD', 'Décret PRC MINEPAT sur le système LMD'],
        'UEMOA CEEAC PPP' => ['LES PPP EN ZONE UEMOA ET CEEAC', 'Les PPP en zone UEMOA et CEEAC'],
        'sigle sans voyelle' => ['LE CDMT DES CTD', 'Le CDMT des CTD'],
        'accents restitués' => ['ARRETE FIXANT LES MODALITES DE CONTROLE DES DEPENSES', 'Arrêté fixant les modalités de contrôle des dépenses'],
        'élisions recollées' => ['LEXECUTION DU BUDGET DE LETAT', "L'exécution du budget de l'État"],
        'élision avec sigle' => ['RAPPORT DE LOCDE', "Rapport de l'OCDE"],
        'apostrophe existante' => ['LA PLACE DE L\'AUDIT DANS L’ETAT', "La place de l'audit dans l'État"],
        'numéro de texte' => ['DECRET N 2010 0999 PM DU 26 FEVRIER 2010', 'Décret n° 2010 0999 PM du 26 février 2010'],
        'date collée' => ['ARRETE DU 19 JUILLET 2012PORTANT REPARTITION', 'Arrêté du 19 juillet 2012 portant répartition'],
        'noms propres' => ['LA DECENTRALISATION AU CAMEROUN ET EN AFRIQUE', 'La décentralisation au Cameroun et en Afrique'],
        'chiffres romains' => ['TITRE III DU CODE GENERAL', 'Titre III du code général'],
        'mise en œuvre' => ['MISE EN OEUVRE DU BUDGET-PROGRAMME', 'Mise en œuvre du budget-programme'],
        'tirets' => ['ARRETE CONJOINT MINAT-MINVIL', 'Arrêté conjoint MINAT-MINVIL'],
        'majuscules accentuées' => ['CONTRÔLE HIERARCHISE ET AUDIT ALLEGE', 'Contrôle hiérarchisé et audit allégé'],
        'anglais' => ['A BASIC MODEL OF THE BUDGET FOR CAMEROON', 'A basic model of the budget for Cameroon'],
        'sigle seul' => ['MINFI', 'MINFI'],
        'cote après un nombre' => ['ARRETE N 00999 A MINATD DU 24 AOUT 2009', 'Arrêté n° 00999 A MINATD du 24 août 2009'],
        'romain seul après titre' => ['TITRE V DU CODE GENERAL', 'Titre V du code général'],
        'romain seul après livre' => ['LIVRE I', 'Livre I'],
        'romain seul après un numéro' => ['TOME 2 PARTIE X ANNEXE I', 'Tome 2 partie X annexe I'],
        'sigles à voyelles' => ['RAPPORT UNESCO UNICEF FAO OMS SUR LES PME ET ONG', 'Rapport UNESCO UNICEF FAO OMS sur les PME et ONG'],
        'monnaies et sigles' => ['TAUX USD EUR XAF ET IRPP CIMA', 'Taux USD EUR XAF et IRPP CIMA'],
        'IS dans un titre français' => ['REGIME DE L IS ET DE LA TVA', "Régime de l'IS et de la TVA"],
        'is dans un titre anglais' => ['WHAT IS PUBLIC DEBT', 'What is public debt'],
        'émission élidée' => ['MODALITES DEMISSION DE RECOUVREMENT', "Modalités d'émission de recouvrement"],
        'évaluation élidée' => ['CRITERES DEVALUATION DES POLITIQUES', "Critères d'évaluation des politiques"],
        'apostrophe devenue espace' => ['BUDGETISATION DE L AIDE ET D ALLOCATION', "Budgétisation de l'aide et d'allocation"],
    ]);

    it('ne lit jamais une élision dans les mots exclus', function (string $word): void {
        expect(LegacyText::title('NOTE '.mb_strtoupper($word)))->not->toContain("'");
    })->with(TitleLexicon::ELISION_EXCEPTIONS);

    it('ne touche pas un titre déjà en casse mixte', function (): void {
        expect(LegacyText::title('The State of Public Finance in Central Africa'))
            ->toBe('The State of Public Finance in Central Africa');
    });

    it('rend une chaîne vide pour un titre vide', function (): void {
        expect(LegacyText::title(' &nbsp; .pdf '))->toBe('');
    });
});

describe('year', function (): void {
    it('préfère l\'année déclarée quand elle est plausible', function (): void {
        expect(LegacyText::year(2009, 'Loi de finances 2015'))->toBe(2009)
            ->and(LegacyText::year(1900, 'x'))->toBe(1900)
            ->and(LegacyText::year(2100, 'x'))->toBe(2100);
    });

    it('ignore une année déclarée hors de 1900–2100', function (?int $declared): void {
        expect(LegacyText::year($declared, 'Rapport annuel 2014'))->toBe(2014);
    })->with([0, 1899, 2101, null]);

    it('lit l\'année dans le titre', function (string $title, ?int $expected): void {
        expect(LegacyText::year(null, $title, 2026))->toBe($expected);
    })->with([
        'dernière occurrence plausible' => ['Stratégie 2010-2020', 2020],
        'date du texte prioritaire' => ['Loi n° 2008 999 du 14 avril 2008 modifiant la loi du 18 janvier 1996', 2008],
        'premier jour' => ['Décret du 1er janvier 2015 complétant le décret de 1994', 2015],
        'mois abrégé' => ['Circulaire du 30 déc 2022 portant instructions', 2022],
        'horizon futur écarté' => ['Cameroun Vision 2035 : document de 2009', 2009],
        'année suivante admise' => ['Loi de finances 2027', 2027],
        'nombre à cinq chiffres' => ['Arrêté n° 00073 et 20150', null],
        'avant 1900' => ['Traité de 1884', null],
        'aucune' => ['Guide didactique', null],
        'numéro de texte' => ['Décret n° 2011/0976/PM fixant la dotation', 2011],
        'montant en FCFA écarté' => ['Budget de 2500 FCFA par habitant en 2014', 2014],
        'montant en F CFA' => ['Seuil de 2000 F CFA', null],
        'montant en francs' => ['Prime de 1000 francs', null],
        'montant en F' => ['Timbre de 1500 F', null],
        'milliards' => ['Budget 2019 de 1900 milliards', 2019],
        'millions et mds' => ['Dette de 2010 millions et 2020 mds', null],
        'année suivie d\'un mot en F' => ['Loi de finances 2015 Fiscalité', 2015],
    ]);
});

describe('language', function (): void {
    it('détecte l\'anglais seulement sans mot français courant', function (string $title, string $expected): void {
        expect(LegacyText::language($title))->toBe($expected);
    })->with([
        'anglais' => ['The State of Public Finance in Central Africa', 'en'],
        'anglais en majuscules' => ['PUBLIC FINANCIAL MANAGEMENT IN AFRICA', 'en'],
        'mots métier anglais' => ['Budgeting for results', 'en'],
        'deux mots courants' => ['Taxation and revenue of the State', 'en'],
        'un seul mot anglais' => ['Budget of Cameroon', 'fr'],
        'mélange' => ['Public Financial Management : le cas du Cameroun', 'fr'],
        'français' => ['Ordonnance n° 99 OF livre premier dispositions diverses', 'fr'],
        'vide' => ['', 'fr'],
        'mot répété compté une fois' => ['Service public secteur public', 'fr'],
        'marqueurs faibles seuls' => ['Management public', 'fr'],
        'trois marqueurs faibles' => ['Public management control', 'fr'],
        'un fort et deux faibles' => ['Public management in Africa', 'en'],
        'un fort et un faible' => ['State of Africa', 'fr'],
        'deux forts dont un mot métier' => ['Fraud risk management', 'en'],
    ]);
});

describe('abstract', function (): void {
    it('convertit le HTML Joomla en texte en gardant les paragraphes', function (): void {
        $html = "<h2>Objet</h2><p>Premier&nbsp;paragraphe sur l&rsquo;ex&eacute;cution.</p>\n<p>Second <b>paragraphe</b><br>avec retour.</p><ul><li>un</li><li>deux</li></ul>";

        expect(LegacyText::abstract($html))->toBe("Objet\n\nPremier paragraphe sur l’exécution.\n\nSecond paragraphe\navec retour.\n\nun\ndeux");
    });

    it('rend null pour une description vide', function (?string $html): void {
        expect(LegacyText::abstract($html))->toBeNull();
    })->with([null, '', '   ', '<p>&nbsp;</p>', '<p><br /></p>']);

    it('retire scripts et styles', function (): void {
        expect(LegacyText::abstract('<style>p{}</style><script>alert(1)</script><p>Texte</p>'))->toBe('Texte');
    });
});

describe('slug', function (): void {
    it('reprend l\'alias Joomla s\'il est utilisable', function (): void {
        expect(LegacyText::slug('revue-de-l-ocde-sur-les-finances-locales', 'ignoré'))->toBe('revue-de-l-ocde-sur-les-finances-locales');
    });

    it('rend un slug conforme', function (string $alias, string $title, string $expected): void {
        expect(LegacyText::slug($alias, $title))->toBe($expected);
    })->with([
        'alias à nettoyer' => ['Arrêté_N°12--MINFI ', 'x', 'arrete-n-12-minfi'],
        'alias vide' => ['', "Loi n° 2018/012 de l'État", 'loi-n-2018-012-de-l-etat'],
        'alias sans lettre ni chiffre' => ['---', 'Décret', 'decret'],
        'rien d\'utilisable' => ['', '…', 'document'],
    ]);

    it('tronque à 160 caractères sur une limite de mot', function (): void {
        $slug = LegacyText::slug(str_repeat('mot-', 60), 'x');

        expect(mb_strlen($slug))->toBeLessThanOrEqual(160)
            ->and($slug)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and($slug)->toEndWith('mot');
    });

    it('accepte une longueur maximale plus courte', function (): void {
        $slug = LegacyText::slug('un-tres-long-alias-de-document', 'x', 12);

        expect($slug)->toBe('un-tres-long');
    });

    it('coupe un mot unique trop long', function (): void {
        expect(LegacyText::slug(str_repeat('a', 200), 'x'))->toBe(str_repeat('a', 160));
    });
});

describe('lowercasedUppercaseWords', function (): void {
    it('liste les mots criés remis en minuscules dans au moins 3 titres', function (): void {
        $titles = [
            'RAPPORT DU ZOBAN', 'NOTE ZOBAN 2019', 'AVIS DU ZOBAN', 'NOTE DE SERVICE',
            'Guide de la note', 'KADU UN', 'KADU DEUX', 'ZOBAN MINFI', 'MINFI ET TVA', 'TVA ET MINFI',
        ];

        expect(LegacyText::lowercasedUppercaseWords($titles))->toBe(['ZOBAN' => 4]);
    });

    it('accepte un autre seuil', function (): void {
        expect(LegacyText::lowercasedUppercaseWords(['KADU UN', 'KADU DEUX'], 2))->toBe(['KADU' => 2]);
    });
});

describe('TitleLexicon', function (): void {
    it('déclare FRENCH_WORDS trié, sans clé en double', function (): void {
        $source = (string) file_get_contents((string) (new ReflectionClass(TitleLexicon::class))->getFileName());
        preg_match('/const FRENCH_WORDS = \[(.*?)\n    \];/s', $source, $block);
        preg_match_all("/'([^']+)'\s*=>/", $block[1] ?? '', $keys);
        $sorted = $keys[1];
        sort($sorted, SORT_STRING);

        expect($keys[1])->not->toBeEmpty()
            ->and(count($keys[1]))->toBe(count(array_unique($keys[1])))
            ->and($keys[1])->toBe($sorted)
            ->and(count($keys[1]))->toBe(count(TitleLexicon::FRENCH_WORDS));
    });
});
