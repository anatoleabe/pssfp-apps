<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prévient l'auditeur, une seule fois par inscription, que ses accès sont
 * disponibles, par SMS ET par e-mail : un lien vers sa lettre d'accès (date de
 * naissance exigée) quand c'est possible, sinon l'adresse de son espace.
 * Jamais de mot de passe. Prévenu si au moins un des deux messages est parti.
 *
 * Ne lève jamais : un incident de passerelle ne doit faire échouer ni le
 * versement ni la création du compte qui ont provoqué l'envoi.
 */
final class NotificationAcces
{
    public function __construct(private readonly LiensLettreAcces $liens) {}

    /**
     * Sans lien possible (mot de passe inconnu du portail, lettre non paramétrée).
     * ASCII, un seul segment, et pas de point collé à l'adresse : certaines
     * applications SMS le gardaient dans le lien (apply.pssfp.org.).
     */
    public static function texte(): string
    {
        $adresse = (string) preg_replace('#^https?://#', '', (string) config('pssfp.candidature_app_url'));

        return "PSSFP : vos acces FOAD sont disponibles dans votre espace {$adresse}";
    }

    /**
     * @return bool|null true envoyé, false échec (à renvoyer depuis la fiche),
     *                   null rien à envoyer (accès non disponible ou déjà notifié)
     */
    public function siDisponible(Candidature $dossier, ?User $auteur = null): ?bool
    {
        try {
            // Relecture : l'appelant peut tenir un état antérieur à sa propre transaction.
            $c = Candidature::query()->with('auditeur.inscriptionCourante.bareme')->find($dossier->id);
            if ($c === null || AccesEcampus::etat($c) !== AccesEcampus::DISPONIBLE) {
                return null;
            }
            $inscription = AccesEcampus::inscriptionCourante($c);
            if ($inscription === null || ! $this->reserver($inscription)) {
                return null;
            }
        } catch (Throwable $e) {
            Log::channel('single')->error('Notification des accès impossible.', ['dossier' => $dossier->numero_dossier, 'erreur' => $e::class]);

            return false;
        }

        $envoi = $this->liens->prevenir($c, $auteur);
        if ($envoi['sms'] !== true && $envoi['email'] !== true) {
            $this->sansEchec(function () use ($inscription, $c, $auteur, $envoi): void {
                // Ni SMS ni e-mail : la réservation saute, la fiche proposera « Renvoyer ».
                InscriptionAnnuelle::query()->whereKey($inscription->id)->update(['acces_notifie_at' => null]);
                activity('scolarite')->causedBy($auteur)->performedOn($c)->event('acces_sms_echec')
                    ->withProperties($envoi)->log('Accès disponibles : ni SMS ni e-mail envoyé');
            });

            return false;
        }

        $this->sansEchec(fn () => activity('scolarite')->causedBy($auteur)->performedOn($c)->event('acces_sms_envoye')
            ->withProperties($envoi)->log('Accès disponibles : auditeur prévenu par SMS et par e-mail'));

        return true;
    }

    private function sansEchec(callable $geste): void
    {
        try {
            $geste();
        } catch (Throwable $e) {
            Log::channel('single')->error('Journal du SMS « accès disponibles » non écrit.', ['erreur' => $e::class]);
        }
    }

    /** Mise à jour conditionnelle : deux saisies simultanées n'envoient pas deux SMS. */
    private function reserver(InscriptionAnnuelle $inscription): bool
    {
        return InscriptionAnnuelle::query()->whereKey($inscription->id)
            ->whereNull('acces_notifie_at')
            ->update(['acces_notifie_at' => now()]) === 1;
    }
}
