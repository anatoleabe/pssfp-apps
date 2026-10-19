<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use Illuminate\Support\Facades\DB;

uses()->group('module-3', 'library');

it('compte les documents publiés par type et signale la présence de payants', function (): void {
    Document::factory()->published()->count(2)->create(['type' => 'memoire']);
    Document::factory()->published()->paid()->create(['type' => 'ouvrage_numerique']);
    Document::factory()->create(['type' => 'these']);

    $this->getJson('/v1/library/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_type.memoire', 2)
        ->assertJsonPath('data.by_type.ouvrage_numerique', 1)
        ->assertJsonPath('data.paid_count', 1)
        ->assertJsonMissingPath('data.by_type.these');
});

it('compte les promotions distinctes des mémoires publiés', function (): void {
    Document::factory()->published()->count(2)->create(['type' => 'memoire', 'promotion' => 3]);
    Document::factory()->published()->create(['type' => 'memoire', 'promotion' => 7]);
    Document::factory()->published()->create(['type' => 'memoire', 'promotion' => null]);
    Document::factory()->published()->create(['type' => 'rapport_stage', 'promotion' => 11]);
    Document::factory()->create(['type' => 'memoire', 'promotion' => 9]);

    $this->getJson('/v1/library/stats')
        ->assertOk()
        ->assertJsonPath('data.promotions_count', 2);
});

it('renvoie zéro promotion sans mémoire publié', function (): void {
    $this->getJson('/v1/library/stats')
        ->assertOk()
        ->assertJsonPath('data.promotions_count', 0);
});

it('liste les documents publiés, 20 par page, sans identifiant interne', function (): void {
    Document::factory()->published()->count(25)->create();
    Document::factory()->count(3)->create();

    $response = $this->getJson('/v1/library/documents')->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('data.0'))->not->toHaveKey('id')
        ->and($response->json('data.0'))->toHaveKeys(['uuid', 'slug', 'access_mode', 'cover_url']);
});

it('filtre par type, accès, mot-clé et plage d\'années', function (): void {
    $mc = MotCle::factory()->create(['slug' => 'fiscalite']);
    $cible = Document::factory()->published()->create(['type' => 'these', 'year' => 2020]);
    $cible->motsCles()->attach($mc);
    Document::factory()->published()->create(['type' => 'these', 'year' => 2010]);
    Document::factory()->published()->reserved()->create(['type' => 'these', 'year' => 2020]);
    Document::factory()->published()->create(['type' => 'memoire', 'year' => 2020]);

    $this->getJson('/v1/library/documents?type=these&acces=libre&mot_cle=fiscalite&annee_min=2015&annee_max=2025')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $cible->uuid);
});

it('trie par popularité', function (): void {
    Document::factory()->published()->create(['slug' => 'peu-lu', 'views_count' => 3]);
    Document::factory()->published()->create(['slug' => 'tres-lu', 'views_count' => 900]);

    $this->getJson('/v1/library/documents?tri=populaire')->assertJsonPath('data.0.slug', 'tres-lu');
});

it('rejette un tri inconnu', function (): void {
    $this->getJson('/v1/library/documents?tri=hasard')->assertUnprocessable();
});

it('affiche une fiche avec la décision d\'accès', function (): void {
    $doc = Document::factory()->published()->withFile()->reserved()->create(['slug' => 'support-ue4']);
    $doc->authors()->create(['author_name' => 'Auteur Test', 'order' => 1]);
    Document::factory()->published()->create(['type' => $doc->type]);

    $this->getJson('/v1/library/documents/support-ue4')
        ->assertOk()
        ->assertJsonPath('data.access.allowed', false)
        ->assertJsonPath('data.access.reason', 'reserved')
        ->assertJsonPath('data.authors.0', 'Auteur Test')
        ->assertJsonCount(1, 'related');

    expect($doc->fresh()->views_count)->toBe(0);
});

it('expose le format du fichier sur la fiche', function (?string $path, ?string $format): void {
    Document::factory()->published()->create(['slug' => 'fiche-format', 'file_path' => $path]);

    $this->getJson('/v1/library/documents/fiche-format')->assertOk()->assertJsonPath('data.file_format', $format);
})->with([
    'pdf' => ['documents/a.pdf', 'PDF'],
    'pptx' => ['documents/b.PPTX', 'PPTX'],
    'sans extension' => ['documents/c', 'PDF'],
    'sans fichier' => [null, null],
]);

