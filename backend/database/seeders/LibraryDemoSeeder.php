<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Document;
use App\Models\LibraryRedirect;
use App\Models\MotCle;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Bibliothèque de démonstration (dev et E2E locaux) — données fictives.
 * `php artisan db:seed --class=LibraryDemoSeeder`
 */
final class LibraryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LibraryDemoSeeder est interdit en production.');
        }

        $motsCles = collect(['fiscalite' => 'Fiscalité', 'marches-publics' => 'Marchés publics', 'decentralisation' => 'Décentralisation', 'audit' => 'Audit et contrôle', 'budget' => 'Budget de l\'État'])
            ->map(fn (string $label, string $slug) => MotCle::query()->firstOrCreate(['slug' => $slug], ['label' => ['fr' => $label]]));

        $types = ['memoire' => 12, 'these' => 4, 'texte_loi' => 6, 'ouvrage_numerique' => 5, 'cours' => 3, 'article_scientifique' => 4];
        foreach ($types as $type => $count) {
            Document::factory()->published()->count($count)->create(['type' => $type, 'promotion' => $type === 'memoire' ? fake()->numberBetween(1, 13) : null])
                ->each(function (Document $doc) use ($motsCles, $type): void {
                    $doc->authors()->create(['author_name' => fake()->name(), 'order' => 1]);
                    $doc->motsCles()->attach($motsCles->random(2)->pluck('id'));
                    $doc->update(['views_count' => fake()->numberBetween(0, 900)]);
                    if ($type === 'cours') {
                        $doc->update(['access_level' => 'auditor']);
                    }
                });
        }

        Document::factory()->published()->paid(2500)->create(['type' => 'ouvrage_numerique', 'title' => ['fr' => 'Manuel de comptabilité publique (démonstration)']]);
        Document::factory()->published()->create(['type' => 'memoire', 'slug' => 'decentralisation-fiscale-demo', 'title' => ['fr' => 'La décentralisation fiscale : bilan et perspectives (démonstration)']]);

        // Ancienne URL Joomla de démonstration (tests de redirection du Plan 2, tâche 8).
        LibraryRedirect::query()->firstOrCreate(['from_path' => '/index.php/textes-et-lois'], ['to_path' => '/collections/texte_loi']);
    }
}
