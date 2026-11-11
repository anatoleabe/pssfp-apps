<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\AppSettings;
use App\Support\Journaux;
use Illuminate\Support\Facades\DB;

/**
 * Lot 14d-1 (D30) : chaîne d'empreintes du journal d'activité. Le calcul vit dans la base (fonctions `activity_log_sceller`
 * et `activity_log_ruptures`) : ce service les appelle et garde le dernier sceau vérifié, pour détecter la suppression des
 * entrées les plus récentes.
 */
final class ChaineJournal
{
    public const CLE_DERNIER_SCEAU = 'audit.journal_dernier_sceau';

    /** Une entrée non scellée au-delà de ce délai signale un scellement arrêté. */
    public const DELAI_SCELLEMENT_MINUTES = 10;

    public const NATURES = [
        'modifiee' => 'entrée modifiée après coup',
        'maillon_manquant' => 'maillon manquant ou déplacé (entrée supprimée ou insérée hors ordre)',
        'non_scellee' => 'entrée non scellée (scellement arrêté)',
        'fin_supprimee' => 'dernier sceau vérifié introuvable (entrées les plus récentes supprimées ou réécrites)',
    ];

    /**
     * Après enquête sur une suppression des entrées les plus récentes : le dernier sceau repart du dernier rang actuel.
     * La reprise est elle-même journalisée (et donc scellée).
     */
    public function reprendre(string $motif): void
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new \InvalidArgumentException('La reprise de la chaîne exige un motif (10 caractères au moins).');
        }
        $ancien = AppSettings::get(self::CLE_DERNIER_SCEAU);
        $this->sceller();
        $dernier = DB::table('activity_log')->whereNotNull('chaine_rang')->orderByDesc('chaine_rang')->first(['chaine_rang', 'empreinte']);
        AppSettings::set(self::CLE_DERNIER_SCEAU, $dernier === null ? null : ['rang' => (int) $dernier->chaine_rang, 'empreinte' => (string) $dernier->empreinte]);
        activity(Journaux::SUPERVISION)->event('chaine_journal_reprise')
            ->withProperties(['ancien_sceau' => $ancien, 'nouveau_rang' => $dernier?->chaine_rang, 'motif' => $motif])->log('Chaîne du journal reprise après enquête');
    }

    /** Scelle les entrées en attente ; renvoie leur nombre. */
    public function sceller(): int
    {
        return (int) DB::selectOne('SELECT activity_log_sceller() AS n')->n;
    }

    /**
     * Ruptures constatées. Sans rupture, le dernier sceau avance au dernier rang scellé.
     *
     * @return list<array{id: int|null, rang: int|null, nature: string}>
     */
    public function ruptures(): array
    {
        $ruptures = array_map(fn (object $r): array => ['id' => (int) $r->id, 'rang' => (int) $r->chaine_rang, 'nature' => (string) $r->nature],
            DB::select('SELECT id, chaine_rang, nature FROM activity_log_ruptures()'));

        $nonScellees = DB::table('activity_log')->whereNull('empreinte')
            ->where('created_at', '<', now()->subMinutes(self::DELAI_SCELLEMENT_MINUTES))->orderBy('id')->limit(100)->pluck('id');
        foreach ($nonScellees as $id) {
            $ruptures[] = ['id' => (int) $id, 'rang' => null, 'nature' => 'non_scellee'];
        }

        $sceau = AppSettings::get(self::CLE_DERNIER_SCEAU);
        if (is_array($sceau) && isset($sceau['rang'], $sceau['empreinte'])
            && ! DB::table('activity_log')->where('chaine_rang', $sceau['rang'])->where('empreinte', $sceau['empreinte'])->exists()) {
            $ruptures[] = ['id' => null, 'rang' => (int) $sceau['rang'], 'nature' => 'fin_supprimee'];
        }

        if ($ruptures === []) {
            $dernier = DB::table('activity_log')->whereNotNull('chaine_rang')->orderByDesc('chaine_rang')->first(['chaine_rang', 'empreinte']);
            if ($dernier !== null) {
                AppSettings::set(self::CLE_DERNIER_SCEAU, ['rang' => (int) $dernier->chaine_rang, 'empreinte' => (string) $dernier->empreinte]);
            }
        }

        return $ruptures;
    }
}
