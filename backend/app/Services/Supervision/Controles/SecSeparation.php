<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\User;
use App\Services\Supervision\Controle;

/**
 * Séparation des tâches (D30, SEC_SEPARATION) : un compte du personnel ne réunit pas deux droits incompatibles,
 * rôles compris. Un cumul voulu (super administrateur) se justifie.
 */
final class SecSeparation implements Controle
{
    /** Couples incompatibles : libellé => [droit, droit]. */
    public const COUPLES = [
        'Instruire et valider une requête' => ['lmd.requetes_traiter', 'lmd.requetes_valider'],
        'Valider les heures et modifier les coordonnées bancaires' => ['lmd.pointage_enseignants', 'lmd.dossier_paie'],
        'Coordonnées bancaires et états de paie' => ['lmd.dossier_paie', 'lmd.paie_verifier'],
        'Administrer les habilitations et consulter le journal' => ['update_role', 'journal.view'],
        'Préparer et signer le calendrier' => ['lmd.calendrier_preparer', 'lmd.calendrier_signer'],
    ];

    public function code(): string
    {
        return 'SEC_SEPARATION';
    }

    public function libelle(): string
    {
        return 'Séparation des tâches non respectée';
    }

    public function domaine(): string
    {
        return 'Sécurité';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 3;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        $comptes = User::query()->whereHas('roles', fn ($q) => $q->whereNotIn('name', User::NON_PANEL_ROLES))
            ->with(['roles.permissions:id,name', 'permissions:id,name'])->orderBy('name')->get(['id', 'name']);
        foreach ($comptes as $u) {
            $droits = $u->getAllPermissions()->pluck('name')->all();
            foreach (self::COUPLES as $libelle => [$a, $b]) {
                if (in_array($a, $droits, true) && in_array($b, $droits, true)) {
                    $cas[$u->id.'|'.$a.'|'.$b] = ['resume' => $u->name.' : '.mb_strtolower($libelle), 'compte' => $u->id, 'couple' => $libelle];
                }
            }
        }

        return $cas;
    }
}
