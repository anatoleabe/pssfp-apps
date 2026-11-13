<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Applications\CandidatureController;
use App\Http\Controllers\Api\Applications\EmploiDuTempsController;
use App\Http\Controllers\Api\Applications\LienLettreAccesController;
use App\Http\Controllers\Api\Applications\MemoireController;
use App\Http\Controllers\Api\Applications\ParcoursController;
use App\Http\Controllers\Api\Applications\QrCandidatureController;
use App\Http\Controllers\Api\Applications\RequetesController;
use App\Http\Controllers\Api\Applications\ResultatsController;
use App\Http\Controllers\Api\Applications\ScolariteController;
use App\Http\Controllers\Api\Articles\ArticleController;
use App\Http\Controllers\Api\Auth\AuthCandidatController;
use App\Http\Controllers\Api\Auth\ConsultationEspaceController;
use App\Http\Controllers\Api\Contact\ContactController;
use App\Http\Controllers\Api\Erreurs\SignalementErreurPortailController;
use App\Http\Controllers\Api\Library\CatalogueController;
use App\Http\Controllers\Api\Library\DocumentController as LibraryDocumentController;
use App\Http\Controllers\Api\Library\DownloadController;
use App\Http\Controllers\Api\Library\SearchController;
use App\Http\Controllers\Api\Library\ViewController;
use App\Http\Controllers\Api\Pages\PageController;
use App\Http\Controllers\Api\Public\VerificationDocumentController;
use App\Http\Controllers\Api\Reference\ReferenceController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * API publique PSSFP — préfixe global /v1 défini dans bootstrap/app.php.
 * Cf. docs/api-contract.md pour la liste exhaustive des endpoints.
 */

Route::get('/health', HealthController::class)->name('api.health');

// Vérification publique d'un document officiel (spec module 4, lot 7) : minimum de données.
Route::get('/documents/verification/{code}', VerificationDocumentController::class)
    ->middleware('throttle:30,1')
    ->name('documents.verification');

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
// ip.portail en tête : les limites « par IP » doivent voir le visiteur, pas le serveur du portail.
Route::prefix('auth/candidat')->name('auth.candidat.')->middleware('ip.portail')->group(function (): void {
    Route::post('/register', [AuthCandidatController::class, 'register'])
        ->middleware('throttle:20,1')
        ->name('register');
    Route::post('/login', [AuthCandidatController::class, 'login'])
        ->middleware('throttle.candidat.login')
        ->name('login');
    // Auditeurs : identifiants FOAD (compteurs anti-bruteforce dans le contrôleur).
    Route::post('/login-matricule', [AuthCandidatController::class, 'loginMatricule'])
        ->middleware('throttle:30,1')
        ->name('login-matricule');
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
    // Échange du lien « Voir son espace » de l'administration (usage unique, 1 min).
    Route::post('/consultation', ConsultationEspaceController::class)
        ->middleware('throttle:consultation-espace')
        ->name('consultation');
});

/*
 * Écrans d'erreur du portail apply, relayés par son serveur (secret partagé
 * exigé par la FormRequest) : visibles dans l'administration, « Erreurs
 * techniques ». ip.portail en tête : la limite vise le visiteur.
 */
Route::post('/erreurs/portail', SignalementErreurPortailController::class)
    ->middleware(['ip.portail', 'throttle:signalement-erreurs'])
    ->name('erreurs.portail');

/*
 * Lien court de la lettre d'accès FOAD envoyé par SMS : public, sans compte,
 * date de naissance exigée (LiensLettreAcces). Le GET ne consomme rien : les
 * aperçus de liens des messageries peuvent l'appeler sans dommage.
 */
