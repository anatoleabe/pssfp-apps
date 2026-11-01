<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Frais d'inscription et péri-académiques des auditeurs : pointés par un
 * agent, ils conditionnent la mise à disposition de la lettre d'admission
 * dans l'espace de l'auditeur. Distincts des frais de dossier (`frais_paye`).
 */
final class FraisInscription
{
    public const PERMISSION = 'candidature.frais_inscription';

    public static function concerne(Candidature $c): bool
    {
        return $c->statut === Candidature::STATUT_ACCEPTE && $c->withdrawn_at === null;
    }

    public static function payes(Candidature $c): bool
    {
        return $c->frais_inscription_payes_at !== null;
    }

    /**
     * Pointe (ou annule le pointage) des dossiers retenus non retirés ; les
     * autres sont ignorés. Mise à jour conditionnelle : deux agents qui
     * pointent en même temps ne journalisent pas deux fois le même dossier.
     *
     * @param  Collection<int, Candidature>  $dossiers
     * @return int nombre de dossiers modifiés
     */
    public function pointer(Collection $dossiers, bool $payes, User $auteur): int
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        $modifies = 0;
        foreach ($dossiers->filter(fn (Candidature $c): bool => self::concerne($c)) as $c) {
            $requete = Candidature::query()->whereKey($c->id)->where('statut', Candidature::STATUT_ACCEPTE)->whereNull('withdrawn_at');
            $changes = $payes
                ? $requete->whereNull('frais_inscription_payes_at')->update(['frais_inscription_payes_at' => now(), 'frais_inscription_payes_by' => $auteur->id])
                : $requete->whereNotNull('frais_inscription_payes_at')->update(['frais_inscription_payes_at' => null, 'frais_inscription_payes_by' => null]);
            if ($changes === 0) {
                continue;
            }
            $modifies++;
            activity('candidatures')->causedBy($auteur)->performedOn($c)
                ->event($payes ? 'frais_inscription_payes' : 'frais_inscription_annules')
                ->log($payes ? 'Frais d\'inscription pointés payés' : 'Pointage des frais d\'inscription annulé');
        }

        return $modifies;
    }
}
