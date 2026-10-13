<?php

declare(strict_types=1);

namespace App\Services\ResultatsSelection;

use App\Support\NombreEnLettres;
use Carbon\CarbonImmutable;

/**
 * Éléments propres à une séance du jury, saisis au moment du téléchargement.
 *
 * Tout est facultatif : un champ vide sort en pointillés pour être complété
 * à la main avant signature, comme sur les modèles Word de la scolarité.
 */
final readonly class SeanceJury
{
    public const BLANC = '……………';

    public function __construct(
        public ?CarbonImmutable $dateDeliberation = null,
        public ?string $heureDeliberation = null,
        public ?string $noteServiceReference = null,
        public ?CarbonImmutable $noteServiceDate = null,
        /** Numéro d'enregistrement du PV ou du communiqué. */
        public ?string $numero = null,
    ) {}

    public static function vide(): self
    {
        return new self;
    }

    /** @param  array<string, mixed>  $donnees  état du formulaire Filament */
    public static function depuisFormulaire(array $donnees): self
    {
        return new self(
            dateDeliberation: self::date($donnees['date_deliberation'] ?? null),
            heureDeliberation: self::texte($donnees['heure_deliberation'] ?? null),
            noteServiceReference: self::texte($donnees['note_service_reference'] ?? null),
            noteServiceDate: self::date($donnees['note_service_date'] ?? null),
            numero: self::texte($donnees['numero'] ?? null),
        );
    }

    /** « L'an deux-mille-vingt-six et le 1er du mois d'octobre, à 10 heures » */
    public function dateEnLettres(): string
    {
        $date = $this->dateDeliberation;
        $heure = $this->heureDeliberation ?? self::BLANC.' heures';

        if ($date === null) {
            return 'L’an '.self::BLANC.' et le '.self::BLANC.' du mois de '.self::BLANC.', à '.$heure;
        }

        $jour = $date->day === 1 ? '1er' : (string) $date->day;
        $mois = $date->locale('fr')->translatedFormat('F');
        $de = in_array($mois[0], ['a', 'o'], true) ? 'd’' : 'de ';

        return 'L’an '.NombreEnLettres::cardinal($date->year).' et le '.$jour.' du mois '.$de.$mois.', à '.$heure;
    }

    /** « n°26-801/UYII/FSEG-D du 22 septembre 2026 » */
    public function noteService(): string
    {
        $date = $this->noteServiceDate?->locale('fr')->translatedFormat('j F Y') ?? self::BLANC;

        return 'n°'.($this->noteServiceReference ?? self::BLANC).' du '.$date;
    }

    public function referencePv(): string
    {
        return $this->reference((string) config('resultats_selection.reference_pv'));
    }

    /** Date portée en en-tête courant du PV : « 01-10-2026 ». */
    public function dateCourte(): string
    {
        return $this->dateDeliberation?->format('d-m-Y') ?? self::BLANC;
    }

    /** Préfixe des numéros d'enregistrement : « 26 » pour 2026. */
    public function anneeReference(): string
    {
        return ($this->dateDeliberation ?? CarbonImmutable::now())->format('y');
    }

    private function reference(string $suffixe): string
    {
        return 'N°'.$this->anneeReference().'/'.($this->numero ?? self::BLANC).'/'.$suffixe;
    }

    private static function date(mixed $valeur): ?CarbonImmutable
    {
        return is_string($valeur) && $valeur !== '' ? CarbonImmutable::parse($valeur) : null;
    }

    private static function texte(mixed $valeur): ?string
    {
        $valeur = is_string($valeur) ? trim($valeur) : '';

        return $valeur === '' ? null : $valeur;
    }
}
