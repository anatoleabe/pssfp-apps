<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Scolarite\VerificationComptes;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vérifie en lecture seule les comptes des auditeurs (spec D1, §10.4). Ne sort que des
 * effectifs, des montants et des uuid : aucun nom. Code de retour non nul sur un écart.
 */
final class VerifierComptes extends Command
{
    protected $signature = 'scolarite:verifier-comptes
        {--equivalence : Compare aussi à l\'ancien calcul (valable tant qu\'aucune inscription n\'a reçu l\'argent d\'un versement qui ne la désigne pas, donc jusqu\'à D3)}';

    protected $description = 'Vérifie, en lecture seule, la cohérence des comptes des auditeurs : versements, imputations, avances';

    /** Écarts affichés au plus ; les suivants sont comptés. */
    private const ECARTS_MAX = 100;

    public function handle(VerificationComptes $verification): int
    {
        return $this->enLectureSeule(function () use ($verification): int {
            $totaux = $verification->totaux();
            $this->table(['Versements valides', 'Versé (FCFA)', 'Imputé (FCFA)', 'Avance (FCFA)', 'dont réservée', 'Auditeurs avec avance'], [[
                $totaux['versements'], self::fcfa($totaux['verse']), self::fcfa($totaux['impute']), self::fcfa($totaux['avance']),
                self::fcfa($totaux['avance_reservee']), $totaux['auditeurs_avec_avance'],
            ]]);

            $ecarts = $verification->ecarts();
            if ($this->option('equivalence')) {
                $equivalence = $verification->equivalence();
                $this->line('Équivalence avec l\'ancien calcul : '.$equivalence['inscriptions'].' inscription(s) comparée(s), '
                    .$equivalence['exclus'].' auditeur(s) hors comparaison (argent imputé sur une autre année).');
                $this->table(['Filtre', 'Ancien calcul', 'Imputations'], array_map(
                    fn (string $filtre, array $n): array => [$filtre, $n[0], $n[1]],
                    array_keys($effectifs = $verification->effectifs($verification->auditeursCroises())), $effectifs,
                ));
                $ecarts = [...$ecarts, ...$equivalence['ecarts']];
            }

            if ($ecarts === []) {
                $this->info('Aucun écart.');

                return self::SUCCESS;
            }
            $this->error(count($ecarts).' écart(s) :');
            foreach (array_slice($ecarts, 0, self::ECARTS_MAX) as $ecart) {
                $this->line('  - '.$ecart);
            }
            if (count($ecarts) > self::ECARTS_MAX) {
                $this->line('  … et '.(count($ecarts) - self::ECARTS_MAX).' autre(s).');
            }

            return self::FAILURE;
        });
    }

    /**
     * Dans une transaction ouverte en REPEATABLE READ et READ ONLY : toutes les requêtes lisent le
     * même instantané, même pendant des saisies. Si une transaction est déjà ouverte (tests), on
     * n'y émet que des SELECT.
     *
     * @param  Closure(): int  $verifier
     */
    private function enLectureSeule(Closure $verifier): int
    {
        if (DB::transactionLevel() > 0) {
            return $verifier();
        }
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $verifier();
        } finally {
            DB::rollBack();
        }
    }

    private static function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
