<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;

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

        try {
            $indexed = (int) (new Client(config('scout.meilisearch.host'), config('scout.meilisearch.key')))
                ->index((new Document)->searchableAs())->stats()['numberOfDocuments'];
        } catch (ApiException|CommunicationException $e) {
            Log::warning('Contrôle de l\'index bibliothèque impossible.', ['exception' => $e::class, 'error' => $e->getMessage()]);
            $this->error('Meilisearch injoignable ou index absent : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Base : {$database} · Index : {$indexed}");

        if ($database === $indexed) {
            return self::SUCCESS;
        }

        Log::warning('Index bibliothèque incohérent avec la base.', ['database' => $database, 'index' => $indexed]);
        $this->error('Incohérence détectée entre la base et l\'index.');

        return self::FAILURE;
    }
}
