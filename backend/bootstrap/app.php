<?php

declare(strict_types=1);

use App\Console\Commands\PurgeExpiredOtps;
use App\Http\Middleware\ThrottleCandidatLogin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            // Endpoints publics dépourvus de CSRF (Sanctum couvre les requêtes stateful)
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'throttle.candidat.login' => ThrottleCandidatLogin::class,
            // Sanctum abilities — alias par défaut Laravel 10 absents en Laravel 11.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(PurgeExpiredOtps::class)->daily()->at('03:30');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
