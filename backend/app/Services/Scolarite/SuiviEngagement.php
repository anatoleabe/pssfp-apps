<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;

/**
 * Suivi d'un engagement de prise en charge (spec module 4, lot A2 §9.6), en lecture seule :
 * couvert et reste par inscription couverte selon la portée, réglé (argent reçu de
 * l'organisme), état. Calculé à chaque lecture depuis la situation des inscriptions
 * (l'imputé de D1), jamais stocké. Sans requête par ligne si l'engagement est chargé
 * par le scope `avecSuivi`.
 */
final readonly class SuiviEngagement
{
    /** @param  list<array{inscription: InscriptionAnnuelle, couvert: int, reste: int}>  $lignes  par id d'inscription */
    private function __construct(
        public EngagementPriseEnCharge $engagement,
        public array $lignes,
        public int $couvert,
        public int $regle,
        public int $reste,
    ) {}

    public static function de(EngagementPriseEnCharge $engagement): self
    {
        if (! $engagement->relationLoaded('deblocagesCouverts') || ! array_key_exists('reglements_valides_sum_montant', $engagement->getAttributes())) {
            $engagement->rechargerSuivi();
        }

        // Plusieurs liens peuvent couvrir la même inscription (régularisé, puis rattaché de nouveau) : une ligne par inscription.
        $inscriptions = [];
        foreach ($engagement->deblocagesCouverts as $d) {
            /** @var DeblocageAcces $d */
            if ($d->inscription !== null) {
                $inscriptions[$d->inscription->id] = $d->inscription;
            }
        }
        ksort($inscriptions);

        $lignes = [];
        foreach ($inscriptions as $inscription) {
            [$couvert, $reste] = self::portee($engagement->portee, $inscription);
            $lignes[] = ['inscription' => $inscription, 'couvert' => $couvert, 'reste' => $reste];
        }

        return new self(
            $engagement,
            $lignes,
            array_sum(array_column($lignes, 'couvert')),
            (int) $engagement->getAttribute('reglements_valides_sum_montant'),
            array_sum(array_column($lignes, 'reste')),
        );
    }

    /**
     * Dû et reste d'une inscription dans la portée de l'engagement : le palier « inscription »
     * ou toute l'année, remise déduite.
     *
     * @return array{0: int, 1: int}
     */
    public static function portee(string $portee, InscriptionAnnuelle $inscription): array
    {
        $s = $inscription->situation();

        return $portee === EngagementPriseEnCharge::DROITS_INSCRIPTION
            ? [$s->paliers[0]->du, $s->resteInscription()]
            : [$s->duTotal, $s->reste];
    }

    public function nombre(): int
    {
        return count($this->lignes);
    }

    public function etat(): string
    {
        return match (true) {
            $this->lignes === [] => EngagementPriseEnCharge::SANS_AUDITEUR,
            $this->reste === 0 => EngagementPriseEnCharge::SOLDE,
            $this->engagement->estEchu() => EngagementPriseEnCharge::ECHU,
            default => EngagementPriseEnCharge::EN_COURS,
        };
    }

    public function libelleEtat(): string
    {
        return EngagementPriseEnCharge::ETATS[$this->etat()];
    }
}