it('reserve les champs de détail à la fiche, pas aux documents liés ni à la liste', function (): void {
    $doc = Document::factory()->published()->create(['slug' => 'fiche']);
    Document::factory()->published()->create(['type' => $doc->type]);

    $show = $this->getJson('/v1/library/documents/fiche')->assertOk();
    expect($show->json('data'))->toHaveKeys(['access', 'abstract', 'views_count'])
        ->and($show->json('related.0'))->not->toHaveKeys(['access', 'abstract', 'doi', 'external_url', 'views_count']);

    $list = $this->getJson('/v1/library/documents')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('access')->not->toHaveKey('abstract');
});

it('rejette un chemin de redirection invalide', function (): void {
    $this->getJson('/v1/library/redirects?path[]=x')->assertUnprocessable();
    $this->getJson('/v1/library/redirects')->assertUnprocessable();
});

it('renvoie 404 pour une fiche non publiée', function (): void {
    Document::factory()->create(['slug' => 'brouillon']);

    $this->getJson('/v1/library/documents/brouillon')->assertNotFound();
});

it('liste le thésaurus', function (): void {
    MotCle::factory()->create(['slug' => 'tva', 'label' => ['fr' => 'TVA']]);

    $this->getJson('/v1/library/mots-cles')->assertOk()->assertJsonPath('data.0.slug', 'tva');
});

it('résout une ancienne URL Joomla et compte l\'usage', function (): void {
    $r = LibraryRedirect::create(['from_path' => '/index.php/textes-et-lois/lois', 'to_path' => '/collections/texte_loi']);

    $this->getJson('/v1/library/redirects?path=/index.php/textes-et-lois/lois')
        ->assertOk()
        ->assertJsonPath('data.to_path', '/collections/texte_loi')
        ->assertJsonPath('data.status_code', 301);

    expect($r->fresh()->hits)->toBe(1);
    $this->getJson('/v1/library/redirects?path=/inconnu')->assertNotFound();
});

it('limite l\'ensemble de la bibliothèque à 120 requêtes par minute et par client', function (): void {
    foreach (range(1, 120) as $i) {
        $this->getJson('/v1/library/stats')->assertOk();
    }

    $this->getJson('/v1/library/stats')->assertStatus(429);
    $this->getJson('/v1/library/mots-cles')->assertStatus(429);
});

it('garde 60 recherches par minute sous le plafond général', function (): void {
    foreach (range(1, 60) as $i) {
        $this->getJson('/v1/library/search?q=budget')->assertOk();
    }

    $this->getJson('/v1/library/search?q=budget')->assertStatus(429);
    $this->getJson('/v1/library/stats')->assertOk();
});

it('place les documents sans date de publication en fin de tri récent', function (): void {
    Document::factory()->published()->create(['slug' => 'sans-date', 'published_at' => null]);
    Document::factory()->published()->create(['slug' => 'recent', 'published_at' => now()->subDay()]);
    Document::factory()->published()->create(['slug' => 'ancien', 'published_at' => now()->subYear()]);

    $slugs = collect($this->getJson('/v1/library/documents?tri=recent')->json('data'))->pluck('slug')->all();

    expect($slugs)->toBe(['recent', 'ancien', 'sans-date']);
});

it('propose d\'abord les documents liés qui partagent un mot-clé', function (): void {
    $motCle = MotCle::factory()->create();
    $doc = Document::factory()->published()->create(['slug' => 'fiche', 'type' => 'memoire']);
    $doc->motsCles()->attach($motCle);
    $proche = Document::factory()->published()->create(['slug' => 'proche', 'type' => 'memoire', 'views_count' => 1]);
    $proche->motsCles()->attach($motCle);
    Document::factory()->published()->create(['slug' => 'populaire', 'type' => 'memoire', 'views_count' => 500]);
    Document::factory()->published()->create(['slug' => 'autre-type', 'type' => 'these', 'views_count' => 900]);

    $related = collect($this->getJson('/v1/library/documents/fiche')->json('related'))->pluck('slug')->all();

    expect($related)->toBe(['proche', 'populaire']);
});

