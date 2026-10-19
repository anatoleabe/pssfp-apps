<?php

declare(strict_types=1);

use App\Models\Document;
use App\Services\Library\Import\KeywordRef;
use App\Services\Library\Import\LegacyMapping;
use App\Services\Library\Import\MappedCategory;
use App\Services\Library\Import\UnmappedLegacyCategory;

uses()->group('module-3', 'library', 'biblio-import');

const LEGACY_CATEGORY_PATHS = __DIR__.'/../../../Fixtures/joomla/legacy-category-paths.php';

/** @return list<string> */
function keywordSlugs(MappedCategory $mapped): array
{
    return array_map(fn (KeywordRef $k): string => $k->slug, $mapped->keywords);
}

it('applique chaque ligne du tableau de correspondance DOCman', function (string $path, string $type, array $keywords): void {
    $mapped = (new LegacyMapping)->forDocmanPath($path);

    expect($mapped->type)->toBe($type)
        ->and(keywordSlugs($mapped))->toBe($keywords)
        ->and($mapped->accessLevel)->toBe('public');
})->with([
    'textes et lois' => ['textes-et-lois', 'texte_loi', []],
    'décrets CTD' => ['textes-et-lois/decrets/decrets-ctd', 'texte_loi', ['decrets', 'decentralisation']],
    'arrêtés comptabilité' => ['textes-et-lois/arretes/arrete-comptabilite', 'texte_loi', ['arretes', 'comptabilite-publique']],
    'circulaires lois de règlement' => ['textes-et-lois/circulaires/circulaires-lois-de-reglement', 'texte_loi', ['circulaires', 'lois-de-reglement']],
    'lois de règlement et ordonnances' => ['textes-et-lois/lois/lois-de-reglement-et-ordonnances', 'texte_loi', ['lois', 'lois-de-reglement', 'ordonnances']],
    'décisions généralités' => ['textes-et-lois/decisions/decisions-generalites', 'texte_loi', ['decisions', 'generalites']],
    'CTD décrets' => ['les-collectivites-territoriales-decentralisees/les-decrets', 'texte_loi', ['decentralisation', 'decrets']],
    'autres textes (supports sur le contrôle)' => ['autres-textes', 'rapport', ['audit-et-controle']],
    'instructions' => ['instructions', 'texte_loi', ['instructions']],
    'articles' => ['articles', 'article_scientifique', []],
    'articles comptabilité' => ['articles/comptabilite-1', 'article_scientifique', ['comptabilite-publique']],
    'articles CTD' => ['articles/ctd', 'article_scientifique', ['decentralisation']],
    'articles économie' => ['articles/economie', 'article_scientifique', ['economie']],
    'articles fiscalité' => ['articles/fiscalite-1', 'article_scientifique', ['fiscalite']],
    'articles gestion budgétaire' => ['articles/gestion-budgetaire', 'article_scientifique', ['gestion-budgetaire']],
    'articles management' => ['articles/management', 'article_scientifique', ['management-public']],
    'articles marchés publics' => ['articles/marches-publics', 'article_scientifique', ['marches-publics']],
    'manuels' => ['manuels', 'ouvrage_numerique', []],
    'mémentos' => ['mementos', 'ouvrage_numerique', []],
    'guides' => ['guides', 'ouvrage_numerique', []],
    'séminaires et formations' => ['reformes/seminaires-et-formations', 'ressource_pedagogique', ['reformes']],
    'réformes' => ['reformes', 'rapport', ['reformes']],
    'réformes budget-programme' => ['reformes/budget-programme', 'rapport', ['reformes', 'budget-programme']],
    'réformes audit' => ['reformes/audit-et-controle', 'rapport', ['reformes', 'audit-et-controle']],
    'réformes rapports' => ['reformes/rapports-et-publications-1', 'rapport', ['reformes']],
    'Banque mondiale' => ['institutions/institutions-internationales/banque-mondiale', 'rapport', ['institutions-internationales', 'banque-mondiale']],
    'FMI' => ['institutions/institutions-internationales/fonds-monetaire-internationale', 'rapport', ['institutions-internationales', 'fmi']],
    'Chambre des comptes' => ['institutions/institutions-nationales/chambre-des-comptes', 'rapport', ['institutions-nationales', 'chambre-des-comptes']],
    'MINFI' => ['institutions/institutions-nationales/minfi', 'rapport', ['institutions-nationales', 'minfi']],
    'rapports et publications' => ['rapports-et-publications', 'rapport', []],
    'économie' => ['economie-1', 'rapport', ['economie']],
    'fiscalité' => ['fiscalite', 'rapport', ['fiscalite']],
]);

