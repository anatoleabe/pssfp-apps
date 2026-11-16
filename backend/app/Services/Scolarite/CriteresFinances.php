<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use InvalidArgumentException;

/**
 * Périmètre P du tableau de bord et de l'analyse de l'espace Finances (spec module-4-scolarite-espace-financier §5.1),
 * validé une fois : année académique, promotion, niveau, parcours, statuts de l'auditeur, dossiers retirés. Aucune
 * valeur hors liste n'atteint le service. Pour le journal, des critères, jamais une personne.
 */
final readonly class CriteresFinances
{
    public const NON_RENSEIGNE = 'Non renseigné';

    /** @param  list<string>  $statuts */
    private function __construct(
        public ?string $annee,
        public ?int $campagneId,
        public ?string $niveau,
        public ?string $parcours,
        public array $statuts,
        public bool $inclureRetires,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  état du formulaire de filtres
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public static function depuis(array $donnees): self
    {
        $annee = self::texte($donnees['annee'] ?? null);
        if ($annee !== null && preg_match('/^\d{4}-\d{4}$/', $annee) !== 1) {
            throw new InvalidArgumentException('Choisissez une année académique de la liste.');
        }
        $campagneId = $donnees['campagne_id'] ?? null;
        if (filled($campagneId) && (! is_numeric($campagneId) || ! CampagneCandidature::query()->whereKey((int) $campagneId)->exists())) {
            throw new InvalidArgumentException('Choisissez une promotion de la liste.');
        }
        $niveau = self::texte($donnees['niveau'] ?? null);
        if ($niveau !== null && ! array_key_exists($niveau, BaremeScolarite::NIVEAUX)) {
            throw new InvalidArgumentException('Choisissez un niveau de la liste.');
        }
        $statuts = array_map('strval', array_values(array_filter((array) ($donnees['statuts'] ?? []), fn ($s): bool => filled($s))));
        if (array_diff($statuts, array_keys(Auditeur::STATUTS)) !== []) {
            throw new InvalidArgumentException('Choisissez des statuts de la liste.');
        }

        return new self(
            annee: $annee,
            campagneId: filled($campagneId) ? (int) $campagneId : null,
            niveau: $niveau,
            parcours: self::texte($donnees['parcours'] ?? null),
            // Par défaut tous les statuts : le dû d'un auditeur en abandon reste une créance (§5.4).
            statuts: $statuts === [] ? array_keys(Auditeur::STATUTS) : array_values(array_intersect(array_keys(Auditeur::STATUTS), $statuts)),
            inclureRetires: (bool) filter_var($donnees['inclure_retires'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** @return array<string, mixed> */
    public function proprietes(): array
    {
        return ['annee' => $this->annee, 'campagne_id' => $this->campagneId, 'niveau' => $this->niveau, 'parcours' => $this->parcours,
            'statuts' => $this->statuts, 'inclure_retires' => $this->inclureRetires];
    }

    private static function texte(mixed $valeur): ?string
    {
        $texte = is_scalar($valeur) ? trim((string) $valeur) : '';

        return $texte === '' ? null : $texte;
    }
}
