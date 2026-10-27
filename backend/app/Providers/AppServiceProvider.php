<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Candidature;
use App\Observers\CandidatureObserver;
use App\Services\Library\Files\CoverRenderer;
use App\Services\Library\Files\PdftoppmCoverRenderer;
use App\Services\Library\Search\DatabaseDocumentSearch;
use App\Services\Library\Search\DocumentSearch;
use App\Services\Library\Search\FallbackDocumentSearch;
use App\Services\Library\Search\MeilisearchDocumentSearch;
use App\Services\Scanner\NoopPhotoScanner;
use App\Services\Scanner\PhotoScannerInterface;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\EchoSmsProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Sms\TechSoftProvider;
use App\Support\JournalModificationsAdmin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsServiceInterface::class, function ($app) {
            return match (config('services.sms.provider', 'fake')) {
                'africas_talking' => $app->make(AfricasTalkingProvider::class),
                'echosms' => $app->make(EchoSmsProvider::class),
                'techsoft' => $app->make(TechSoftProvider::class),
                default => $app->make(FakeSmsProvider::class),
            };
        });

        // Photo scanner (cf. spec PR G). Noop par défaut V1 — la version
        // ClamAV (socket clamd) sera bindée quand l'agent sera déployé sur Contabo.
        $this->app->bind(PhotoScannerInterface::class, NoopPhotoScanner::class);

        $this->app->bind(CoverRenderer::class, PdftoppmCoverRenderer::class);

        $this->app->bind(DocumentSearch::class, function (): DocumentSearch {
            $database = new DatabaseDocumentSearch;

            return config('scout.driver') === 'meilisearch'
                ? new FallbackDocumentSearch(new MeilisearchDocumentSearch, $database)
                : $database;
        });
    }

    public function boot(): void
    {
        // L'hébergement plafonne à 150 e-mails par heure et 1 500 par jour.
        // On reste en dessous : le formulaire de contact et les envois de test
        // partent en synchrone, hors de cette bride, et doivent garder de la
        // marge. Dépasser coûte un « 550 sending too fast » et un message perdu.
        RateLimiter::for('emails', fn (): array => [
            Limit::perHour(100),
            Limit::perDay(1200),
        ]);

        $this->configureLibraryRateLimits();

        Candidature::observe(CandidatureObserver::class);

        // Journal d'activité : toute modification faite depuis le back-office.
        JournalModificationsAdmin::enregistrer();

        // Listeners email candidature (soumission + décisions) : enregistrés
        // par l'auto-découverte Laravel 11 (app/Listeners, méthodes handle*).
        // Ne PAS les ré-enregistrer ici — un Event::listen manuel les ferait
        // tourner deux fois (double email candidat, cf. event:list).
    }

    /**
     * Limiteurs de la bibliothèque (`throttle:library`, `library-search`, `library-download`,
     * `library-view`) : plafonds par IP et par minute lus dans `config/library.php`, sauf pour
     * les IP exemptées (serveur Next.js en prod).
     */
    private function configureLibraryRateLimits(): void
    {
        $limiters = [
            'library' => 'library',
            'library-search' => 'search',
            'library-download' => 'download',
            'library-view' => 'view',
        ];

        foreach ($limiters as $name => $configKey) {
            RateLimiter::for($name, function (Request $request) use ($configKey): Limit {
                $ip = (string) $request->ip();

                if (in_array($ip, (array) config('library.rate_limit_exempt_ips', []), true)) {
                    return Limit::none();
                }

                return Limit::perMinute((int) config("library.rate_limits.{$configKey}"))->by($ip);
            });
        }
    }
}