it('applique chaque ligne du tableau de correspondance Alexandria', function (string $path, string $type, array $keywords): void {
    $mapped = (new LegacyMapping)->forAlexandriaCategory($path);

    expect($mapped->type)->toBe($type)
        ->and(keywordSlugs($mapped))->toBe($keywords);
})->with([
    'racine' => ['', 'ouvrage_numerique', []],
    'ouvrages numériques' => ['ouvrage-numerique', 'ouvrage_numerique', []],
    'comptabilité publique' => ['ouvrage-numerique/comptabilite-publiques', 'ouvrage_numerique', ['comptabilite-publique']],
    'recueils de textes' => ['ouvrage-numerique/receuil-de-textes', 'ouvrage_numerique', ['recueils-de-textes']],
    'finances' => ['ouvrage-numerique/finances', 'ouvrage_numerique', ['finances-publiques']],
    'autres' => ['ouvrage-numerique/autres', 'ouvrage_numerique', ['autres-ouvrages']],
    'réformes marchés publics' => ['reformes/marches-publics', 'rapport', ['reformes', 'marches-publics']],
    'rapports et publications' => ['rapports-et-publications', 'rapport', []],
    'textes dépubliés (des livres)' => ['textes-et-lois', 'ouvrage_numerique', []],
    'sous-catégorie des textes dépubliés' => ['textes-et-lois/decrets', 'ouvrage_numerique', []],
]);

it('donne une correspondance à chaque catégorie réelle de l\'ancienne bibliothèque', function (): void {
    $paths = require LEGACY_CATEGORY_PATHS;
    $mapping = new LegacyMapping;

    expect($paths['docman'])->toHaveCount(84)->and($paths['alexandria'])->toHaveCount(27);

    foreach (array_keys($paths['docman']) as $path) {
        expect(array_key_exists($mapping->forDocmanPath((string) $path)->type, Document::TYPES))->toBeTrue("DOCman {$path}");
    }
    foreach (array_keys($paths['alexandria']) as $path) {
        expect(array_key_exists($mapping->forAlexandriaCategory((string) $path)->type, Document::TYPES))->toBeTrue("Alexandria {$path}");
    }
});

it('tolère les barres obliques et la casse dans un chemin', function (): void {
    expect((new LegacyMapping)->forDocmanPath('/Articles/CTD/')->type)->toBe('article_scientifique');
});

it('refuse un chemin sans correspondance', function (string $path): void {
    (new LegacyMapping)->forDocmanPath($path);
})->with(['inconnu', 'articlesx', ''])->throws(UnmappedLegacyCategory::class);

it('ne prend pas la racine Alexandria pour un défaut', function (): void {
    (new LegacyMapping)->forAlexandriaCategory('inconnu');
})->throws(UnmappedLegacyCategory::class);

it('donne le mot-clé le plus spécifique d\'une catégorie', function (): void {
    $mapping = new LegacyMapping;

    expect($mapping->forDocmanPath('institutions/institutions-nationales/prc')->primaryKeyword()?->slug)->toBe('prc')
        ->and($mapping->forDocmanPath('textes-et-lois')->primaryKeyword())->toBeNull();
});

