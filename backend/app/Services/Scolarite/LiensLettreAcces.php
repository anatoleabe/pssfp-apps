<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Mail\NotificationCandidatMail;
use App\Models\Candidature;
use App\Models\LienLettreAcces;
use App\Models\User;
use App\Services\DocumentsAuditeurs\ParametresLettres;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Liens courts vers la lettre d'accès FOAD, envoyés par SMS à la place des
 * codes en clair (décision du 13/11/2026).
 *
 * Un lien seul ne suffit pas : l'auditeur saisit sa date de naissance. Le
 * jeton (16 caractères aléatoires, ~95 bits) n'est jamais stocké, seule son
 * empreinte. Valable 7 jours et 5 téléchargements, verrouillé après 5 dates
 * fausses, révoqué par l'envoi d'un nouveau lien, fermé dès que le dossier
 * n'est plus remettable (droits non payés, retrait).
 */
final class LiensLettreAcces
{
    public const LONGUEUR_JETON = 16;

    public const OK = 'ok';

    public const DATE_INCORRECTE = 'date_incorrecte';

    public const VERROUILLE = 'verrouille';

    public const INVALIDE = 'invalide';

    public function __construct(private readonly SmsServiceInterface $sms) {}

    /** ASCII, un seul segment, aucune ponctuation collée à l'adresse. */
    public static function texteSms(string $url): string
    {
        return "PSSFP : telechargez votre lettre d'acces FOAD sur {$url} (lien valable "
            .LienLettreAcces::DUREE_JOURS.' jours, date de naissance demandee)';
    }

    /** Un lien a du sens : codes remettables, mot de passe connu, lettre éditable, date de naissance connue. */
    public static function possible(Candidature $c): bool
    {
        if (! IdentifiantsMoodle::remettables($c) || $c->date_naissance === null) {
            return false;
        }
        $c->loadMissing('campagne');

        return $c->campagne !== null
            && ParametresLettres::pour($c->campagne)->manquants(ParametresLettres::DOCUMENT_ACCES) === [];
    }

    /**
     * Crée un lien (ceux encore ouverts du dossier sont révoqués) et rend son adresse.
     *
     * @throws InvalidArgumentException dossier sans lien possible
     */
    public function creer(Candidature $c, ?User $auteur): string
    {
        if (! self::possible($c)) {
            throw new InvalidArgumentException("Dossier {$c->numero_dossier} : lettre d'accès non remettable par lien (droits non payés, mot de passe ou date de naissance inconnus, ou lettre non paramétrée).");
        }
        $jeton = Str::random(self::LONGUEUR_JETON);

        DB::transaction(function () use ($c, $auteur, $jeton): void {
            // Verrou sur le dossier : deux envois simultanés (double clic, solde et action
            // manuelle) ne laissent jamais deux liens ouverts.
            Candidature::query()->whereKey($c->id)->lockForUpdate()->first();
            self::revoquer($c->id);
            LienLettreAcces::query()->create([
                'candidature_id' => $c->id,
                'empreinte' => hash('sha256', $jeton),
                'expire_le' => now()->addDays(LienLettreAcces::DUREE_JOURS),
                'cree_par' => $auteur?->id,
            ]);
        });

        return rtrim((string) config('pssfp.candidature_app_url'), '/').'/l/'.$jeton;
    }

    /** Ferme les liens encore ouverts d'un dossier (nouveau lien, numéro de téléphone changé). */
    public static function revoquer(int $candidatureId): void
    {
        LienLettreAcces::query()->where('candidature_id', $candidatureId)->whereNull('revoque_le')->update(['revoque_le' => now()]);
    }

    /** Sujet de l'e-mail des accès (lien vers la lettre, ou à défaut l'espace). */
    public const SUJET_EMAIL = 'PSSFP — Vos accès à la plateforme de formation à distance';

    public static function texteEmail(string $url): string
    {
        $espace = (string) config('pssfp.candidature_app_url');

        return "Votre inscription au PSSFP est soldée : vos accès à la plateforme de formation à distance (eCampus) sont prêts.\n\n"
            ."Téléchargez votre lettre d'accès (identifiant et mot de passe) à l'adresse suivante, en confirmant votre date de naissance :\n"
            .$url."\n\n"
            .'Ce lien personnel est valable '.LienLettreAcces::DUREE_JOURS.' jours. Passé ce délai, retrouvez votre lettre dans votre espace '
            .$espace.' ou demandez un nouveau lien à la scolarité. Ne le transmettez à personne.';
    }

    /** Sans lien possible : l'espace, où l'auditeur retrouve ses accès. */
    public static function texteEmailEspace(): string
    {
        return 'Vos accès à la plateforme de formation à distance (eCampus) sont disponibles dans votre espace : '
            .config('pssfp.candidature_app_url')."\n\n"
            .'Connectez-vous avec votre numéro de téléphone et votre code, ou avec votre matricule.';
    }

