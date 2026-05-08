<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\AuthCandidatController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * API publique PSSFP — préfixe global /v1 défini dans bootstrap/app.php.
 * Cf. docs/api-contract.md pour la liste exhaustive des endpoints.
 */

Route::get('/health', HealthController::class)->name('api.health');

/*
 * Auth candidat (cf. ADR-0007 + spec module 5 §M4).
 *
 * - register / forgot-pin / verify-otp : non authentifiés.
 * - login : middleware throttle.candidat.login (3/10min IP, 10/30min phone, 50/24h IP).
 * - reset-pin : token court Sanctum ability `pin:reset` issu de verify-otp.
 * - logout : token candidat standard.
 */
Route::prefix('auth/candidat')->name('auth.candidat.')->group(function (): void {
    Route::post('/register', [AuthCandidatController::class, 'register'])->name('register');
    Route::post('/login', [AuthCandidatController::class, 'login'])
        ->middleware('throttle.candidat.login')
        ->name('login');
    Route::post('/forgot-pin', [AuthCandidatController::class, 'forgotPin'])->name('forgot-pin');
    Route::post('/verify-otp', [AuthCandidatController::class, 'verifyOtp'])->name('verify-otp');
    Route::post('/reset-pin', [AuthCandidatController::class, 'resetPin'])
        ->middleware(['auth:sanctum', 'ability:pin:reset'])
        ->name('reset-pin');
    Route::post('/logout', [AuthCandidatController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('logout');
});
