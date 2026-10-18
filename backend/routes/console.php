<?php

use App\Models\DocumentDownload;
use App\Models\SearchLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Bibliothèque (ADR-0003) : réindexation hebdomadaire complète, filet de
 * sécurité si une indexation en file a échoué. Sans effet hors Meilisearch.
 */
Schedule::command('scout:import', ['App\\Models\\Document'])
    ->weeklyOn(0, '03:30')
    ->when(fn (): bool => config('scout.driver') === 'meilisearch')
    ->withoutOverlapping();

// Contrôle de cohérence base/index, une heure après la réindexation hebdomadaire.
Schedule::command('library:check-index')
    ->weeklyOn(0, '04:30')
    ->when(fn (): bool => config('scout.driver') === 'meilisearch')
    ->withoutOverlapping();

// RGPD (spec V1 §5.1) : recherches purgées à 90 jours, téléchargements à 180 jours.
Schedule::command('model:prune', ['--model' => [SearchLog::class, DocumentDownload::class]])
    ->dailyAt('02:00')
    ->withoutOverlapping();
