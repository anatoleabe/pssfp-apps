<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\AcmaDossier;
use App\Models\Auditeur;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lien d'un dossier d'archive vers un auditeur, posé par un geste de la scolarité (réinscription, reprise du
 * matricule ACMA). Décision d'abord : la décision `lie` signée entre dans `acma_rapprochements` (ajout seul), puis le
 * lien ; le déclencheur de l'archive ne l'accepte qu'à cette condition, avant comme après le gel. À appeler dans la
 * transaction du geste.
 */
final class LienArchive
{
    /** @return int identifiant de la décision écrite */
    public static function poser(AcmaDossier $dossier, Auditeur $auditeur, string $motif, User $auteur): int
    {
        $proposition = $dossier->rapprochements()->whereNull('decide_par')->latest('id')->first();
        $id = DB::table('acma_rapprochements')->insertGetId([
            'dossier_id' => $dossier->id, 'auditeur_id' => $auditeur->id, 'cas' => $proposition?->cas, 'score' => $proposition?->score,
            'decision' => 'lie', 'motif' => mb_substr($motif, 0, 500),
            'decide_par' => $auteur->id, 'reprise_id' => null, 'created_at' => now(),
        ]);
        DB::table('acma_dossiers')->where('id', $dossier->id)->update(['auditeur_id' => $auditeur->id, 'statut_rapprochement' => 'lie_arbitre', 'updated_at' => now()]);

        return (int) $id;
    }
}
