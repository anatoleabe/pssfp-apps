<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OtpService;
use Illuminate\Console\Command;

/**
 * Cron quotidien (cf. bootstrap/app.php : `->daily()->at('03:30')`).
 *
 * Supprime les enregistrements `sms_otps` expirés depuis plus de 7 jours.
 * On garde une fenêtre de 7 jours pour permettre des audits forensiques
 * en cas d'incident (qui a tenté de réinitialiser un PIN sur cette plage).
 */
final class PurgeExpiredOtps extends Command
{
    protected $signature = 'otp:purge {--days=7 : Conservation post-expiration en jours}';

    protected $description = 'Supprime les OTP expirés au-delà de la fenêtre de conservation.';

    public function handle(OtpService $service): int
    {
        $days = (int) $this->option('days');
        $deleted = $service->purgeExpired($days);

        $this->info("Purge OTP : {$deleted} entrée(s) supprimée(s) (older than {$days} days).");

        return self::SUCCESS;
    }
}
