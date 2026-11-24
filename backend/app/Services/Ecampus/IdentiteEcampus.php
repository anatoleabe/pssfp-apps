<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use App\Models\Candidature;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Support\TexteImprimable;
use Illuminate\Support\Facades\Cache;

/**
 * Le compte eCampus d'un auditeur suit la correction de son dossier (24/11/2026) : nom, prénom et e-mail, au format
 * de la création du compte. Seul le compte qui porte l'identifiant de l'auditeur est touché, et seulement s'il porte
 * aussi son matricule ou son e-mail (l'ancien ou le nouveau).
 */
final class IdentiteEcampus
{
    public function __construct(private readonly ClientMoodle $moodle) {}

    /**
     * Pose sur eCampus le nom, le prénom et l'e-mail du dossier ; renvoie le constat. Sans compte, rien à faire.
     *
     * @throws EchecEcampus eCampus injoignable, compte d'un autre, e-mail pris, ou correction non confirmée
     */
    public function synchroniser(Candidature $c, string $ancienEmail): string
    {
        $resultat = Cache::lock('ecampus-compte:'.$c->id, 60)->get(function () use ($c, $ancienEmail): string {
            $identifiant = IdentifiantsMoodle::identifiant($c);
            $compte = $this->moodle->comptesParChamp('username', [$identifiant])[0] ?? null;
            if ($compte === null || ! isset($compte['id'])) {
                return 'Aucun compte eCampus ne porte l\'identifiant '.$identifiant.' : rien à corriger.';
            }
            if (! $this->correspond($c, $compte, $ancienEmail)) {
                throw new EchecEcampus('Le compte eCampus '.$identifiant.' ne porte ni le matricule ni l\'e-mail de ce dossier : laissé tel quel.');
            }

            $attendus = [
                'firstname' => TexteImprimable::nettoyer($c->prenom),
                'lastname' => TexteImprimable::majuscules($c->nom),
                'email' => (string) $c->email,
            ];
            $champs = array_filter($attendus, fn (string $valeur, string $champ): bool => $valeur !== ''
                && mb_strtolower(trim((string) ($compte[$champ] ?? ''))) !== mb_strtolower($valeur), ARRAY_FILTER_USE_BOTH);
            if ($champs === []) {
                return 'Compte eCampus '.$identifiant.' déjà à jour.';
            }
            if (isset($champs['email'])) {
                // allowaccountssameemail est désactivé : autant dire quel compte bloque.
                $autre = $this->moodle->comptesParChamp('email', [$champs['email']])[0] ?? null;
                if ($autre !== null && (int) ($autre['id'] ?? 0) !== (int) $compte['id']) {
                    throw new EchecEcampus('E-mail déjà utilisé sur eCampus par le compte '.($autre['username'] ?? 'inconnu').' : compte eCampus laissé tel quel.');
                }
            }

            $this->moodle->modifierCompte((int) $compte['id'], $champs);
            $libelles = ['firstname' => 'prénom', 'lastname' => 'nom', 'email' => 'e-mail'];

            return 'Compte eCampus '.$identifiant.' corrigé ('.implode(', ', array_map(fn (string $k): string => $libelles[$k], array_keys($champs))).').';
        });

        if ($resultat === false) {
            throw new EchecEcampus('Une opération sur ce compte eCampus est déjà en cours : corrigez-le dans un instant.');
        }

        return $resultat;
    }

    /** @param  array<string, mixed>  $compte */
    private function correspond(Candidature $c, array $compte, string $ancienEmail): bool
    {
        $marque = filled($c->identifiant_ecampus) ? (string) $c->identifiant_ecampus : (string) $c->matricule;
        $egal = fn (string $a, string $b): bool => $a !== '' && strcasecmp(trim($a), trim($b)) === 0;
        $email = (string) ($compte['email'] ?? '');

        return $egal((string) ($compte['idnumber'] ?? ''), $marque) || $egal($email, $ancienEmail) || $egal($email, (string) $c->email);
    }
}
