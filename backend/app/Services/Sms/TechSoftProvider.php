<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Exceptions\NotConfiguredException;
use App\Support\PhoneMasker;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Passerelle SMS TechSoft Bulk SMS v3 (https://app.techsoft-sms.com).
 *
 * Contrat réel, vérifié contre l'API de production le 18 septembre 2026 :
 *
 * - `POST {base}/sms/send`, corps JSON, `Authorization: Bearer {token}`.
 * - `type` vaut `plain` pour un SMS texte.
 * - `recipient` accepte plusieurs numéros séparés par des virgules. On
 *   n'envoie qu'un numéro à la fois : un refus partiel sur un lot serait
 *   impossible à attribuer, et la traçabilité par candidat y perdrait.
 * - Succès : `{"status":"success","data":[{uid, status, cost, sent_at}]}`.
 *   `data` est un TABLEAU à l'envoi, un OBJET sur `GET /sms/{uid}`.
 * - Erreur : `{"status":"error","code":104,"message":"…"}` avec un code HTTP
 *   cohérent, contrairement à Echo SMS qui répondait 200 sur un refus métier.
 *
 * Le jeton voyage en en-tête et n'est jamais repris dans un message d'erreur
 * ni dans un log.
 */
final class TechSoftProvider implements ChecksConnectivity, DescribesConfiguration, QueriesMessageStatus, ReportsSmsDelivery, SmsServiceInterface
{
    private const TIMEOUT_SECONDS = 20;

    /** @var array<string, string> Statuts observés en production. */
    private const STATUTS = [
        'delivered' => 'Livré',
        'success' => 'Envoyé',
        'failed' => 'Échec',
        'pending' => 'En attente',
    ];

    public function decrire(): SmsConfigurationSummary
    {
        $senderId = (string) config('services.techsoft.sender_id', '');

        return new SmsConfigurationSummary(
            libelle: 'TechSoft Bulk SMS',
            expediteur: $senderId === '' ? null : $senderId,
            jetonConfigure: $this->apiToken() !== '',
            envoiReel: true,
        );
    }

    public function send(string $phoneE164, string $message): void
    {
        $this->sendAndReport($phoneE164, $message);
    }

    public function sendAndReport(string $phoneE164, string $message): SmsSendResult
    {
        $senderId = $this->senderId();

        $response = $this->requete()->post($this->baseUrl().'/sms/send', [
            'recipient' => $phoneE164,
            'sender_id' => $senderId,
            'type' => 'plain',
            'message' => $message,
        ]);

        $this->refuserSiErreur($response);

        $premier = $response->json('data.0');
        $premier = is_array($premier) ? $premier : [];

        Log::channel('sms')->info('[techsoft] SMS envoyé', [
            'phone' => PhoneMasker::mask($phoneE164),
            'uid' => $premier['uid'] ?? null,
            'status' => $premier['status'] ?? null,
            'length' => mb_strlen($message),
        ]);

        return new SmsSendResult(
            expediteur: is_string($premier['from'] ?? null) ? $premier['from'] : $senderId,
            codeFournisseur: null,
            messageId: isset($premier['uid']) ? (string) $premier['uid'] : null,
            statut: isset($premier['status']) ? (string) $premier['status'] : null,
            cout: isset($premier['cost']) ? (string) $premier['cost'] : null,
        );
    }

    public function verifierConnexion(): ConnectivityReport
    {
        // Tout est dans le try, y compris baseUrl() qui lève quand la
        // configuration est incomplète : un diagnostic doit pouvoir dire
        // « injoignable » sans faire tomber la page qui l'affiche.
        try {
            $response = $this->requete()->get($this->baseUrl().'/user');
        } catch (NotConfiguredException $e) {
            return new ConnectivityReport(false, null, null, $e->getMessage());
        } catch (\Throwable $e) {
            return new ConnectivityReport(false, null, null, 'Passerelle injoignable.');
        }

        if (! $response->successful()) {
            $code = (string) $response->status();

            return new ConnectivityReport(
                false, null, null,
                TechSoftCodes::libelle($code) ?? 'La passerelle a refusé la requête (code '.$code.').',
            );
        }

        // ATTENTION : la réponse contient `api_token` en clair. On n'extrait
        // que ces trois champs et on ne journalise jamais le corps complet.
        $prenom = $response->json('first_name');
        $nom = $response->json('last_name');
        $solde = $response->json('sms_unit');

        $compte = trim(
            (is_string($prenom) ? $prenom : '').' '.(is_string($nom) ? $nom : '')
        );

        return new ConnectivityReport(
            joignable: true,
            compte: $compte === '' ? null : $compte,
            solde: is_scalar($solde) ? (string) $solde : null,
            erreur: null,
        );
    }

    public function statutMessage(string $uid): MessageStatus
    {
        $response = $this->requete()->get($this->baseUrl().'/sms/'.urlencode($uid));

        $this->refuserSiErreur($response);

        $brut = $response->json('data.status');
        $brut = is_scalar($brut) ? (string) $brut : '';
        $cout = $response->json('data.cost');

        $normalise = mb_strtolower($brut);

        return new MessageStatus(
            brut: $brut,
            // Statut inconnu : on rend le brut, jamais un libellé inventé.
            libelle: self::STATUTS[$normalise] ?? ($brut === '' ? 'Inconnu' : $brut),
            livre: $normalise === 'delivered',
            cout: is_scalar($cout) ? (string) $cout : null,
        );
    }

    /**
     * Requête préconfigurée. Centralisée pour que l'en-tête d'authentification
     * ne soit écrit qu'à un seul endroit.
     */
    private function requete(): PendingRequest
    {
        return Http::asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiToken(),
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Traduit une réponse d'erreur en exception lisible.
     *
     * Ne reprend jamais l'URL ni les en-têtes : le jeton y figurerait.
     */
    private function refuserSiErreur(Response $response): void
    {
        $statut = $response->json('status');

        if ($response->successful() && $statut !== 'error') {
            return;
        }

        $code = $response->json('code');
        $code = is_scalar($code) ? (string) $code : (string) $response->status();

        $libelle = TechSoftCodes::libelle($code);
        $detail = $response->json('message');

        throw new RuntimeException(
            'TechSoft a refusé l\'envoi (code '.$code.' : '
            .($libelle ?? (is_string($detail) && $detail !== '' ? $detail : 'motif inconnu')).').'
        );
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.techsoft.base_url', ''), '/');

        if ($url === '' || $this->apiToken() === '') {
            throw new NotConfiguredException(
                'TechSoft non configuré. Renseignez TECHSOFT_BASE_URL et TECHSOFT_API_TOKEN, '
                .'ou repassez SMS_PROVIDER=fake.'
            );
        }

        return $url;
    }

    private function apiToken(): string
    {
        return (string) config('services.techsoft.api_token', '');
    }

    private function senderId(): string
    {
        $senderId = (string) config('services.techsoft.sender_id', '');

        if ($senderId === '') {
            throw new NotConfiguredException(
                'TECHSOFT_SENDER_ID est requis pour envoyer un SMS via TechSoft.'
            );
        }

        // TechSoft tronque silencieusement au-delà de 11 caractères : mieux
        // vaut refuser que laisser partir un expéditeur amputé.
        if (mb_strlen($senderId) > 11) {
            throw new NotConfiguredException(
                'TECHSOFT_SENDER_ID dépasse 11 caractères — TechSoft le tronquerait.'
            );
        }

        return $senderId;
    }
}
