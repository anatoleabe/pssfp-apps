<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Services\Sms\ReportsSmsDelivery;
use App\Services\Sms\SmsServiceInterface;
use App\Support\PhoneMasker;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relance par SMS les candidats dont le dossier n'avance plus.
 *
 * Deux populations, deux messages (cf. config/relance_sms.php) :
 * - `ready`      : dossier complet jamais soumis, il ne manque que le clic.
 * - `photo_only` : seule la photo d'identité manque.
 *
 * Garde-fous, dans l'ordre où ils s'appliquent :
 * 1. Anti-doublon — un candidat déjà relancé avec succès pour une cause n'est
 *    jamais relancé une seconde fois pour la même cause.
 * 2. Numéro obligatoire — un dossier sans téléphone exploitable est ignoré,
 *    pas mis en échec.
 * 3. Simulation par défaut — l'appelant doit demander explicitement l'envoi.
 * 4. Échec isolé — l'échec d'un envoi est journalisé et n'interrompt pas la
 *    campagne de relance.
 */
final class RelanceCandidatureService
{
    /** @var list<string> */
    public const CAUSES = [
        CandidatureService::DRAFT_READY,
        CandidatureService::DRAFT_PHOTO_ONLY,
    ];

    public function __construct(
        private readonly CandidatureService $candidatures,
        private readonly SmsServiceInterface $sms,
    ) {}

    /**
     * @param  list<string>  $causes
     * @return array{envoyes: int, echecs: int, ignores_deja_relances: int, ignores_sans_numero: int, details: list<array<string, mixed>>}
     */
    public function relancer(
        CampagneCandidature $campagne,
        array $causes,
        bool $envoiReel,
        ?int $limite = null,
    ): array {
        $buckets = $this->candidatures->classifyDraftsForCampagne($campagne->id);

        $rapport = [
            'envoyes' => 0,
            'echecs' => 0,
            'ignores_deja_relances' => 0,
            'ignores_sans_numero' => 0,
            'details' => [],
        ];

        $restants = $limite;

        foreach ($causes as $cause) {
            foreach ($buckets[$cause] ?? [] as $candidatureId) {
                if ($restants !== null && $restants <= 0) {
                    break 2;
                }

                $candidature = Candidature::find($candidatureId);
                if ($candidature === null) {
                    continue;
                }

                if ($this->dejaRelance($candidature, $cause)) {
                    $rapport['ignores_deja_relances']++;

                    continue;
                }

                $numero = $this->numeroDeContact($candidature);
                if ($numero === null) {
                    $rapport['ignores_sans_numero']++;

                    continue;
                }

                $message = $this->message($cause, $campagne);

                if (! $envoiReel) {
                    $rapport['details'][] = [
                        'dossier' => $candidature->numero_dossier,
                        'telephone' => PhoneMasker::mask($numero),
                        'cause' => $cause,
                        'message' => $message,
                        'simule' => true,
                    ];
                    $restants = $restants === null ? null : $restants - 1;

                    continue;
                }

                $this->envoyer($candidature, $cause, $numero, $message, $rapport);
                $restants = $restants === null ? null : $restants - 1;
            }
        }

        return $rapport;
    }

