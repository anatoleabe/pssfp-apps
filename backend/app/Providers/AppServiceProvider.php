<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Candidature;
use App\Observers\CandidatureObserver;
use App\Services\Scanner\NoopPhotoScanner;
use App\Services\Scanner\PhotoScannerInterface;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\EchoSmsProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use App\Services\Sms\TechSoftProvider;
use Illuminate\Cache\RateLimiting\Limit;
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

        Candidature::observe(CandidatureObserver::class);

        // Listeners email candidature (soumission + décisions) : enregistrés
        // par l'auto-découverte Laravel 11 (app/Listeners, méthodes handle*).
        // Ne PAS les ré-enregistrer ici — un Event::listen manuel les ferait
        // tourner deux fois (double email candidat, cf. event:list).
    }
}
