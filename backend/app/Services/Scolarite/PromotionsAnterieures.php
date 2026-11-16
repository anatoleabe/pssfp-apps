<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\CampagneCandidature;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Création d'une promotion antérieure (spec de la réinscription des anciens, §5) : une campagne close, sans appel ni
 * communiqué, qui n'apparaît pas sur le portail. Elle sert de promotion de rattachement à un ancien réinscrit depuis
 * l'archive ACMA ; aucun matricule n'y est jamais attribué par la plateforme.
 *
 * Correspondance retenue (Q1, 16/11/2026) : promotion n = année d'entrée 2012 + n ; l'année reste modifiable.
 */
final class PromotionsAnterieures
{
    public const PERMISSION = 'scolarite.promotion_anterieure';

    public const EVENEMENT = 'promotion_anterieure_creee';

    public const MOTIF_MINIMUM = 10;

    public const ECART_ANNEE_NUMERO = 2012;

    public static function anneeProposee(int $numero): string
    {
        $debut = self::ECART_ANNEE_NUMERO + $numero;

        return $debut.'-'.($debut + 1);
    }

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function creer(int $numero, string $annee, string $motif, User $auteur): CampagneCandidature
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < self::MOTIF_MINIMUM || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException('Le motif compte de '.self::MOTIF_MINIMUM.' à 500 caractères.');
        }
        if (! AnneeEnCours::valide($annee)) {
            throw new InvalidArgumentException('L\'année académique s\'écrit AAAA-AAAA, deux années qui se suivent.');
        }

        return DB::transaction(function () use ($numero, $annee, $motif, $auteur): CampagneCandidature {
            $courante = AnneeEnCours::promotionCourante()
                ?? throw new InvalidArgumentException('Promotion en cours introuvable : vérifiez l\'année en cours.');
            // La promotion courante sert de verrou : deux créations simultanées s'attendent.
            CampagneCandidature::query()->whereKey($courante->id)->lockForUpdate()->first();

            if ($numero < 1 || $numero >= (int) $courante->promotion_numero) {
                throw new InvalidArgumentException('Une promotion antérieure porte un numéro de 1 à '.((int) $courante->promotion_numero - 1).'.');
            }
            if (AnneeEnCours::debut($annee) >= AnneeEnCours::debut($courante->anneeAcademique())) {
                throw new InvalidArgumentException('L\'année d\'une promotion antérieure précède celle de la promotion en cours.');
            }
            $existante = CampagneCandidature::query()->where('promotion_numero', $numero)->first();
            if ($existante !== null) {
                throw new InvalidArgumentException("La {$numero}e promotion existe déjà.");
            }
            $memeAnnee = AnneeEnCours::promotionDeLAnnee($annee);
            if ($memeAnnee !== null) {
                throw new InvalidArgumentException("L'année {$annee} est déjà celle de la {$memeAnnee->promotion_numero}e promotion.");
            }

            $debut = AnneeEnCours::debut($annee);
            $promotion = CampagneCandidature::query()->create([
                'slug' => "p{$numero}-{$debut}",
                'nom' => ['fr' => "Année académique {$annee}"],
                'promotion_numero' => $numero,
                'prefix_numero' => sprintf('P%02d%03d-', $numero, $debut % 1000),
                'status' => 'closed',
                'opens_at' => CarbonImmutable::create($debut, 6, 1),
                'closes_at' => CarbonImmutable::create($debut, 9, 15),
                'max_voeux' => 1,
            ]);

            activity('scolarite')->causedBy($auteur)->performedOn($promotion)->event(self::EVENEMENT)
                ->withProperties(['promotion' => $numero, 'annee' => $annee, 'motif' => $motif])
                ->log('Promotion antérieure créée');

            return $promotion;
        });
    }
}
