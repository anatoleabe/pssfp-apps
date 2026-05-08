<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Listeners\SendCandidatureDecisionEmail;
use App\Models\Candidature;
use App\Observers\CandidatureObserver;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\FakeSmsProvider;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsServiceInterface::class, function ($app) {
            return match (config('services.sms.provider', 'fake')) {
                'africas_talking' => $app->make(AfricasTalkingProvider::class),
                default => $app->make(FakeSmsProvider::class),
            };
        });
    }

    public function boot(): void
    {
        Candidature::observe(CandidatureObserver::class);

        Event::listen(CandidatureAccepted::class, [SendCandidatureDecisionEmail::class, 'handleAccepted']);
        Event::listen(CandidatureRefused::class, [SendCandidatureDecisionEmail::class, 'handleRefused']);
    }
}
