<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use InvalidArgumentException;

/**
 * Critères d'une liste PDF des auditeurs (écran « Listes et états »), validés
 * une fois pour toutes : le service ne reçoit jamais une valeur hors liste.
 *
 * Niveau et spécialité nuls : tous. La situation reprend les catégories de
 * LibelleSituation ; « non soldé » couvre tout auditeur qui doit encore de
 * l'argent (inscription non soldée, à jour ou en retard). Les dossiers retirés
 * restent hors liste, sauf si l'agent coche « Inclure les dossiers retirés ».
 */
final readonly class CriteresListe
{
    public const TOUTES = 'toutes';

    public const SOLDE = 'solde';

    public const NON_SOLDE = 'non_solde';

    public const EN_RETARD = 'en_retard';

    public const SITUATIONS = [
        self::TOUTES => 'Toutes',
        self::SOLDE => 'Soldé',
        self::NON_SOLDE => 'Non soldé (reste à payer)',
        self::EN_RETARD => 'En retard (inscription soldée, tranche échue)',
    ];

    public const PAR_SPECIALITE = 'specialite';

    public const PAR_NIVEAU = 'niveau';

    public const SANS_REGROUPEMENT = 'aucun';

    public const REGROUPEMENTS = [
        self::PAR_SPECIALITE => 'par spécialité',
        self::PAR_NIVEAU => 'par niveau',
        self::SANS_REGROUPEMENT => 'sans regroupement',
    ];

    /** @param  list<string>  $statuts  clés d'Auditeur::STATUTS, dans leur ordre */
    private function __construct(
        public CampagneCandidature $campagne,
        public ?string $niveau,
        public ?string $specialite,
        public string $situation,
        public array $statuts,
        public string $regroupement,
        public bool $inclureRetires,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  état du formulaire
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public static function depuis(array $donnees): self
    {
        $campagneId = $donnees['campagne_id'] ?? null;
        $campagne = is_numeric($campagneId) ? CampagneCandidature::query()->find((int) $campagneId) : null;
        if ($campagne === null) {
            throw new InvalidArgumentException('Choisissez une promotion.');
        }

        $niveau = self::texte($donnees['niveau'] ?? null);
        if ($niveau !== null && ! array_key_exists($niveau, BaremeScolarite::NIVEAUX)) {
            throw new InvalidArgumentException('Choisissez un niveau de la liste.');
        }

        $situation = self::texte($donnees['situation'] ?? null) ?? self::TOUTES;
        if (! array_key_exists($situation, self::SITUATIONS)) {
            throw new InvalidArgumentException('Choisissez une situation de la liste.');
        }

        $demandes = array_map('strval', (array) ($donnees['statuts'] ?? [Auditeur::STATUT_ACTIF]));
        if ($demandes === [] || array_diff($demandes, array_keys(Auditeur::STATUTS)) !== []) {
            throw new InvalidArgumentException('Cochez au moins un statut de la liste.');
        }

        $regroupement = self::texte($donnees['regroupement'] ?? null) ?? self::PAR_SPECIALITE;
        if (! array_key_exists($regroupement, self::REGROUPEMENTS)) {
            throw new InvalidArgumentException('Choisissez un regroupement de la liste.');
        }

        $inclureRetires = filter_var($donnees['inclure_retires'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($inclureRetires === null) {
            throw new InvalidArgumentException('Cochez ou décochez « Inclure les dossiers retirés ».');
        }

        return new self(
            campagne: $campagne,
            niveau: $niveau,
            specialite: self::texte($donnees['specialite'] ?? null),
            situation: $situation,
            statuts: array_values(array_intersect(array_keys(Auditeur::STATUTS), $demandes)),
            regroupement: $regroupement,
            inclureRetires: $inclureRetires,
        );
    }

    /** « 14e promotion (2026-2027) » */
    public static function libellePromotion(CampagneCandidature $campagne): string
    {
        return $campagne->promotion_numero.'e promotion ('.$campagne->anneeAcademique().')';
    }

    /**
     * Critères rappelés en tête de la liste.
     *
     * @return array<string, string>
     */
    public function libelles(): array
    {
        return [
            'Promotion' => self::libellePromotion($this->campagne),
            'Niveau' => $this->niveau === null ? 'tous' : BaremeScolarite::NIVEAUX[$this->niveau],
            'Spécialité' => $this->specialite ?? 'toutes',
            'Situation' => self::SITUATIONS[$this->situation],
            'Statut' => implode(', ', array_map(fn (string $s): string => Auditeur::STATUTS[$s], $this->statuts)),
            'Regroupement' => self::REGROUPEMENTS[$this->regroupement],
            'Dossiers retirés' => $this->inclureRetires ? 'inclus' : 'exclus',
        ];
    }

    /**
     * Pour le journal : des critères, jamais une personne.
     *
     * @return array<string, mixed>
     */
    public function proprietes(): array
    {
        return [
            'campagne_id' => $this->campagne->id,
            'promotion' => $this->campagne->promotion_numero,
            'niveau' => $this->niveau,
            'specialite' => $this->specialite,
            'situation' => $this->situation,
            'statuts' => $this->statuts,
            'regroupement' => $this->regroupement,
            'inclure_retires' => $this->inclureRetires,
        ];
    }

    private static function texte(mixed $valeur): ?string
    {
        $texte = is_scalar($valeur) ? trim((string) $valeur) : '';

        return $texte === '' ? null : $texte;
    }
}
