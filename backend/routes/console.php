<?php

use App\Models\DocumentDownload;
use App\Models\SearchLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

// Envois temporaires de Livewire (dont les exports WhatsApp, lot 13b-2) : un envoi non utilisé ne reste pas plus
// d'une heure sur le disque (le nettoyage de Livewire n'agit qu'après 24 h et seulement lors d'un nouvel envoi).
Schedule::call(function (): void {
    $disque = Storage::disk((string) (config('livewire.temporary_file_upload.disk') ?? 'local'));
    $limite = now()->subHour()->getTimestamp();
    foreach ($disque->files((string) (config('livewire.temporary_file_upload.directory') ?? 'livewire-tmp')) as $f) {
        if ($disque->lastModified($f) < $limite) {
            $disque->delete($f);
        }
    }
})->name('purge-envois-livewire')->everyFifteenMinutes()->withoutOverlapping();
