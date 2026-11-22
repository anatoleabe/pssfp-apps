<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Services\Lmd\PvDeliberation;
use Illuminate\Console\Command;

/**
 * Reprise du lot 16a : archive au registre le PV des délibérations arrêtées avant l'archivage
 * automatique. Essai à blanc par défaut ; rejouable sans doublon. Le PV d'une délibération déjà
 * rectifiée est archivé puis révoqué.
 */
final class ArchiverPvDeliberations extends Command
{
    protected $signature = 'lmd:archiver-pv
        {--appliquer : Écrire en base et conserver les fichiers (sinon essai à blanc)}';

    protected $description = 'Archive le PV des délibérations arrêtées qui n\'en ont pas encore au registre';

    public function handle(PvDeliberation $pv): int
    {
        $appliquer = (bool) $this->option('appliquer');
        $deliberations = Deliberation::query()
            ->whereIn('statut', [Deliberation::ARRETEE, Deliberation::RECTIFIEE])
            ->whereNotNull('numero_pv')
            ->whereNotIn('id', DocumentDelivre::query()->where('type', DocumentDelivre::PV)->select('deliberation_id'))
            ->orderBy('id')
            ->get();

        $this->info($deliberations->count().' PV à archiver'.($appliquer ? '' : ' (essai à blanc)'));
        foreach ($deliberations as $d) {
            if ($appliquer) {
                $document = $pv->archiver($d, null);
                $this->line(sprintf('%s archivé%s', $d->numero_pv, $document->estRevoque() ? ' puis révoqué (délibération rectifiée)' : ''));
            } else {
                $this->line($d->numero_pv.' · '.Deliberation::STATUTS[$d->statut]);
            }
        }

        return self::SUCCESS;
    }
}