function thesaurusTroisNiveaux(): array
{
    $inst = MotCle::factory()->create(['slug' => 'institutions', 'label' => ['fr' => 'Institutions']]);
    $intl = MotCle::factory()->create(['slug' => 'institutions-internationales', 'label' => ['fr' => 'Internationales'], 'parent_id' => $inst->id]);
    $fmi = MotCle::factory()->create(['slug' => 'fmi', 'label' => ['fr' => 'FMI'], 'parent_id' => $intl->id]);

    return [$inst, $intl, $fmi];
}

function compteDe($response, string $slug): int
{
    return collect($response->json('data'))->firstWhere('slug', $slug)['documents_count'];
}

it('compte une seule fois un document rattaché à un parent et à son enfant', function (): void {
    [$inst, $intl, $fmi] = thesaurusTroisNiveaux();
    $doc = Document::factory()->published()->create();
    $doc->motsCles()->attach([$inst->id, $fmi->id]);
    Document::factory()->published()->create()->motsCles()->attach($intl->id);

    $response = $this->getJson('/v1/library/mots-cles')->assertOk();

    expect(compteDe($response, 'institutions'))->toBe(2)
        ->and(compteDe($response, 'institutions-internationales'))->toBe(2)
        ->and(compteDe($response, 'fmi'))->toBe(1)
        ->and($response->json('data.0.slug'))->toBe('fmi');
});

it('ne compte ni les brouillons ni les publications futures', function (): void {
    [$inst] = thesaurusTroisNiveaux();
    Document::factory()->create()->motsCles()->attach($inst->id);
    Document::factory()->published()->create(['published_at' => now()->addDay()])->motsCles()->attach($inst->id);
    Document::factory()->published()->create()->motsCles()->attach($inst->id);

    expect(compteDe($this->getJson('/v1/library/mots-cles'), 'institutions'))->toBe(1);
});

it('restreint les comptes au type demandé et rejette un type inconnu', function (): void {
    [$inst] = thesaurusTroisNiveaux();
    Document::factory()->published()->create(['type' => 'texte_loi'])->motsCles()->attach($inst->id);
    Document::factory()->published()->create(['type' => 'memoire'])->motsCles()->attach($inst->id);

    expect(compteDe($this->getJson('/v1/library/mots-cles?type=texte_loi'), 'institutions'))->toBe(1)
        ->and(compteDe($this->getJson('/v1/library/mots-cles?type=these'), 'institutions'))->toBe(0);
    $this->getJson('/v1/library/mots-cles?type=inconnu')->assertUnprocessable();
});

it('compte les mots-clés sans document à zéro', function (): void {
    MotCle::factory()->create(['slug' => 'vide']);

    expect(compteDe($this->getJson('/v1/library/mots-cles'), 'vide'))->toBe(0);
});

it('garde un nombre de requêtes indépendant du nombre de mots-clés', function (): void {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/v1/library/mots-cles')->assertOk();

        return count(DB::getQueryLog());
    };
    thesaurusTroisNiveaux();
    $avant = $count();

    foreach (range(1, 15) as $i) {
        $mc = MotCle::factory()->create();
        Document::factory()->published()->create()->motsCles()->attach($mc->id);
    }

    expect($count())->toBe($avant);
});

it('filtre les documents par mot-clé en incluant les sous-thèmes', function (): void {
    [$inst, , $fmi] = thesaurusTroisNiveaux();
    Document::factory()->published()->create(['slug' => 'sur-fmi'])->motsCles()->attach($fmi->id);
    Document::factory()->published()->create(['slug' => 'ailleurs']);

    $slugs = fn (string $q) => collect($this->getJson('/v1/library/documents?mot_cle='.$q)->assertOk()->json('data'))->pluck('slug')->all();

    expect($slugs('institutions'))->toBe(['sur-fmi'])
        ->and($slugs('fmi'))->toBe(['sur-fmi'])
        ->and($slugs('inconnu'))->toBe([]);
});

it('ne boucle pas sur un cycle de parenté', function (): void {
    $a = MotCle::factory()->create(['slug' => 'a']);
    $b = MotCle::factory()->create(['slug' => 'b', 'parent_id' => $a->id]);
    $a->update(['parent_id' => $b->id]);
    Document::factory()->published()->create(['slug' => 'd'])->motsCles()->attach($b->id);

    expect(compteDe($this->getJson('/v1/library/mots-cles'), 'a'))->toBe(1);
    $this->getJson('/v1/library/documents?mot_cle=a')->assertOk()->assertJsonCount(1, 'data');
});
