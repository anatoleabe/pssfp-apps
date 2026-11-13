<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Erreurs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Erreurs\SignalementErreurPortailRequest;
use App\Services\Erreurs\JournalErreurs;
use Illuminate\Http\Response;

/** POST /v1/erreurs/portail — écran d'erreur du portail apply, relayé par son serveur. */
final class SignalementErreurPortailController extends Controller
{
    public function __invoke(SignalementErreurPortailRequest $request, JournalErreurs $journal): Response
    {
        /** @var array{page: string, message: string, digest?: string|null, navigateur?: string|null} $signalement */
        $signalement = $request->validated();
        $journal->depuisPortail($signalement, $request);

        return response()->noContent();
    }
}
