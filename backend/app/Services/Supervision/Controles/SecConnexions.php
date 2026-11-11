<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Audit\VerrouillageComptes;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Supervision;
use Illuminate\Support\Facades\DB;

/** Comptes verrouillés et rafales d'échecs de connexion au back-office (D30, SEC_CONNEXIONS). */
final class SecConnexions implements Controle
{
    /** Échecs sur 24 heures, pour un même identifiant ou une même adresse, à partir desquels la tentative est suspecte. */
    public const ECHECS_SUSPECTS = 10;

    /** Anomalies distinctes au plus par critère (identifiant, adresse). */
    public const RAFALES_MAX = 20;

    public function code(): string
    {
        return 'SEC_CONNEXIONS';
    }

    public function libelle(): string
    {
        return 'Comptes verrouillés et tentatives de connexion suspectes';
    }

    public function domaine(): string
    {
        return 'Sécurité';
    }

    public function criticite(): string
    {
        return self::MAJEUR;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        foreach (VerrouillageComptes::verrouilles()->orderBy('id')->get(['id', 'name', 'verrouille_le', 'verrouille_jusqua', 'verrouille_motif']) as $u) {
            $cas['verrouille:'.$u->id] = [
                'resume' => 'Compte de '.$u->name.' verrouillé le '.$u->verrouille_le->setTimezone(Supervision::FUSEAU)->format('d/m/Y à H:i')
                    .($u->verrouille_jusqua ? ' jusqu\'à '.$u->verrouille_jusqua->setTimezone(Supervision::FUSEAU)->format('H:i') : ', levée manuelle attendue')
                    .($u->verrouille_motif ? ' ('.$u->verrouille_motif.')' : ''),
                'compte' => $u->id,
            ];
        }
        foreach (['identifiant' => 'Identifiant', 'ip' => 'Adresse'] as $champ => $libelle) {
            $rafales = DB::table('activity_log')->where('log_name', VerrouillageComptes::JOURNAL)->where('event', 'connexion_echouee')
                ->where('created_at', '>=', now()->subDay())
                // Clé tirée de la liste fermée ci-dessus : écrite en clair pour que SELECT et GROUP BY portent la même expression.
                ->selectRaw("properties->>'{$champ}' as valeur, count(*) as n")->groupByRaw("properties->>'{$champ}'")
                ->havingRaw('count(*) >= ?', [self::ECHECS_SUSPECTS])->orderByDesc('n')->get();
            // Plafond : au-delà, une seule anomalie (une rotation d'identifiants inventés ne doit pas noyer le contrôle interne).
            if ($rafales->count() > self::RAFALES_MAX) {
                $reste = $rafales->slice(self::RAFALES_MAX);
                $cas[$champ.':autres'] = ['resume' => $reste->count().' autres valeurs de « '.mb_strtolower($libelle).' » avec au moins '
                    .self::ECHECS_SUSPECTS.' échecs sur 24 heures ('.$reste->sum('n').' échecs au total)'];
                $rafales = $rafales->take(self::RAFALES_MAX);
            }
            foreach ($rafales as $r) {
                if ($r->valeur === null || $r->valeur === '') {
                    continue;
                }
                $cas[$champ.':'.mb_substr((string) $r->valeur, 0, 200)] = [
                    'resume' => $libelle.' « '.mb_substr((string) $r->valeur, 0, 150).' » : '.$r->n.' échecs de connexion sur 24 heures',
                ];
            }
        }

        return $cas;
    }
}
