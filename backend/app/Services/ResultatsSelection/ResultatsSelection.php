<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Support\NombreEnLettres;
use Illuminate\Support\Str;

/**
 * Tout ce que le communiqué et le PV affichent, calculé une seule fois puis
 * rendu dans les deux documents Word.
 */
final readonly class ResultatsSelection
{
    /**
     * @param  list<CandidatRetenu>  $nationaux
     * @param  list<CandidatRetenu>  $etrangers
     * @param  list<array{nom: string, qualite: string, fonction: string, role: string}>  $jury
     */
    public function __construct(
        public int $promotionNumero,
        public string $anneeAcademique,
        public int $totalEnLigne,
        public Repartition $parPays,
        public Repartition $parGenre,
        public Repartition $parMode,
        public array $nationaux,
        public array $etrangers,
        public array $jury,
        public SeanceJury $seance,
        /** Dossiers écartés parce que la même personne en avait plusieurs. */
        public int $doublonsRetires,
        public ProfilRetenus $profil,
    ) {}

    public function totalRetenus(): int
    {
        return count($this->nationaux) + count($this->etrangers);
    }

    /** « QUATORZIEME » — majuscules sans accent, comme dans les titres des PV. */
    public function promotionOrdinal(): string
    {
        return Str::upper(Str::ascii(NombreEnLettres::ordinal($this->promotionNumero)));
    }

    public function promotionAbregee(): string
    {
        return $this->promotionNumero.($this->promotionNumero === 1 ? 're' : 'e');
    }

    public function anneeAcademiqueSlash(): string
    {
        return str_replace('-', '/', $this->anneeAcademique);
    }

    /** @return array{nom: string, qualite: string, fonction: string, role: string}|null */
    public function membre(string $role): ?array
    {
        foreach ($this->jury as $membre) {
            if ($membre['role'] === $role) {
                return $membre;
            }
        }

        return null;
    }

    /** @return list<array{nom: string, qualite: string, fonction: string, role: string}> */
    public function membresSimples(): array
    {
        return array_values(array_filter($this->jury, fn (array $m): bool => $m['role'] === 'membre'));
    }
}