Route::prefix('lettre-acces')->name('lettre-acces.')->middleware('ip.portail')->group(function (): void {
    Route::get('/{jeton}', [LienLettreAccesController::class, 'show'])->middleware('throttle:lien-lettre-acces-lecture')->name('lien');
    Route::post('/{jeton}', [LienLettreAccesController::class, 'telecharger'])->middleware('throttle:lien-lettre-acces')->name('telecharger');
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

    Route::get('/campaigns/resultats', [CandidatureController::class, 'resultatsCampagne'])
        ->name('campaigns.resultats');

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

        Route::get('/me/lettre-admission', [CandidatureController::class, 'lettreAdmission'])
            ->middleware('ability:application:read')
            ->name('me.lettre-admission');

        // Scolarité de l'auditeur (spec module 4, lot 1).
        Route::get('/me/scolarite', [ScolariteController::class, 'show'])
            ->middleware('ability:application:read')
            ->name('me.scolarite');

        // Parcours LMD de l'auditeur (spec module 4, lot 4).
        Route::get('/me/parcours', [ParcoursController::class, 'show'])
            ->middleware('ability:application:read')
            ->name('me.parcours');

        Route::get('/me/assiduite', [ParcoursController::class, 'assiduite'])
            ->middleware('ability:application:read')
            ->name('me.assiduite');

        Route::get('/me/calendrier', [ParcoursController::class, 'calendrier'])
            ->middleware('ability:application:read')
            ->name('me.calendrier');

        // Emploi du temps de l'auditeur (spec module 4, lot 12c).
        Route::get('/me/emploi-du-temps', [EmploiDuTempsController::class, 'show'])
            ->middleware('ability:application:read')
            ->name('me.emploi-du-temps');

        Route::get('/me/calendrier/communiques/{uuid}', [ParcoursController::class, 'communique'])
            ->middleware(['ability:application:read', 'throttle:30,1'])
            ->whereUuid('uuid')
            ->name('me.calendrier.communique');

        // Résultats publiés et documents officiels de l'auditeur (spec module 4, lot 7).
        Route::get('/me/resultats', [ResultatsController::class, 'index'])
            ->middleware('ability:application:read')
            ->name('me.resultats');

        Route::get('/me/documents-officiels/{type}', [ResultatsController::class, 'document'])
            ->whereIn('type', ['releve_notes', 'attestation_reussite', 'certificat_scolarite'])
            ->middleware(['ability:application:read', 'throttle:releve-scolarite'])
            ->name('me.documents-officiels');

        // Requêtes de l'auditeur (spec module 4, lot 8).
        Route::get('/me/requetes', [RequetesController::class, 'index'])->middleware('ability:application:read')->name('me.requetes');
        Route::post('/me/requetes', [RequetesController::class, 'store'])->middleware(['ability:application:read', 'throttle:20,60'])->name('me.requetes.store');
        Route::post('/me/requetes/{uuid}/annuler', [RequetesController::class, 'annuler'])->middleware('ability:application:read')->name('me.requetes.annuler');
        Route::get('/me/requetes/{uuid}/document', [RequetesController::class, 'document'])->middleware('ability:application:read')->name('me.requetes.document');

        // Mémoire de l'auditeur (spec module 4, lot 11).
        Route::get('/me/memoire', [MemoireController::class, 'show'])->middleware('ability:application:read')->name('me.memoire');
        Route::post('/me/memoire/depots/{etape}', [MemoireController::class, 'deposer'])
            ->whereIn('etape', ['cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2', 'depot_final'])
            ->middleware(['ability:application:read', 'throttle:20,60'])->name('me.memoire.deposer');
        Route::get('/me/memoire/attestation', [MemoireController::class, 'attestation'])
            ->middleware(['ability:application:read', 'throttle:releve-scolarite'])->name('me.memoire.attestation');

        // Reçu d'un versement (spec module 4, Scolarité lot 4).
        Route::get('/me/scolarite/recus/{uuid}', [ScolariteController::class, 'recu'])
            ->whereUuid('uuid')
            ->middleware(['ability:application:read', 'throttle:recus-scolarite'])
            ->name('me.scolarite.recu');

        Route::get('/me/releve-scolarite', [ScolariteController::class, 'releve'])
            ->middleware(['ability:application:read', 'throttle:releve-scolarite'])
            ->name('me.releve-scolarite');

        Route::post('/me/acces/reveler', [ScolariteController::class, 'reveler'])
            ->middleware(['ability:application:read', 'throttle:acces-ecampus'])
            ->name('me.acces.reveler');

        Route::get('/me/acces/lettre', [ScolariteController::class, 'lettreAcces'])
            ->middleware(['ability:application:read', 'throttle:lettre-acces'])
            ->name('me.acces.lettre');

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
 * Bibliothèque virtuelle (Module 3 V1 — docs/specs/module-3-bibliotheque-v1-2026-10.md §5.3).
 * Lecture seule et sans authentification en V1.
 *
 * Débit par IP (limiteurs nommés, AppServiceProvider) : 120/min sur tout le groupe,
 * plus serré sur la recherche (60), le téléchargement (30) et les consultations (20).
 * Plafonds et IP exemptées dans config/library.php (LIBRARY_RATE_LIMIT*, …_EXEMPT_IPS).
 */
Route::prefix('library')->name('library.')->middleware('throttle:library')->group(function (): void {
    Route::get('/stats', [CatalogueController::class, 'stats'])->name('stats');
    Route::get('/mots-cles', [CatalogueController::class, 'motsCles'])->name('mots-cles');
    Route::get('/redirects', [CatalogueController::class, 'redirect'])->name('redirects');
    Route::get('/search', SearchController::class)->middleware('throttle:library-search')->name('search');
    Route::get('/documents', [LibraryDocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{uuid}/download', DownloadController::class)
        ->middleware('throttle:library-download')
        ->name('documents.download');
    Route::post('/documents/{uuid}/view', ViewController::class)
        ->middleware('throttle:library-view')
        ->name('documents.view');
    Route::get('/documents/{slug}', [LibraryDocumentController::class, 'show'])->name('documents.show');
});

/*
 * Contact (cf. spec module 1 PR O).
 *
 * Rate-limit 5 messages / IP / heure côté serveur.
 */
Route::post('/contact', [ContactController::class, 'send'])->name('contact.send');
