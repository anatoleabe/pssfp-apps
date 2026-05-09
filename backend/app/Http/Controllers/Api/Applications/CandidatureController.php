<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Applications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Applications\SubmitCandidatureRequest;
use App\Http\Requests\Applications\UpdateCandidatureRequest;
use App\Http\Requests\Applications\WithdrawCandidatureRequest;
use App\Http\Resources\CampagneCandidatureResource;
use App\Http\Resources\CandidatureResource;
use App\Services\CandidatureService;
use App\Services\RecipisseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints /v1/applications/* (cf. spec module 5 §3, §M5).
 *
 * - currentCampaign : campagne ouverte ou null (public).
 * - me              : récupère le dossier du candidat authentifié pour la campagne courante.
 * - update          : upsert + partial update tant que statut == postulant.
 * - submit          : transition vers candidat, génère PDF, retourne URL signée.
 * - recipisse       : redirige vers URL signée 30 min (re-signée à chaque appel).
 */
final class CandidatureController extends Controller
{
    public function __construct(
        private readonly CandidatureService $service,
        private readonly RecipisseService $recipisse,
    ) {}

    public function currentCampaign(): JsonResponse
    {
        $campagne = $this->service->currentCampagne();

        if ($campagne === null) {
            return response()->json(['data' => null], 200);
        }

        return CampagneCandidatureResource::make($campagne)->response();
    }

    public function me(Request $request): JsonResponse
    {
        $campagne = $this->service->currentCampagne();
        if ($campagne === null) {
            return response()->json([
                'message' => 'Aucune campagne de candidature n\'est ouverte actuellement.',
            ], Response::HTTP_NOT_FOUND);
        }

        $candidature = $this->service->findForUser($request->user(), $campagne);
        if ($candidature === null) {
            return response()->json([
                'message' => 'Aucune candidature n\'a encore été créée pour cette campagne.',
            ], Response::HTTP_NOT_FOUND);
        }

        $candidature->load(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        return CandidatureResource::make($candidature)->response();
    }

    public function update(UpdateCandidatureRequest $request): JsonResponse
    {
        $campagne = $this->service->currentCampagne();
        if ($campagne === null) {
            return response()->json([
                'message' => 'Aucune campagne ouverte — impossible de créer ou éditer un dossier.',
            ], Response::HTTP_CONFLICT);
        }

        $candidature = $this->service->upsertForUser($request->user(), $campagne);

        try {
            $candidature = $this->service->updateDraft($candidature, $request->validated());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $candidature->load(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        // PUT upsert : 200 quel que soit le passage (création ou update). Évite le
        // 201 émis par JsonResource quand wasRecentlyCreated=true sur la 1re passe.
        return CandidatureResource::make($candidature)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function submit(SubmitCandidatureRequest $request): JsonResponse
    {
        $campagne = $this->service->currentCampagne();
        if ($campagne === null) {
            return response()->json([
                'message' => 'La campagne est fermée — soumission impossible.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $candidature = $this->service->findForUser($request->user(), $campagne);
        if ($candidature === null) {
            return response()->json([
                'message' => 'Aucune candidature à soumettre.',
            ], Response::HTTP_NOT_FOUND);
        }

        $errors = $this->service->checkSubmittable($candidature);
        if ($errors !== []) {
            return response()->json([
                'message' => 'La candidature ne peut pas être soumise : champs ou règles métier manquants.',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->service->submit(
                $candidature,
                $request->header('X-Idempotency-Key'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $result['candidature']->load(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        return response()->json([
            'data' => CandidatureResource::make($result['candidature'])->resolve(),
            'recipisse_url' => $result['pdf_url'],
        ], Response::HTTP_OK);
    }

    public function recipisse(Request $request): RedirectResponse|JsonResponse
    {
        $campagne = $this->service->currentCampagne();
        if ($campagne === null) {
            return response()->json([
                'message' => 'Aucune campagne ouverte.',
            ], Response::HTTP_NOT_FOUND);
        }

        $candidature = $this->service->findForUser($request->user(), $campagne);
        if ($candidature === null || $candidature->recipisse_pdf_path === null) {
            return response()->json([
                'message' => 'Aucun récépissé disponible — la candidature n\'a pas encore été soumise.',
            ], Response::HTTP_NOT_FOUND);
        }

        $url = $this->recipisse->signedUrl($candidature->recipisse_pdf_path);

        return redirect()->away($url, 302);
    }

    public function withdraw(WithdrawCandidatureRequest $request): JsonResponse
    {
        $campagne = $this->service->currentCampagne();
        if ($campagne === null) {
            return response()->json([
                'message' => 'Aucune campagne ouverte.',
            ], Response::HTTP_NOT_FOUND);
        }

        $candidature = $this->service->findForUser($request->user(), $campagne);
        if ($candidature === null) {
            return response()->json([
                'message' => 'Aucune candidature à retirer.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->service->withdraw($candidature);
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'already_withdrawn' => 'Candidature déjà retirée.',
                'already_decided' => 'Cette candidature a déjà été décidée par le comité, le retrait administratif ne peut plus être fait par le candidat.',
                default => $e->getMessage(),
            };

            return response()->json([
                'message' => $message,
                'kind' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        $candidature->load(['campagne', 'paysNationalite', 'regionRel', 'departementRel']);

        return CandidatureResource::make($candidature)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
