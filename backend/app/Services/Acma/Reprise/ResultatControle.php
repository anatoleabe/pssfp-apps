<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

/** Résultat d'un contrôle d'équivalence (§10.5). `ok` vaut null pour un contrôle informatif ou non applicable. */
final class ResultatControle
{
    /** @param  list<string>  $details  lignes sans donnée nominative */
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly bool $bloquant,
        public readonly ?bool $ok,
        public readonly string $attendu,
        public readonly string $obtenu,
        public readonly array $details = [],
    ) {}

    public function enEchec(): bool
    {
        return $this->bloquant && $this->ok === false;
    }

    public function statut(): string
    {
        return match (true) {
            $this->ok === null => 'informatif',
            $this->ok => 'conforme',
            default => $this->bloquant ? 'ÉCHEC' : 'écart',
        };
    }

    /** @return array<string, mixed> */
    public function versTableau(): array
    {
        return [
            'code' => $this->code, 'libelle' => $this->libelle, 'bloquant' => $this->bloquant, 'ok' => $this->ok,
            'attendu' => $this->attendu, 'obtenu' => $this->obtenu, 'details' => $this->details,
        ];
    }
}
