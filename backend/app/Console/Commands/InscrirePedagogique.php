<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Services\Lmd\InscriptionsPedagogiques;
use App\Services\Lmd\ParcoursAuditeur;
use Illuminate\Console\Command;

/**
 * Inscription pédagogique de toutes les inscriptions d'une année sur leur
 * maquette de référence validée (spec module 4, lot 4). Essai à blanc par
 * défaut. Le rapport ne cite que des matricules.
 */
final class InscrirePedagogique extends Command
{
    protected $signature = 'lmd:inscrire
        {--annee=2026-2027 : Année académique}
        {--appliquer : Écrire en base (sinon essai à blanc)}';

    protected $description = 'Inscrit les auditeurs d\'une année aux UE de leur maquette de référence';

    public function handle(InscriptionsPedagogiques $service, ParcoursAuditeur $parcours): int
    {
        $annee = (string) $this->option('annee');
        $appliquer = (bool) $this->option('appliquer');
        $bilan = ['inscrites' => 0, 'ues' => 0, 'deja' => 0];
        $sansParcours = [];
        $sansMaquette = [];

        InscriptionAnnuelle::query()->where('annee_academique', $annee)
            ->with(['parcours', 'auditeur.candidature'])
            ->withCount('inscriptionsPedagogiques')
            ->orderBy('id')
            ->chunkById(200, function ($inscriptions) use ($service, $parcours, $appliquer, &$bilan, &$sansParcours, &$sansMaquette): void {
                foreach ($inscriptions as $inscription) {
                    $matricule = (string) ($inscription->auditeur?->candidature?->matricule ?? '#'.$inscription->id);
                    if (! $appliquer) {
                        $p = $parcours->pour($inscription);
                        if ($p === null) {
                            $sansParcours[] = $matricule;
                        } elseif (Maquette::reference($inscription->annee_academique, $p->id) === null) {
                            $sansMaquette[$p->code][] = $matricule;
                        } elseif ($inscription->inscriptions_pedagogiques_count > 0) {
                            $bilan['deja']++;
                        } else {
                            $bilan['inscrites']++;
                        }

                        continue;
                    }
                    $r = $service->inscrire($inscription);
                    match ($r['issue']) {
                        InscriptionsPedagogiques::SANS_PARCOURS => $sansParcours[] = $matricule,
                        InscriptionsPedagogiques::SANS_MAQUETTE => $sansMaquette[$r['parcours']][] = $matricule,
                        default => $r['ues'] > 0 ? [$bilan['inscrites']++, $bilan['ues'] += $r['ues']] : $bilan['deja']++,
                    };
                }
            });

        $this->info(($appliquer ? 'Inscription pédagogique' : 'Essai à blanc').' '.$annee.' :');
        $this->line('  inscriptions '.($appliquer ? 'faites' : 'à faire').' : '.$bilan['inscrites'].($appliquer ? ' ('.$bilan['ues'].' UE)' : ''));
        $this->line('  déjà inscrites : '.$bilan['deja']);
        $this->line('  sans parcours reconnu : '.count($sansParcours).($sansParcours === [] ? '' : ' ('.implode(', ', $sansParcours).')'));
        foreach ($sansMaquette as $code => $matricules) {
            $this->line("  parcours {$code} sans maquette validée : ".count($matricules));
        }

        return self::SUCCESS;
    }
}
