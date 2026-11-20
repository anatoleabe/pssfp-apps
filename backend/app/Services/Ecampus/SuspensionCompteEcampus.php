<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use App\Models\Candidature;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Illuminate\Support\Facades\Cache;

/**
 * Compte eCampus d'un auditeur suspendu à sa demande (spec module-4-scolarite-suspension, 20/11/2026) : suspendu à la
 * suspension, rouvert à sa levée. Le compte est celui qui porte l'identifiant de l'auditeur, et seulement s'il porte
 * aussi son matricule ou son e-mail (même garde que la réinitialisation du mot de passe).
 */
final class SuspensionCompteEcampus
{
    public function __construct(
        private readonly MotsDePasseEcampus $comptes,
        private readonly ClientMoodle $moodle,
    ) {}

    /**
     * Pose l'état voulu et renvoie le constat à garder. Sans compte eCampus, il n'y a rien à suspendre : ce n'est pas
     * une erreur.
     *
     * @throws EchecEcampus eCampus injoignable, compte d'un autre, ou changement non confirmé
     */
    public function appliquer(Candidature $c, bool $suspendu): string
    {
        $resultat = Cache::lock('ecampus-compte:'.$c->id, 60)->get(function () use ($c, $suspendu): string {
            $compte = $this->comptes->compte($c);
            if ($compte === null) {
                return 'Aucun compte eCampus ne porte l\'identifiant '.IdentifiantsMoodle::identifiant($c).'.';
            }
            if (! $compte['correspond']) {
                throw new EchecEcampus('Le compte eCampus '.$compte['identifiant'].' ne porte ni le matricule ni l\'e-mail de ce dossier : laissé tel quel.');
            }
            if ($compte['suspendu'] !== $suspendu) {
                $this->moodle->suspendreCompte($compte['id'], $suspendu);
            }

            return 'Compte eCampus '.$compte['identifiant'].($suspendu ? ' suspendu.' : ' rouvert.');
        });

        if ($resultat === false) {
            throw new EchecEcampus('Une opération sur ce compte eCampus est déjà en cours : réessayez dans un instant.');
        }

        return $resultat;
    }
}
