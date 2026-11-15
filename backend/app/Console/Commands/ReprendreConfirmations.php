<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scolarite\RepriseConfirmations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reprise des confirmations (spec module-4-scolarite-encaissement-confirmation §9), à lancer juste après le
 * rechargement du code, application en maintenance : les versements saisis par l'ancien code entre la
 * migration et le rechargement sont repris comme confirmés. Idempotente, vérifiée, tout ou rien.
 *
 * Refusée hors maintenance : l'application ouverte, elle confirmerait sans scan les versements que le service
 * financier vient d'enregistrer et que la scolarité doit contrôler.
 */
final class ReprendreConfirmations extends Command
{
    protected $signature = 'scolarite:reprendre-confirmations';

    protected $description = 'Reprend comme confirmés les versements d\'avant la confirmation par la scolarité (déploiement, application en maintenance).';

    public function handle(RepriseConfirmations $reprise): int
    {
        if (! $this->laravel->isDownForMaintenance()) {
            $this->error('Application ouverte : lancez la reprise en maintenance (php artisan down), juste après le rechargement du code.');

            return self::FAILURE;
        }

        try {
            $lignes = $reprise->executer();
        } catch (RuntimeException $e) {
            // Uuid des inscriptions seulement, jamais de nom.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Log::info('Reprise des confirmations de versements (commande)', ['confirmations' => $lignes]);
        $this->info($lignes.' versement(s) repris comme confirmés ; aucun état d\'accès n\'a changé.');

        return self::SUCCESS;
    }
}
