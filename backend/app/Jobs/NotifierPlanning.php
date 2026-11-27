<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Services\NotificationCandidatsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Prévient des auditeurs d'un changement d'emploi du temps ou de planning d'examens (spec lot 28).
 * En file d'attente : une panne d'e-mail ou de SMS n'annule ni la publication ni le report.
 */
final class NotifierPlanning implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** @param  list<int>  $candidatures  identifiants des dossiers à prévenir */
    public function __construct(public readonly array $candidatures, public readonly string $sujet, public readonly string $texte,
        public readonly bool $email, public readonly bool $sms) {}

    public function handle(NotificationCandidatsService $notifications): void
    {
        $dossiers = Candidature::query()->whereKey($this->candidatures)->get();
        foreach ([NotificationCandidatsService::CANAL_EMAIL => $this->email, NotificationCandidatsService::CANAL_SMS => $this->sms] as $canal => $actif) {
            if ($actif) {
                $notifications->envoyer($dossiers, $canal, $this->texte, $this->sujet, null, dejaRendu: true, cause: CandidatureRelance::CAUSE_PLANNING);
            }
        }
    }
}
