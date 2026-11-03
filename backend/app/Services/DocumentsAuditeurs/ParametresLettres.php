<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\CampagneCandidature;
use Carbon\CarbonImmutable;

/**
 * Variables des documents d'une promotion : celles saisies sur la campagne,
 * complétées par les valeurs par défaut de config/documents_auditeurs.php.
 *
 * Aucune date n'a de valeur par défaut : une lettre datée de l'an passé ou
 * annonçant la mauvaise rentrée ne doit jamais partir. `manquants()` liste ce
 * qu'il reste à saisir avant de pouvoir générer un document. La signature n'en
 * fait pas partie : sans scan déposé, les lettres se signent à la main.
 */
final class ParametresLettres
{
    public const DOCUMENT_ACCES = 'acces';

    public const DOCUMENT_ADMISSION = 'admission';

    /** Champs requis par document, avec leur libellé dans l'admin. */
    private const REQUIS = [
        self::DOCUMENT_ACCES => [
            'reference_acces' => 'Référence des lettres d\'accès FOAD',
            'date_lettres' => 'Date des lettres',
        ],
        self::DOCUMENT_ADMISSION => [
            'reference_admission' => 'Référence des lettres d\'admission',
            'date_lettres' => 'Date des lettres',
            'rentree_date' => 'Date de la rentrée solennelle',
            'rentree_heure' => 'Heure de la rentrée',
            'rentree_lieu' => 'Lieu de la rentrée',
            'frais_inscription_montant' => 'Montant des frais d\'inscription',
            'frais_inscription_echeance' => 'Échéance des frais d\'inscription',
            'frais_tranche1_montant' => 'Montant de la 1re tranche',
            'frais_tranche1_date' => 'Échéance de la 1re tranche',
            'frais_tranche2_montant' => 'Montant de la 2e tranche',
            'frais_tranche2_date' => 'Échéance de la 2e tranche',
        ],
    ];

    /** @param  array<string, mixed>  $valeurs */
    private function __construct(
        public readonly CampagneCandidature $campagne,
        private readonly array $valeurs,
        public readonly ?string $signature,
    ) {}

    public static function pour(CampagneCandidature $campagne): self
    {
        $saisies = array_filter((array) $campagne->parametres_lettres, fn ($v): bool => filled($v));

        return new self(
            $campagne,
            array_merge((array) config('documents_auditeurs.defauts'), $saisies),
            filled($campagne->signature_lettres_path) ? (string) $campagne->signature_lettres_path : null,
        );
    }

    /** @return list<string> libellés des champs à saisir avant de générer ce document */
    public function manquants(string $document): array
    {
        $manquants = [];
        foreach (self::REQUIS[$document] as $cle => $libelle) {
            if (blank($this->valeurs[$cle] ?? null)) {
                $manquants[] = $libelle;
            }
        }

        return $manquants;
    }

    public function texte(string $cle): string
    {
        return trim((string) ($this->valeurs[$cle] ?? ''));
    }

    public function montant(string $cle): string
    {
        return number_format((int) ($this->valeurs[$cle] ?? 0), 0, ',', "\u{00A0}");
    }

    public function totalFrais(): string
    {
        $total = array_sum(array_map(fn (string $cle): int => (int) ($this->valeurs[$cle] ?? 0),
            ['frais_inscription_montant', 'frais_tranche1_montant', 'frais_tranche2_montant']));

        return number_format($total, 0, ',', "\u{00A0}");
    }

    /** « 21 octobre 2026 », « 03 novembre 2026 », « 1er décembre 2026 » */
    public function date(string $cle): string
    {
        $valeur = $this->valeurs[$cle] ?? null;

        if (blank($valeur)) {
            return '';
        }
        $date = CarbonImmutable::parse((string) $valeur)->locale('fr');

        return self::jour($date).' '.$date->translatedFormat('F Y');
    }

    /**
     * Article et période de la rentrée : [« le », « 17 novembre 2026 »] ou
     * [« du », « 10 au 11 novembre 2026 »]. Une date de fin qui ne suit pas le
     * début est ignorée, pour ne jamais imprimer une période à l'envers.
     *
     * @return array{0: string, 1: string}
     */
    public function periodeRentree(): array
    {
        $fin = $this->valeurs['rentree_date_fin'] ?? null;
        $debut = $this->valeurs['rentree_date'] ?? null;

        if (blank($fin) || blank($debut) || ! CarbonImmutable::parse((string) $fin)->isAfter(CarbonImmutable::parse((string) $debut))) {
            return ['le', $this->date('rentree_date')];
        }
        $debut = CarbonImmutable::parse((string) $debut)->locale('fr');
        $fin = CarbonImmutable::parse((string) $fin);

        $depart = match (true) {
            $debut->year !== $fin->year => $this->date('rentree_date'),
            $debut->month !== $fin->month => self::jour($debut).' '.$debut->translatedFormat('F'),
            default => self::jour($debut),
        };

        return ['du', $depart.' au '.$this->date('rentree_date_fin')];
    }

    private static function jour(CarbonImmutable $date): string
    {
        return $date->day === 1 ? '1er' : $date->format('d');
    }

    /** « N° 26/ 7 L/MINFI/SG/PSSFP/PCP/UAAF » : {numero} est le rang du matricule. */
    public function reference(string $cle, int $numero): string
    {
        $annee = blank($this->valeurs['date_lettres'] ?? null)
            ? now()->format('y')
            : CarbonImmutable::parse((string) $this->valeurs['date_lettres'])->format('y');

        return strtr($this->texte($cle), ['{annee}' => $annee, '{numero}' => (string) $numero]);
    }

    /** @return list<string> */
    public function copies(): array
    {
        return array_values(array_filter((array) ($this->valeurs['copies'] ?? []), 'filled'));
    }

    public function promotion(): int
    {
        return (int) $this->campagne->promotion_numero;
    }

    /** « 14e » */
    public function promotionCourte(): string
    {
        return $this->promotion().'e';
    }

    /** « 2026-2027 » */
    public function anneeAcademique(): string
    {
        return $this->campagne->anneeAcademique();
    }
}
