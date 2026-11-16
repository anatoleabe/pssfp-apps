<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Models\ConfirmationVersement;
use App\Models\CorrectionVersement;
use App\Models\User;
use App\Services\Scolarite\RecusVersements;
use App\Services\Supervision\Controle;
use App\Support\AppSettings;

/**
 * Corrections de versements à relire (ADR-0015, spec de l'espace Finances §10, VER_CORRECTIONS), contrôle compensatoire
 * du second regard : (1) chaque correction qui baisse le montant d'un versement confirmé, ou annule sans remplacement un
 * versement confirmé ; (2) chaque compte qui dépasse, sur 30 jours glissants, le seuil de corrections demandées
 * (réglage `finances.seuil_corrections`, 5 par défaut). Désignation par numéro de pièce et matricule, jamais par le nom
 * de l'auditeur.
 */
final class VerCorrections implements Controle
{
    public const CLE_SEUIL = 'finances.seuil_corrections';

    public const SEUIL_DEFAUT = 5;

    public const FENETRE_JOURS = 30;

    public function code(): string
    {
        return 'VER_CORRECTIONS';
    }

    public function libelle(): string
    {
        return 'Corrections de versements à relire (baisses sur un versement confirmé, comptes au-delà du seuil)';
    }

    public function domaine(): string
    {
        return 'Scolarité';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 7;
    }

    public function responsable(): string
    {
        return 'scolarite_responsable';
    }

    public static function seuil(): int
    {
        $v = AppSettings::get(self::CLE_SEUIL);

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::SEUIL_DEFAUT;
    }

    public function cas(): array
    {
        $cas = [];
        $corrections = CorrectionVersement::query()->with('versementAnnule.auditeur.candidature:id,matricule')->orderBy('id')->get();
        $confirmes = array_flip(ConfirmationVersement::query()->whereIn('versement_id', $corrections->pluck('versement_annule_id'))->pluck('versement_id')->all());
        foreach ($corrections as $c) {
            if (! isset($confirmes[$c->versement_annule_id])) {
                continue;
            }
            $montant = $c->champs['montant'] ?? null;
            $baisse = is_array($montant) && (int) $montant['apres'] < (int) $montant['avant'];
            if (! $baisse && $c->nature !== CorrectionVersement::ANNULATION) {
                continue;
            }
            $matricule = (string) ($c->versementAnnule?->auditeur?->candidature?->matricule ?? 'sans matricule');
            $cas['correction:'.$c->id] = [
                'resume' => $c->numero.' — '.$matricule.' — '.($baisse
                    ? 'montant baissé de '.RecusVersements::fcfa((int) $montant['avant']).' à '.RecusVersements::fcfa((int) $montant['apres'])
                    : 'versement confirmé de '.RecusVersements::fcfa((int) $c->versementAnnule?->montant).' annulé sans remplacement'),
                'correction' => $c->id,
            ];
        }

        $seuil = self::seuil();
        $depuis = now()->subDays(self::FENETRE_JOURS);
        $parCompte = CorrectionVersement::query()->where('created_at', '>=', $depuis)->get(['demandee_par'])->countBy('demandee_par');
        foreach ($parCompte as $compte => $nombre) {
            if ($nombre <= $seuil) {
                continue;
            }
            $cas['compte:'.$compte] = [
                'resume' => (User::query()->whereKey($compte)->value('name') ?? 'Compte '.$compte).' : '.$nombre.' corrections en '
                    .self::FENETRE_JOURS.' jours (seuil '.$seuil.')',
                'compte' => (int) $compte,
            ];
        }

        return $cas;
    }
}
