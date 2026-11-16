<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CommandeAcma;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\Acma\Reprise\ResultatControle;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Lot 15b (spec du lot 15, §10.2 à §10.5) : reprise du transit dans l'archive `acma_*`. À blanc par défaut ; avec
 * --appliquer, validée seulement si aucun contrôle bloquant n'échoue. La console n'affiche que des comptes.
 */
final class AcmaReprendre extends Command
{
    use CommandeAcma;

    protected $signature = 'acma:reprendre
        {--auteur= : E-mail du compte super_admin ou admin qui porte la reprise}
        {--etapes= : Étapes séparées par des virgules (toutes par défaut)}
        {--appliquer : Valider la reprise (sinon à blanc : tout est annulé)}';

    protected $description = 'Reprend le transit ACMA dans l\'archive, avec contrôles d\'équivalence (à blanc par défaut)';

    public function handle(RepriseAcma $reprise): int
    {
        $auteur = $this->auteurAcma();
        if ($auteur === null) {
            return self::FAILURE;
        }
        self::memoire();
        $etapes = filled($this->option('etapes')) ? array_map('trim', explode(',', (string) $this->option('etapes'))) : null;
        try {
            $r = $reprise->executer($auteur, $etapes, (bool) $this->option('appliquer'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($r['comptes'] as $etape => $comptes) {
            $this->info("Étape {$etape}");
            $this->table(['Compte', 'Valeur'], self::aplatir($comptes));
        }
        $this->table(['Contrôle', 'Libellé', 'Attendu', 'Obtenu', 'Statut'], array_map(
            fn (ResultatControle $c): array => [$c->code, $c->libelle, $c->attendu, $c->obtenu, $c->statut()], $r['controles'],
        ));
        $this->line('Rapport des contrôles (stockage privé) : '.$r['rapport']);
        if ($r['classeur'] !== null) {
            $this->line('Classeur des cas à arbitrer (stockage privé) : '.$r['classeur']);
        }
        match ($r['statut']) {
            'appliquee' => $this->info("Reprise {$r['uuid']} appliquée."),
            'refusee' => $this->error("Reprise {$r['uuid']} refusée : un contrôle bloquant a échoué, rien n'a été écrit."),
            default => $this->warn("Essai à blanc {$r['uuid']} : rien n'a été écrit dans l'archive."),
        };

        return $r['statut'] === 'refusee' ? self::FAILURE : self::SUCCESS;
    }

    /** La reprise lit environ 380 000 lignes : la limite de mémoire de la console ne suffit pas toujours. */
    public static function memoire(): void
    {
        $limite = (string) ini_get('memory_limit');
        if ($limite !== '-1' && (int) $limite < 2048 && ! str_ends_with(strtoupper($limite), 'G')) {
            ini_set('memory_limit', '2048M');
        }
    }

    /**
     * @param  array<string, mixed>  $comptes
     * @return list<array{0: string, 1: int|string}>
     */
    public static function aplatir(array $comptes, string $prefixe = ''): array
    {
        $lignes = [];
        foreach ($comptes as $cle => $valeur) {
            if (is_array($valeur)) {
                array_push($lignes, ...self::aplatir($valeur, $prefixe.$cle.' · '));
            } else {
                $lignes[] = [$prefixe.$cle, is_bool($valeur) ? ($valeur ? 'oui' : 'non') : $valeur];
            }
        }

        return $lignes;
    }
}
