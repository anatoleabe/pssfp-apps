<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Applications\CandidatureController;
use App\Http\Controllers\Api\Applications\QrCandidatureController;
use App\Http\Controllers\Api\Articles\ArticleController;
use App\Http\Controllers\Api\Auth\AuthCandidatController;
use App\Http\Controllers\Api\Contact\ContactController;
use App\Http\Controllers\Api\Pages\PageController;
use App\Http\Controllers\Api\Reference\ReferenceController;
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
 *
 * Backstop anti-abus par IP sur register/forgot-pin : défense en profondeur
 * indépendante de Turnstile (qui fail-open si CF est injoignable). Protège
 * notamment le crédit SMS OTP (spec §M13). Un cooldown par numéro dans
 * OtpService est à ajouter au branchement du vrai fournisseur SMS (LOT B).
 */
Route::prefix('auth/candidat')->name('auth.candidat.')->group(function (): void {
    Route::post('/register', [AuthCandidatController::class, 'register'])
        ->middleware('throttle:20,1')
        ->name('register');
    Route::post('/login', [AuthCandidatController::class, 'login'])
        ->middleware('throttle.candidat.login')
        ->name('login');
    Route::post('/forgot-pin', [AuthCandidatController::class, 'forgotPin'])
        ->middleware('throttle:10,1')
        ->name('forgot-pin');
    Route::post('/verify-otp', [AuthCandidatController::class, 'verifyOtp'])
        ->middleware('throttle:30,1')
        ->name('verify-otp');
    Route::post('/reset-pin', [AuthCandidatController::class, 'resetPin'])
        ->middleware(['auth:sanctum', 'ability:pin:reset'])
        ->name('reset-pin');
    Route::post('/logout', [AuthCandidatController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('logout');
});

/*
 * Référentiels publics (cache 24h Redis).
 */
Route::prefix('reference')->name('reference.')->group(function (): void {
    Route::get('/pays', [ReferenceController::class, 'pays'])->name('pays');
    Route::get('/regions-cameroun', [ReferenceController::class, 'regions'])->name('regions');
    Route::get('/departements-cameroun', [ReferenceController::class, 'departements'])->name('departements');
    Route::get('/specialites', [ReferenceController::class, 'specialites'])->name('specialites');
    Route::get('/diplomes', [ReferenceController::class, 'diplomes'])->name('diplomes');
    Route::get('/universites', [ReferenceController::class, 'universites'])->name('universites');
    Route::get('/employeurs-publics', [ReferenceController::class, 'employeursPublics'])->name('employeurs-publics');
});

/*
 * Applications candidat (auth Sanctum + abilities scoped).
 *
 * - currentCampaign : public (le frontend l'affiche aussi avant login).
 * - me / update / submit / recipisse : auth + abilities.
 */
Route::prefix('applications')->name('applications.')->group(function (): void {
    Route::get('/campaigns/current', [CandidatureController::class, 'currentCampaign'])
        ->name('campaigns.current');

    Route::middleware(['auth:sanctum'])->group(function (): void {
        Route::get('/me', [CandidatureController::class, 'me'])
            ->middleware('ability:application:read')
            ->name('me.show');

        Route::put('/me', [CandidatureController::class, 'update'])
            ->middleware('ability:application:create,profile:write')
            ->name('me.update');

        Route::post('/me/submit', [CandidatureController::class, 'submit'])
            ->middleware('ability:application:submit')
            ->name('me.submit');

        Route::get('/me/recipisse', [CandidatureController::class, 'recipisse'])
            ->middleware('ability:application:read')
            ->name('me.recipisse');

        Route::post('/me/withdraw', [CandidatureController::class, 'withdraw'])
            ->middleware('ability:application:create')
            ->name('me.withdraw');

        Route::post('/me/photo', [CandidatureController::class, 'uploadPhoto'])
            ->middleware('ability:application:create')
            ->name('me.photo.upload');

        Route::get('/me/photo', [CandidatureController::class, 'showPhoto'])
            ->middleware('ability:application:read')
            ->name('me.photo.show');

        Route::delete('/me/photo', [CandidatureController::class, 'deletePhoto'])
            ->middleware('ability:application:create')
            ->name('me.photo.delete');

        // Pièces justificatives optionnelles (LOT candidature P14) — cf.
        // migration candidature_documents. Non-bloquantes pour submit.
        Route::post('/me/documents', [CandidatureController::class, 'uploadDocument'])
            ->middleware('ability:application:create')
            ->name('me.documents.upload');

        Route::delete('/me/documents/{document:uuid}', [CandidatureController::class, 'deleteDocument'])
            ->middleware('ability:application:create')
            ->name('me.documents.delete');
    });
});

/*
 * Page publique fallback du QR scanné depuis le PDF récépissé (cf. ajout 1 PR C).
 */
Route::get('/c/{uuid}/qr', QrCandidatureController::class)->name('candidature.qr');

/*
 * Pages institutionnelles éditables via Filament (cf. spec module 1 PR K).
 *
 * - GET /v1/pages          : liste, filtres ?parent_slug=, ?in_menu=true
 * - GET /v1/pages/{slug}   : détail (slug peut contenir des slashs URL-encodés)
 * - GET /v1/menu           : structure de navigation prête à consommer côté frontend
 */
Route::prefix('pages')->name('pages.')->group(function (): void {
    Route::get('/', [PageController::class, 'index'])->name('index');
    // Le slug peut contenir des slashs encodés — pattern .* pour matcher tout
    // (ex: pssfp%2Fpresentation ou pssfp/presentation).
    Route::get('/{slug}', [PageController::class, 'show'])
        ->where('slug', '.*')
        ->name('show');
});

Route::get('/menu', [PageController::class, 'menu'])->name('menu');

/*
 * Articles d'actualités (cf. spec module 1 PR N).
 *
 * - GET /v1/articles          : liste publiée paginée 9/page, filtres ?category= ?featured=
 * - GET /v1/articles/{slug}   : détail d'un article
 */
Route::prefix('articles')->name('articles.')->group(function (): void {
    Route::get('/', [ArticleController::class, 'index'])->name('index');
    Route::get('/{slug}', [ArticleController::class, 'show'])->name('show');
});

/*
 * Contact (cf. spec module 1 PR O).
 *
 * Rate-limit 5 messages / IP / heure côté serveur.
 */
Route::post('/contact', [ContactController::class, 'send'])->name('contact.send');