    /**
     * @param  array{envoyes: int, echecs: int, ignores_deja_relances: int, ignores_sans_numero: int, details: list<array<string, mixed>>}  $rapport
     */
    private function envoyer(
        Candidature $candidature,
        string $cause,
        string $numero,
        string $message,
        array &$rapport,
    ): void {
        try {
            if ($this->sms instanceof ReportsSmsDelivery) {
                $resultat = $this->sms->sendAndReport($numero, $message);
                $expediteur = $resultat->expediteur;
                $code = $resultat->codeFournisseur;
            } else {
                $this->sms->send($numero, $message);
                $expediteur = null;
                $code = null;
            }

            CandidatureRelance::create([
                'candidature_id' => $candidature->id,
                'cause' => $cause,
                'canal' => 'sms',
                'statut' => CandidatureRelance::STATUT_ENVOYE,
                'expediteur' => $expediteur,
                'code_fournisseur' => $code,
                'sent_at' => now(),
            ]);

            activity('candidatures')
                ->performedOn($candidature)
                ->withProperties(['cause' => $cause, 'canal' => 'sms'])
                ->event('candidature_relance_envoyee')
                ->log('Relance SMS envoyée au candidat');

            $rapport['envoyes']++;
            $rapport['details'][] = [
                'dossier' => $candidature->numero_dossier,
                'telephone' => PhoneMasker::mask($numero),
                'cause' => $cause,
                'statut' => CandidatureRelance::STATUT_ENVOYE,
            ];
        } catch (Throwable $e) {
            // L'échec est tracé mais ne bloque pas les relances suivantes, et
            // n'interdit pas une nouvelle tentative plus tard (scopeAbouties).
            CandidatureRelance::create([
                'candidature_id' => $candidature->id,
                'cause' => $cause,
                'canal' => 'sms',
                'statut' => CandidatureRelance::STATUT_ECHEC,
                'erreur' => mb_substr($e->getMessage(), 0, 500),
                // Conservé même en échec : un Sender ID expiré est la cause
                // la plus fréquente, et il faut pouvoir la lire directement.
                'expediteur' => $this->expediteurConfigure(),
                'sent_at' => now(),
            ]);

            Log::channel('sms')->error('Relance SMS en échec', [
                'dossier' => $candidature->numero_dossier,
                'phone' => PhoneMasker::mask($numero),
                'cause' => $cause,
                'error' => $e->getMessage(),
            ]);

            $rapport['echecs']++;
            $rapport['details'][] = [
                'dossier' => $candidature->numero_dossier,
                'telephone' => PhoneMasker::mask($numero),
                'cause' => $cause,
                'statut' => CandidatureRelance::STATUT_ECHEC,
                'erreur' => $e->getMessage(),
            ];
        }
    }

    private function dejaRelance(Candidature $candidature, string $cause): bool
    {
        return CandidatureRelance::query()
            ->where('candidature_id', $candidature->id)
            ->where('cause', $cause)
            ->abouties()
            ->exists();
    }

    /**
     * Téléphone de contact du candidat. `phone_e164` est son identifiant de
     * connexion et le seul numéro garanti au format international ; les champs
     * indicatif1/telephone1 sont saisis à la main et servent de repli.
     */
    private function numeroDeContact(Candidature $candidature): ?string
    {
        $login = trim((string) $candidature->phone_e164);
        if ($login !== '' && preg_match('/^\+[1-9]\d{6,14}$/', $login) === 1) {
            return $login;
        }

        $compose = trim((string) $candidature->indicatif1).trim((string) $candidature->telephone1);
        $compose = preg_replace('/\s+/', '', $compose) ?? '';

        return preg_match('/^\+[1-9]\d{6,14}$/', $compose) === 1 ? $compose : null;
    }

    private function message(string $cause, CampagneCandidature $campagne): string
    {
        $template = (string) config("relance_sms.messages.{$cause}", '');
        $cloture = $campagne->closes_at?->format('d/m/Y') ?? 'la date de cloture';

        return strtr($template, [
            ':url' => (string) config('relance_sms.url'),
            ':date_cloture' => $cloture,
        ]);
    }

    /** Sender ID (ou numéro) configuré pour la passerelle SMS active. */
    private function expediteurConfigure(): ?string
    {
        if ((string) config('services.sms.provider') !== 'echosms') {
            return null;
        }

        $type = (string) config('services.echosms.from_type', 'sender_id');
        $valeur = (string) config(
            $type === 'sender_id' ? 'services.echosms.sender_id' : 'services.echosms.from_number',
            ''
        );

        return $valeur === '' ? null : $valeur;
    }
}
