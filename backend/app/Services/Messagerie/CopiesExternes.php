<?php

declare(strict_types=1);

namespace App\Services\Messagerie;

use App\Mail\CopieMessageMail;
use App\Models\EnvoiMessage;
use App\Services\Sms\SmsServiceInterface;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Lot 14b-1 (D31) : traite la file des copies externes (courriel, SMS), trois tentatives au plus. */
final class CopiesExternes
{
    public const LOT = 200;

    public function __construct(private readonly SmsServiceInterface $sms) {}

    /** @return array{envoyes: int, echecs: int, en_attente: int} */
    public function traiter(int $lot = self::LOT): array
    {
        $bilan = ['envoyes' => 0, 'echecs' => 0, 'en_attente' => 0];
        $envois = EnvoiMessage::query()->where('statut', EnvoiMessage::A_ENVOYER)
            ->where('tentatives', '<', EnvoiMessage::TENTATIVES_MAX)->with('message:id,uuid,objet')
            ->orderBy('id')->limit($lot)->get();
        foreach ($envois as $envoi) {
            $resultat = $this->envoyer($envoi);
            $bilan[$resultat]++;
        }

        return $bilan;
    }

    /** @return 'envoyes'|'echecs'|'en_attente' */
    private function envoyer(EnvoiMessage $envoi): string
    {
        $tentatives = $envoi->tentatives + 1;
        try {
            if ($envoi->canal === EnvoiMessage::EMAIL) {
                Mail::to((string) $envoi->adresse)->send(new CopieMessageMail($envoi->message->objet, Messagerie::lien($envoi->message)));
            } else {
                $this->sms->send((string) $envoi->adresse, $envoi->texte);
            }
            $envoi->forceFill(['statut' => EnvoiMessage::ENVOYE, 'tentatives' => $tentatives, 'envoye_le' => now(), 'derniere_erreur' => null])->save();

            return 'envoyes';
        } catch (Throwable $e) {
            // Ni secret ni adresse (un rejet SMTP cite souvent le destinataire) dans l'erreur conservée.
            $texte = (string) preg_replace(['/[^\s@<>"]+@[^\s@<>"]+/u', '/\+?\d{8,15}/'], ['[adresse]', '[numéro]'], (string) SecretRedactor::redact($e->getMessage()));
            $erreur = Str::limit(class_basename($e).' : '.$texte, 490, '');
            $abandon = $tentatives >= EnvoiMessage::TENTATIVES_MAX;
            $envoi->forceFill(['statut' => $abandon ? EnvoiMessage::ECHEC : EnvoiMessage::A_ENVOYER, 'tentatives' => $tentatives, 'derniere_erreur' => $erreur])->save();
            Log::warning('Copie externe d\'un message non envoyée', ['envoi' => $envoi->id, 'canal' => $envoi->canal, 'tentative' => $tentatives, 'erreur' => $erreur]);

            return $abandon ? 'echecs' : 'en_attente';
        }
    }
}
