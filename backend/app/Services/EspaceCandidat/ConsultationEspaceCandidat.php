<?php

declare(strict_types=1);

namespace App\Services\EspaceCandidat;

use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatureService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Consultation de l'espace d'un candidat par l'administration, tel que le
 * candidat le voit, sans connaître son code et en lecture seule.
 *
 * 1. L'admin demande l'ouverture : un code d'échange aléatoire, à usage unique
 *    et valable une minute, est placé dans l'URL du portail candidature.
 * 2. Le portail échange ce code contre un jeton Sanctum du compte candidat
 *    limité à la lecture (aucune ability d'écriture) et valable 30 minutes.
 *
 * Seul le haché du code d'échange est conservé (cache), et chaque ouverture
 * est inscrite au journal d'activité au nom de l'agent.
 */
final class ConsultationEspaceCandidat
{
    public const PERMISSION = NouveauCodeCandidat::PERMISSION;

    public const NOM_JETON = 'consultation-admin';

    /** Lecture seule : aucune ability d'écriture des routes /applications/me. */
    public const ABILITIES = ['application:read', 'profile:read', 'consultation'];

    public const DUREE_LIEN_SECONDES = 60;

    public const DUREE_SESSION_MINUTES = 30;

    private const PREFIXE = 'consultation-espace:';

    public function __construct(private readonly CandidatureService $candidatures) {}

    public function ouvrir(Candidature $c, User $auteur): string
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        $compte = NouveauCodeCandidat::compteCandidat($c)
            ?? throw new InvalidArgumentException("Le dossier {$c->numero_dossier} n'est rattaché à aucun compte candidat.");

        $code = Str::random(64);
        Cache::put(self::cle($code), [
            'compte_id' => $compte->id,
            'candidature_id' => $c->id,
            'auteur_id' => $auteur->id,
        ], self::DUREE_LIEN_SECONDES);

        return config('pssfp.candidature_app_url').'/auth/consultation?code='.$code;
    }

    /**
     * @return array{token: string, expires_at: string}|null null si le code est inconnu, expiré ou déjà utilisé
     */
    public function echanger(string $code): ?array
    {
        $cle = self::cle($code);
        $donnees = Cache::get($cle);
        // `add` est atomique : deux requêtes simultanées ne peuvent pas
        // consommer le même code.
        if (! is_array($donnees) || ! Cache::add($cle.':utilise', true, self::DUREE_LIEN_SECONDES * 2)) {
            return null;
        }
        Cache::forget($cle);

        $compte = User::find($donnees['compte_id']);
        $candidature = Candidature::find($donnees['candidature_id']);
        $auteur = User::find($donnees['auteur_id']);
        // Tout est revérifié à l'échange : un retrait de droit de l'agent, ou un
        // compte devenu inéligible (rôle du personnel ajouté), pendant la minute
        // de validité du lien l'annule.
        if ($compte === null || $candidature === null || $auteur === null
            || ! $auteur->can(self::PERMISSION)
            || ! $auteur->canAccessPanel(Filament::getPanel('admin'))
            || NouveauCodeCandidat::compteCandidat($candidature)?->id !== $compte->id) {
            return null;
        }

        // Une seule consultation ouverte par compte : la précédente, devenue
        // orpheline si l'agent a relancé le bouton, est révoquée.
        $compte->tokens()->where('name', self::NOM_JETON)->delete();
        $jeton = $compte->createToken(self::NOM_JETON, self::ABILITIES, now()->addMinutes(self::DUREE_SESSION_MINUTES));

        // Le portail affiche le dossier « lisible » du compte (campagne ouverte,
        // sinon dernier retenu, sinon le plus récent), pas forcément celui sur
        // lequel l'agent a cliqué : le journal porte sur le dossier affiché.
        $affiche = $this->candidatures->findReadableForUser($compte) ?? $candidature;
        activity('candidatures')->causedBy($auteur)->performedOn($affiche)
            ->event('espace_consulte')
            ->withProperties(['dossier_demande' => $candidature->numero_dossier])
            ->log('Espace candidat consulté (lecture seule)');

        return [
            'token' => $jeton->plainTextToken,
            'expires_at' => (string) $jeton->accessToken->expires_at?->toIso8601String(),
        ];
    }

    private static function cle(string $code): string
    {
        return self::PREFIXE.hash('sha256', $code);
    }
}
