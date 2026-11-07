<?php

declare(strict_types=1);

namespace App\Filament\Resources\RegleEvaluationResource\Pages;

use App\Filament\Resources\RegleEvaluationResource;
use App\Models\RegleEvaluation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditRegleEvaluation extends EditRecord
{
    protected static string $resource = RegleEvaluationResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var RegleEvaluation $regle */
        $regle = $this->getRecord();

        // Le formulaire ne porte que les clés connues du jury : les autres sont conservées.
        $data['jury'] = array_replace((array) $regle->jury, (array) ($data['jury'] ?? []));

        return self::verifier($data);
    }

    /**
     * Pondérations à 100 % et grilles sans chevauchement (EF-72 du dossier PSSFP Système).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function verifier(array $data): array
    {
        $somme = (float) ($data['pcc_defaut'] ?? 0) + (float) ($data['ptp_defaut'] ?? 0) + (float) ($data['pexam_defaut'] ?? 0);
        if (abs($somme - 1) > 0.0001) {
            throw ValidationException::withMessages(['data.pexam_defaut' => 'La pondération doit faire 100 % au total.']);
        }

        $tranches = collect($data['grille_grades'] ?? [])->map(fn (array $t): array => ['min' => (float) $t['min'], 'max' => (float) $t['max']])
            ->sortBy('min')->values();
        foreach ($tranches as $i => $t) {
            if ($t['min'] >= $t['max']) {
                throw ValidationException::withMessages(['data.grille_grades' => 'Chaque tranche de la grille des grades doit avoir une borne basse inférieure à la borne haute.']);
            }
            if ($i > 0 && $t['min'] < $tranches[$i - 1]['max']) {
                throw ValidationException::withMessages(['data.grille_grades' => 'Deux tranches de la grille des grades se chevauchent.']);
            }
        }
        $data['grille_grades'] = array_values($data['grille_grades'] ?? []);
        $data['grille_mentions'] = collect($data['grille_mentions'] ?? [])->sortBy(fn (array $m): float => (float) $m['min'])->values()->all();

        return $data;
    }
}
