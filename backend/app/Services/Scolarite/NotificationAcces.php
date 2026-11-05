<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prévient l'auditeur, par un SMS sans mot de passe, que ses accès sont
 * disponibles dans son espace. Une seule fois par inscription.
 *
 * Ne lève jamais : un incident de passerelle ne doit faire échouer ni le
 * versement ni la création du compte qui ont provoqué l'envoi.
 */
final class NotificationAcces
{
    public function __construct(private readonly SmsServiceInterface $sms) {}

    /** ASCII et moins de 160 caractères : un seul segment facturé. */
    public static function texte(): string
    {
        $adresse = (string) preg_replace('#^https?://#', '', (string) config('pssfp.candidature_app_url'));

        return "PSSFP : vos acces FOAD sont disponibles dans votre espace {$adresse}.";
    }

    /**
     * @return bool|null true envoyé, false échec (à renvoyer depuis la fiche),
     *                   null rien à envoyer (accès non disponible ou déjà notifié)
     */
    public function siDisponible(Candidature $dossier): ?bool
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

        try {
            if (blank($c->phone_e164)) {
                throw new \RuntimeException('Dossier sans téléphone.');
            }
            $this->sms->send((string) $c->phone_e164, self::texte());
        } catch (Throwable $e) {
            // La réservation saute : la fiche proposera « Renvoyer ».
            InscriptionAnnuelle::query()->whereKey($inscription->id)->update(['acces_notifie_at' => null]);
            Log::channel('single')->error('SMS « accès disponibles » non envoyé.', ['dossier' => $c->numero_dossier, 'erreur' => $e::class]);
            activity('scolarite')->performedOn($c)->event('acces_sms_echec')->log('SMS « accès disponibles » non envoyé');

            return false;
        }

        activity('scolarite')->performedOn($c)->event('acces_sms_envoye')->log('SMS « accès disponibles » envoyé');

        return true;
    }

    /** Mise à jour conditionnelle : deux saisies simultanées n'envoient pas deux SMS. */
    private function reserver(InscriptionAnnuelle $inscription): bool
    {
        return InscriptionAnnuelle::query()->whereKey($inscription->id)
            ->whereNull('acces_notifie_at')
            ->update(['acces_notifie_at' => now()]) === 1;
    }
}
