<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;

/** Compare les documents publiés en base avec l'index Meilisearch (spec V1 §5.4). */
final class CheckLibraryIndex extends Command
{
    protected $signature = 'library:check-index';

    protected $description = "Vérifie que l'index Meilisearch contient autant de documents que la base (publiés).";

    public function handle(): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->warn('Contrôle ignoré : le pilote Scout actif n\'est pas Meilisearch.');

            return self::SUCCESS;
        }

        $database = Document::query()->published()->count();
        $indexed = (int) (new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key')))->index((new Document)->searchableAs())->stats()['numberOfDocuments'];

        $this->line("Base : {$database} · Index : {$indexed}");

        if ($database === $indexed) {
            return self::SUCCESS;
        }

        Log::warning('Index bibliothèque incohérent avec la base.', ['database' => $database, 'index' => $indexed]);
        $this->error('Incohérence détectée entre la base et l\'index.');

        return self::FAILURE;
    }
}
