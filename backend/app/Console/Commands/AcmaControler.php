<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Acma\ErreurAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\Reprise\ControlesAcma;
use App\Services\Acma\Reprise\RapportsReprise;
use App\Services\Acma\Reprise\ResultatControle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/** Lot 15b (spec du lot 15, §10.5) : contrôles d'équivalence sur l'archive en place, en lecture seule. */
final class AcmaControler extends Command
{
    protected $signature = 'acma:controler';

    protected $description = 'Contrôle l\'archive ACMA en place contre le transit (lecture seule)';

    public function handle(ControlesAcma $controles, RapportsReprise $rapports): int
    {
        AcmaReprendre::memoire();
        try {
            $chargement = LectureTransit::chargement();
            $resultats = $controles->executer(null);
        } catch (Throwable $e) {
            // Aucune donnée à la console : message de la reprise, ou SQLSTATE, table et contrainte seulement.
            $this->error(ErreurAcma::sansDonnees($e, 'des contrôles')->getMessage());

            return self::FAILURE;
        }
        $this->table(['Contrôle', 'Libellé', 'Attendu', 'Obtenu', 'Statut'], array_map(
            fn (ResultatControle $c): array => [$c->code, $c->libelle, $c->attendu, $c->obtenu, $c->statut()], $resultats,
        ));
        $maintenant = CarbonImmutable::now();
        $chemin = $rapports->ecrireControles($resultats, [], [
            'Contrôle' => 'archive en place (acma:controler)',
            'Empreinte du manifeste du transit' => $chargement['manifeste_sha256'],
            'Date' => $maintenant->toIso8601String(),
        ], $maintenant);
        $this->line('Rapport (stockage privé) : '.$chemin);
        $echecs = array_filter($resultats, fn (ResultatControle $c): bool => $c->enEchec());
        if ($echecs !== []) {
            $this->error(count($echecs).' contrôle(s) bloquant(s) en échec.');

            return self::FAILURE;
        }
        $this->info('Aucun contrôle bloquant en échec.');

        return self::SUCCESS;
    }
}
