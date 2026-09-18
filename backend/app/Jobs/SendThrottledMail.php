<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CandidatureRelance;
use App\Support\SecretRedactor;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoi d'un e-mail sous contrainte de cadence.
 *
 * Raison d'être : l'hébergement plafonne à 150 e-mails par heure et 1 500 par
 * jour. Sans bride, un envoi groupé dépasse la limite et le serveur répond
 * « 550 Stop! You are sending too fast » — 372 messages ont été perdus ainsi
 * entre le 3 et le 18 septembre 2026, dont 25 accusés de réception de
 * candidature. Avec `--tries=3`, chaque perte coûtait trois connexions
 * refusées, ce qui aggravait le dépassement.
 *
 * Un mailable `ShouldQueue` ne peut pas porter cette bride lui-même :
 * `SendQueuedMailable` relaie `retryUntil()` et `failed()`, mais **pas**
 * `middleware()`. D'où ce job, qui enveloppe l'envoi et porte le limiteur.
 *
 * `retryUntil()` plutôt que `$tries` : le middleware relâche le job à chaque
 * fois que le quota est atteint, et chaque relâche consommerait une tentative.
 */
final class SendThrottledMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $destinataires
     * @param  list<string>  $copiesCachees
     * @param  int|null  $relanceId  Ligne du journal des envois à marquer en échec
     *                               si l'envoi ne passe pas. Le journal affirmait
     *                               « envoyé » dès la mise en file, sans jamais
     *                               apprendre l'échec réel.
     */
    public function __construct(
        private readonly array $destinataires,
        private readonly Mailable $mailable,
        private readonly array $copiesCachees = [],
        private readonly ?int $relanceId = null,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('emails')];
    }

    /**
     * Fenêtre de report. Large : un envoi groupé de plusieurs centaines de
     * messages s'étale mécaniquement sur plusieurs heures à 100/heure.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(): void
    {
        $envoi = Mail::to($this->destinataires);

        if ($this->copiesCachees !== []) {
            $envoi->bcc($this->copiesCachees);
        }

        // `sendNow` et non `send` : le mailable est `ShouldQueue`, `send()` le
        // remettrait en file et court-circuiterait la bride.
        $envoi->sendNow($this->mailable);
    }

    public function failed(Throwable $e): void
    {
        $message = SecretRedactor::redact($e->getMessage()) ?? 'Motif inconnu.';

        Log::channel('single')->error('Envoi e-mail définitivement en échec.', [
            'mailable' => $this->mailable::class,
            'relance_id' => $this->relanceId,
            'error' => $message,
        ]);

        if ($this->relanceId === null) {
            return;
        }

        // Le journal sert de preuve : il doit dire l'échec, pas la mise en file.
        CandidatureRelance::whereKey($this->relanceId)->update([
            'statut' => CandidatureRelance::STATUT_ECHEC,
            'erreur' => mb_substr($message, 0, 500),
        ]);
    }
}
