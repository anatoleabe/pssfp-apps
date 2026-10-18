<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Library;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consultation d'une fiche, signalée par le navigateur (spec V1 §6).
 *
 * Les fiches sont servies en ISR : compter au rendu compterait les
 * régénérations, pas les lecteurs. Une consultation par IP et par heure.
 */
final class ViewController extends Controller
{
    private const DEDUP_MINUTES = 60;

    public function __invoke(Request $request, string $uuid): Response
    {
        $document = Str::isUuid($uuid) ? Document::query()->published()->where('uuid', $uuid)->first() : null;
        abort_if($document === null, Response::HTTP_NOT_FOUND);

        $key = 'library:view:'.$document->id.':'.sha1((string) $request->ip());
        if (Cache::add($key, true, now()->addMinutes(self::DEDUP_MINUTES))) {
            DB::table('documents')->where('id', $document->id)->increment('views_count');
        }

        return response()->noContent();
    }
}
