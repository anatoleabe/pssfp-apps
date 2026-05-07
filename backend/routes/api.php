<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * API publique PSSFP — préfixe global /v1 défini dans bootstrap/app.php.
 * Cf. docs/api-contract.md pour la liste exhaustive des endpoints.
 */

Route::get('/health', HealthController::class)->name('api.health');
