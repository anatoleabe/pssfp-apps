<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\DeblocageAcces;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Moratoire demandé par l'agent au responsable (spec module 4, lot A3, §10), par le circuit des demandes du lot 14c :
 * ce que la demande `accorder_moratoire` contrôle, affiche et exécute. TypesDemande y délègue ; l'écriture du
 * moratoire reste à DeblocagesAcces, qui refait tous les contrôles sous verrou.
 */
final class DemandeMoratoire
{
    /** Le motif du moratoire reprend le numéro de la demande : la saisie laisse la place (1 000 caractères au plus). */
    public const MOTIF_MAX = 900;

    /**
     * Échéance proposée et pièce, contrôlées comme pour un moratoire accordé directement.
     *
     * @param  array<string, mixed>  $saisie
     * @return array{echeance: string, justificatif_path: ?string}
     *
     * @throws InvalidArgumentException
     */
    public static function parametres(InscriptionAnnuelle $inscription, array $saisie): array
    {
        return DeblocagesAcces::controlerMoratoire($inscription, $saisie['echeance'] ?? null, $saisie['justificatif_path'] ?? null);
    }

    /** Matricule, nom et inscription visés : ce que les décideurs lisent dans la liste et le message. */
    public static function libelle(InscriptionAnnuelle $inscription): string
    {
        $dossier = $inscription->auditeur?->candidature;
        $nom = trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom);

        return trim(($dossier?->matricule ?? 'Sans matricule').' — '.($nom === '' ? 'auditeur' : $nom))
            .' — inscription '.$inscription->annee_academique.' ('.$inscription->niveau.')';
    }

    /**
     * @param  array<string, mixed>|null  $parametres
     * @return list<string>
     */
    public static function precisions(?array $parametres): array
    {
        $echeance = (string) ($parametres['echeance'] ?? '');
        if ($echeance === '') {
            return [];
        }

        return [
            'Échéance proposée : '.CarbonImmutable::parse($echeance, InscriptionAnnuelle::FUSEAU)->format('d/m/Y'),
            filled($parametres['justificatif_path'] ?? null) ? 'Pièce : demande écrite de l\'auditeur jointe' : 'Pièce : aucune pièce jointe',
        ];
    }

    /** Ce que rappelle la fenêtre d'approbation : le décideur approuve l'échéance telle quelle. */
    public static function avertissement(?array $parametres): string
    {
        $echeance = filled($parametres['echeance'] ?? null)
            ? CarbonImmutable::parse((string) $parametres['echeance'], InscriptionAnnuelle::FUSEAU)->format('d/m/Y') : '?';

        return 'Le moratoire sera accordé à votre nom jusqu\'au '.$echeance.', et l\'auditeur prévenu par SMS et par e-mail. '
            .'Pour un délai plus court, rejetez en le précisant ; pour un délai plus long, approuvez puis prolongez depuis la fiche de l\'auditeur.';
    }

    /**
     * Crée le moratoire au nom du décideur, avec l'échéance et la pièce demandées ; l'auditeur est prévenu comme en A1.
     *
     * @param  array<string, mixed>  $parametres
     *
     * @throws InvalidArgumentException si l'état a changé depuis la demande (soldé, retiré, débloqué…)
     */
    public static function executer(InscriptionAnnuelle $inscription, array $parametres, User $decideur, string $motif): string
    {
        $resultat = app(DeblocagesAcces::class)->accorder($inscription, [
            'type' => DeblocageAcces::MORATOIRE, 'motif' => $motif,
            'echeance' => $parametres['echeance'] ?? null, 'justificatif_path' => $parametres['justificatif_path'] ?? null,
        ], $decideur);

        return 'Moratoire accordé jusqu\'au '.$resultat->deblocage->echeance->format('d/m/Y').' ; '.match ($resultat->notification) {
            true => 'auditeur prévenu par SMS et par e-mail',
            false => 'ni SMS ni e-mail parti : « Renvoyer le SMS et l\'e-mail » depuis la fiche de l\'auditeur',
            null => 'auditeur déjà prévenu de ses accès, ou compte eCampus encore à créer',
        };
    }
}
