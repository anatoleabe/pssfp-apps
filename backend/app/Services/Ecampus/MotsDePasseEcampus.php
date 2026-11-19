<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use App\Models\Candidature;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Compte eCampus réel d'un auditeur et réinitialisation de son mot de passe (19/11/2026).
 *
 * eCampus ne garde qu'une empreinte du mot de passe : personne ne peut le relire. Le portail connaît celui
 * qu'il a attribué ; s'il ne correspond plus (compte d'une promotion antérieure, mot de passe changé ou oublié),
 * l'administrateur le réinitialise à la demande de l'auditeur. Le nouveau mot de passe (format simple, 6
 * minuscules et chiffres) est posé sur eCampus d'abord, puis gardé par le portail : lettre d'accès,
 * identifiants envoyés et connexion à l'espace auditeur suivent. Aucun mot de passe n'est journalisé.
 */
final class MotsDePasseEcampus
{
    public const PERMISSION = 'candidature.ecampus_mot_de_passe';

    public const EVENEMENT = 'ecampus_mot_de_passe_reinitialise';

    public function __construct(private readonly ClientMoodle $moodle) {}

    /**
     * Le compte eCampus qui porte l'identifiant de l'auditeur, null s'il n'existe pas.
     *
     * @return array{id: int, identifiant: string, nom: string, email: string, suspendu: bool,
     *     derniere_connexion: ?CarbonImmutable, correspond: bool}|null
     */
    public function compte(Candidature $c): ?array
    {
        $compte = $this->moodle->comptesParChamp('username', [IdentifiantsMoodle::identifiant($c)])[0] ?? null;
        if ($compte === null || ! isset($compte['id'])) {
            return null;
        }
        $derniere = (int) ($compte['lastaccess'] ?? 0);

        return [
            'id' => (int) $compte['id'],
            'identifiant' => (string) ($compte['username'] ?? ''),
            'nom' => trim(($compte['firstname'] ?? '').' '.($compte['lastname'] ?? '')),
            'email' => (string) ($compte['email'] ?? ''),
            'suspendu' => (bool) ($compte['suspended'] ?? false),
            'derniere_connexion' => $derniere > 0 ? CarbonImmutable::createFromTimestamp($derniere) : null,
            'correspond' => $this->correspond($c, $compte),
        ];
    }

    /**
     * Pose un nouveau mot de passe sur eCampus puis dans le portail, et le renvoie pour être communiqué.
     * Refusé si le compte n'existe pas ou ne semble pas être celui de l'auditeur (ni sa marque, ni son e-mail).
     */
    public function reinitialiser(Candidature $c, User $auteur): string
    {
        // Deux clics simultanés poseraient deux mots de passe, dont un seul gardé par le portail.
        $resultat = Cache::lock('ecampus-compte:'.$c->id, 60)->get(function () use ($c, $auteur): string {
            $compte = $this->compte($c);
            if ($compte === null) {
                throw new EchecEcampus('Aucun compte eCampus ne porte l\'identifiant '.IdentifiantsMoodle::identifiant($c).'.');
            }
            if (! $compte['correspond']) {
                throw new EchecEcampus('Le compte eCampus '.$compte['identifiant'].' ne porte ni le matricule ni l\'e-mail de ce dossier : mot de passe laissé tel quel.');
            }

            $motDePasse = AttributionMatricules::motDePasse();
            $this->moodle->changerMotDePasse($compte['id'], $motDePasse);
            $c->forceFill(['moodle_password' => $motDePasse])->save();

            activity('candidatures')->causedBy($auteur)->performedOn($c)->event(self::EVENEMENT)
                ->withProperties(['identifiant' => $compte['identifiant']])
                ->log('Mot de passe eCampus réinitialisé à la demande de l\'auditeur');

            return $motDePasse;
        });

        if ($resultat === false) {
            throw new EchecEcampus('Une opération sur ce compte eCampus est déjà en cours : réessayez dans un instant.');
        }

        return $resultat;
    }

    /** @param  array<string, mixed>  $compte */
    private function correspond(Candidature $c, array $compte): bool
    {
        $marque = filled($c->identifiant_ecampus) ? (string) $c->identifiant_ecampus : (string) $c->matricule;
        $egal = fn (string $a, string $b): bool => $a !== '' && strcasecmp(trim($a), trim($b)) === 0;

        return $egal((string) ($compte['idnumber'] ?? ''), $marque) || $egal((string) ($compte['email'] ?? ''), (string) $c->email);
    }
}