    /**
     * Prévient l'auditeur par SMS ET par e-mail (13/11/2026 : un SMS refusé,
     * vers un numéro étranger par exemple, ne doit pas le laisser sans
     * nouvelles). Un seul lien, le même dans les deux messages ; à défaut de
     * lien possible, l'adresse de l'espace. Jamais de mot de passe. Ne lève pas.
     *
     * @return array{sms: ?bool, email: ?bool} true envoyé, false échec, null pas de coordonnée
     */
    public function prevenir(Candidature $c, ?User $auteur): array
    {
        $lien = null;
        if (self::possible($c)) {
            try {
                $lien = $this->creer($c, $auteur);
            } catch (Throwable $e) {
                Log::channel('single')->error('Lien de la lettre d\'accès non créé : message vers l\'espace.', ['dossier' => $c->numero_dossier, 'erreur' => $e::class]);
            }
        }

        return [
            'sms' => blank($c->phone_e164) ? null : $this->tenter($c, 'sms', fn () => $this->sms->send(
                (string) $c->phone_e164,
                $lien !== null ? self::texteSms($lien) : NotificationAcces::texte(),
            )),
            'email' => blank($c->email) ? null : $this->tenter($c, 'email', fn () => Mail::to((string) $c->email)->sendNow(
                new NotificationCandidatMail($c, self::SUJET_EMAIL, $lien !== null ? self::texteEmail($lien) : self::texteEmailEspace()),
            )),
        ];
    }

    /** Nouveau lien envoyé par SMS et par e-mail depuis la fiche de l'auditeur. Ne lève pas. */
    public function envoyer(Candidature $c, User $auteur): bool
    {
        if (! self::possible($c)) {
            return false;
        }
        $envoi = $this->prevenir($c, $auteur);
        if ($envoi['sms'] !== true && $envoi['email'] !== true) {
            return false;
        }
        activity('scolarite')->causedBy($auteur)->performedOn($c)
            ->event('lettre_acces_lien_envoye')
            ->withProperties($envoi)
            ->log('Lien de la lettre d\'accès FOAD envoyé (SMS et e-mail)');

        return true;
    }

    private function tenter(Candidature $c, string $canal, callable $envoi): bool
    {
        try {
            $envoi();

            return true;
        } catch (Throwable $e) {
            // Jamais le texte : il porte le lien.
            Log::channel('single')->error('Message des accès FOAD non envoyé.', ['dossier' => $c->numero_dossier, 'canal' => $canal, 'erreur' => $e::class]);

            return false;
        }
    }

    /** Lien utilisable dont le dossier est encore remettable, ou null. */
    public function trouver(string $jeton): ?LienLettreAcces
    {
        if (preg_match('/\A[A-Za-z0-9]{'.self::LONGUEUR_JETON.'}\z/', $jeton) !== 1) {
            return null;
        }
        $lien = LienLettreAcces::query()->with('candidature.campagne')->where('empreinte', hash('sha256', $jeton))->first();

        return $lien !== null && $lien->utilisable() && $lien->candidature !== null && self::possible($lien->candidature)
            ? $lien
            : null;
    }

    /**
     * Compare la date de naissance et compte l'essai, sous verrou de ligne
     * (deux envois simultanés ne contournent ni le plafond ni le verrou).
     *
     * @return array{statut: string, restantes: int}
     */
    public function verifier(LienLettreAcces $lien, string $dateNaissance, string $ip): array
    {
        return DB::transaction(function () use ($lien, $dateNaissance, $ip): array {
            $l = LienLettreAcces::query()->with('candidature')->lockForUpdate()->findOrFail($lien->id);
            if (! $l->utilisable()) {
                return ['statut' => self::INVALIDE, 'restantes' => 0];
            }
            $attendue = (string) $l->candidature->date_naissance?->toDateString();

            if ($attendue !== '' && hash_equals($attendue, $dateNaissance)) {
                $l->increment('telechargements');
                activity('auth.candidat')->performedOn($l->candidature)
                    ->event('lettre_acces_telechargee_par_lien')
                    ->withProperties(['ip' => $ip, 'telechargement' => $l->telechargements])
                    ->log('Lettre d\'accès FOAD (PDF) téléchargée par le lien du SMS');

                return ['statut' => self::OK, 'restantes' => LienLettreAcces::ECHECS_MAX - $l->echecs];
            }

            $l->increment('echecs');
            $restantes = max(0, LienLettreAcces::ECHECS_MAX - $l->echecs);
            activity('auth.candidat')->performedOn($l->candidature)
                ->event($restantes === 0 ? 'lettre_acces_lien_verrouille' : 'lettre_acces_lien_echec')
                ->withProperties(['ip' => $ip, 'echecs' => $l->echecs])
                ->log($restantes === 0 ? 'Lien de la lettre d\'accès verrouillé après des dates de naissance erronées' : 'Date de naissance erronée sur le lien de la lettre d\'accès');

            return ['statut' => $restantes === 0 ? self::VERROUILLE : self::DATE_INCORRECTE, 'restantes' => $restantes];
        });
    }
}