it('expose un thésaurus hiérarchique, parents d\'abord, libellés français', function (): void {
    $thesaurus = (new LegacyMapping)->thesaurus();
    $seen = [];

    foreach ($thesaurus as $keyword) {
        expect($keyword->slug)->toMatch('/^[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and(mb_strlen($keyword->slug))->toBeLessThanOrEqual(120);
        if ($keyword->parentSlug !== null) {
            expect($seen)->toHaveKey($keyword->parentSlug);
        }
        $seen[$keyword->slug] = $keyword;
    }

    expect(array_keys(array_filter($seen, fn (KeywordRef $k): bool => $k->parentSlug === null)))
        ->toBe(['themes', 'institutions', 'types-de-textes'])
        ->and($seen['themes']->label)->toBe('Thèmes')
        ->and($seen['decrets']->label)->toBe('Décrets')
        ->and($seen['decrets']->parentSlug)->toBe('types-de-textes')
        ->and($seen['banque-mondiale']->parentSlug)->toBe('institutions-internationales')
        ->and($seen['institutions-internationales']->parentSlug)->toBe('institutions')
        ->and($seen['comptabilite-publique']->label)->toBe('Comptabilité publique');
});

it('rattache chaque mot-clé d\'une catégorie à son parent du thésaurus', function (): void {
    $keyword = (new LegacyMapping)->forDocmanPath('textes-et-lois/lois/lois-des-marches-publics')->keywords[1];

    expect($keyword)->toEqual(new KeywordRef('marches-publics', 'Marchés publics', 'themes'));
});

it('valide la configuration au chargement', function (array $config, string $message): void {
    expect(fn () => new LegacyMapping($config))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'type inconnu' => [['thesaurus' => [], 'docman' => ['a' => ['type' => 'inexistant']], 'alexandria' => []], 'inexistant'],
    'mot-clé absent du thésaurus' => [['thesaurus' => [], 'docman' => ['a' => ['type' => 'rapport', 'keywords' => ['fantome']]], 'alexandria' => []], 'fantome'],
    'parent déclaré après' => [['thesaurus' => ['enfant' => ['label' => 'Enfant', 'parent' => 'parent'], 'parent' => ['label' => 'Parent']], 'docman' => [], 'alexandria' => []], 'parent'],
    'slug non conforme' => [['thesaurus' => ['Mauvais_Slug' => ['label' => 'X']], 'docman' => [], 'alexandria' => []], 'Mauvais_Slug'],
    'libellé vide' => [['thesaurus' => ['vide' => ['label' => ' ']], 'docman' => [], 'alexandria' => []], 'vide'],
    'accès inconnu' => [['thesaurus' => [], 'docman' => ['a' => ['type' => 'rapport', 'access_level' => 'vip']], 'alexandria' => []], 'vip'],
]);

it('range « ouvrage-numerique/autres » sous le thème Autres ouvrages', function (): void {
    $mapped = (new LegacyMapping)->forAlexandriaCategory('ouvrage-numerique/autres');
    $theme = collect((new LegacyMapping)->thesaurus())->firstWhere('slug', 'autres-ouvrages');

    expect($mapped->type)->toBe('ouvrage_numerique')
        ->and(keywordSlugs($mapped))->toBe(['autres-ouvrages'])
        ->and($theme->label)->toBe('Autres ouvrages')
        ->and($theme->parentSlug)->toBe('themes');
});

it('applique la règle du dossier du serveur le plus spécifique', function (string $path, string $type, array $keywords, string $rule): void {
    $result = (new LegacyMapping)->forServerFile($path);

    expect($result->exclusionReason)->toBeNull()
        ->and($result->category->type)->toBe($type)
        ->and(keywordSlugs($result->category))->toBe($keywords)
        ->and($result->folderRule)->toBe($rule);
})->with([
    'livre à la racine' => ['livres/Un livre.pdf', 'ouvrage_numerique', ['autres-ouvrages'], 'livres'],
    'livre en sous-dossier' => ['livres/Livre/X.pdf', 'ouvrage_numerique', ['autres-ouvrages'], 'livres'],
    'décrets' => ['Documentations/DECRETS/X.pdf', 'texte_loi', ['decrets'], 'Documentations/DECRETS'],
    'réformes audit' => ['Documentations/REFORMES/AUDIT ET CONTROLE/X.pdf', 'rapport', ['reformes', 'audit-et-controle'], 'Documentations/REFORMES/AUDIT ET CONTROLE'],
    'insensible à la casse' => ['documentations/decrets/x.PDF', 'texte_loi', ['decrets'], 'Documentations/DECRETS'],
]);

it('exclut les dossiers et fichiers listés', function (string $path, string $rule): void {
    $result = (new LegacyMapping)->forServerFile($path);

    expect($result->category)->toBeNull()
        ->and($result->exclusionReason)->not->toBe('')->not->toBeNull()
        ->and($result->folderRule)->toBe($rule);
})->with([
    'sous-dossier plus spécifique que ARRETES' => ['Documentations/ARRETES/TEST/X.pdf', 'Documentations/ARRETES/TEST'],
    'fichier listé' => ['livres/Programmer en Java.pdf', 'livres/Programmer en Java.pdf'],
    'fichier listé, autre casse' => ['LIVRES/programmer en java.PDF', 'livres/Programmer en Java.pdf'],
]);

it('normalise les chemins serveur (NFC, Windows-1252)', function (): void {
    $mapping = new LegacyMapping([
        'thesaurus' => ['themes' => ['label' => 'Thèmes']],
        'docman' => ['a' => ['type' => 'rapport']],
        'server_folders' => ['Étude' => ['source' => 'docman', 'category' => 'a']],
    ]);

    $nfd = "E\u{0301}tude/x.pdf";
    $cp1252 = mb_convert_encoding('Étude/x.pdf', 'Windows-1252', 'UTF-8');

    expect($mapping->forServerFile($nfd)->category?->type)->toBe('rapport')
        ->and($mapping->forServerFile($cp1252)->category?->type)->toBe('rapport');
});

it('ne reconnaît pas un préfixe partiel de segment', function (): void {
    $result = (new LegacyMapping)->forServerFile('livres-anciens/X.pdf');

    expect($result->category)->toBeNull()->and($result->exclusionReason)->toBeNull();
});

it('renvoie un résultat vide pour un dossier inconnu', function (): void {
    $result = (new LegacyMapping)->forServerFile('Inconnu/X.pdf');

    expect($result->category)->toBeNull()
        ->and($result->exclusionReason)->toBeNull()
        ->and($result->folderRule)->toBe('');
});

it('refuse une configuration de dossiers serveur invalide', function (array $override, string $message): void {
    $config = [
        'thesaurus' => ['themes' => ['label' => 'Thèmes']],
        'docman' => ['a' => ['type' => 'rapport']],
        'alexandria' => ['' => ['type' => 'ouvrage_numerique']],
    ];

    expect(fn () => new LegacyMapping($config + $override))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'catégorie absente' => [['server_folders' => ['x' => ['source' => 'docman', 'category' => 'absente']]], 'absente'],
    'source inconnue' => [['server_folders' => ['x' => ['source' => 'ftp', 'category' => 'a']]], 'source'],
    'règle sans catégorie ni exclusion' => [['server_folders' => ['x' => []]], 'x'],
    'raison d\'exclusion vide' => [['server_excluded_files' => ['x.pdf' => ' ']], 'x.pdf'],
]);

it('expose la source « serveur »', function (): void {
    expect(LegacyMapping::SOURCE_SERVER)->toBe('serveur');
});

it('exclut les chemins serveur non canoniques au lieu de les laisser passer', function (string $path, string $reason, string $rule): void {
    $result = (new LegacyMapping)->forServerFile($path);

    expect($result->category)->toBeNull()
        ->and($result->exclusionReason)->toBe($reason)
        ->and($result->folderRule)->toBe($rule);
})->with([
    'double slash' => ['Documentations//ARRETES/TEST/x.pdf', "données personnelles (liste nominative d'auditeurs)", 'Documentations/ARRETES/TEST'],
    'segment point' => ['Documentations/./ARRETES/TEST/x.pdf', "données personnelles (liste nominative d'auditeurs)", 'Documentations/ARRETES/TEST'],
    'antislash' => ['Documentations\\ARRETES\\TEST\\x.pdf', "données personnelles (liste nominative d'auditeurs)", 'Documentations/ARRETES/TEST'],
    'remontée' => ['livres/../Documentations/ARRETES/TEST/x.pdf', 'chemin non canonique', ''],
]);

it('retombe sur la règle générique Documentations', function (): void {
    $result = (new LegacyMapping)->forServerFile('Documentations/X.pdf');

    expect($result->folderRule)->toBe('Documentations')->and($result->category->type)->toBe('texte_loi');
});

it('refuse les clés qui se confondent après normalisation', function (array $override): void {
    $config = [
        'thesaurus' => ['themes' => ['label' => 'Thèmes']],
        'docman' => ['a' => ['type' => 'rapport']],
    ];

    expect(fn () => new LegacyMapping($config + $override))->toThrow(InvalidArgumentException::class, 'doublon');
})->with([
    'dossiers' => [['server_folders' => [
        'Docs/A' => ['exclude' => 'x'],
        'docs//a' => ['exclude' => 'y'],
    ]]],
    'fichiers' => [['server_excluded_files' => ['Livres/X.pdf' => 'x', 'livres/x.PDF' => 'y']]],
]);

it('refuse un dossier à la fois exclu et catégorisé', function (): void {
    $config = [
        'thesaurus' => ['themes' => ['label' => 'Thèmes']],
        'docman' => ['a' => ['type' => 'rapport']],
        'server_folders' => ['x' => ['exclude' => 'raison', 'source' => 'docman', 'category' => 'a']],
    ];

    expect(fn () => new LegacyMapping($config))->toThrow(InvalidArgumentException::class, 'x');
});
