<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CandidatureCreated;
use App\Events\CandidatureSubmitted;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cycle de vie d'une candidature côté API (cf. spec module 5 §M3, §M5).
 *
 * - currentCampagne()    : campagne actuellement ouverte (ou null).
 * - findForUser()        : récupère le dossier du candidat sur la campagne courante.
 * - upsertForUser()      : trouve ou crée le dossier au statut postulant. Idempotent.
 * - updateDraft()        : update partiel des champs profil tant que statut == postulant.
 * - submit()             : transition postulant -> candidat, génère le PDF, stocke,
 *                          honore X-Idempotency-Key (cache Redis 5 min).
 *
 * Le service ne fait pas la validation HTTP — celle-ci reste dans les FormRequests.
 * Il garde la cohérence métier et l'audit trail (activity_log).
 */
final class CandidatureService
{
    private const SUBMIT_IDEMPOTENCY_TTL_SECONDS = 300; // 5 min

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RecipisseService $recipisse,
    ) {}

    public function currentCampagne(): ?CampagneCandidature
    {
        return CampagneCandidature::query()->currentlyOpen()->first();
    }

    public function findForUser(User $user, CampagneCandidature $campagne): ?Candidature
    {
        return Candidature::query()
            ->where('user_id', $user->id)
            ->where('campagne_id', $campagne->id)
            ->first();
    }

    /**
     * Idempotent : retourne la Candidature existante du couple (user, campagne)
     * ou en crée une nouvelle au statut postulant (numero_dossier auto via observer).
     *
     * @param  array<string, mixed>  $initialFields  champs additionnels à set à la création
     */
    public function upsertForUser(User $user, CampagneCandidature $campagne, array $initialFields = []): Candidature
    {
        $existing = $this->findForUser($user, $campagne);
        if ($existing !== null) {
            return $existing;
        }

        $candidature = $this->db->transaction(function () use ($user, $campagne, $initialFields): Candidature {
            return Candidature::create(array_merge([
                'campagne_id' => $campagne->id,
                'user_id' => $user->id,
                'phone_e164' => $user->phone_e164 ?? throw new RuntimeException('User has no phone_e164'),
                'phone_country' => $user->phone_country ?? 'CM',
                'nom' => $this->extractLastName($user->name ?? ''),
                'prenom' => $this->extractFirstName($user->name ?? ''),
                'date_naissance' => $user->date_naissance,
                'statut' => Candidature::STATUT_POSTULANT,
                'frais_paye' => false,
            ], $initialFields));
        });

        try {
            CandidatureCreated::dispatch($candidature);
        } catch (\Throwable $e) {
            Log::channel('single')->error('Dispatch CandidatureCreated échoué — dossier créé.', [
                'candidature_uuid' => $candidature->uuid,
                'error' => $e->getMessage(),
            ]);
        }

        return $candidature;
    }

    /**
     * Update partiel d'une candidature en draft. Refuse si elle a déjà été soumise.
     *
     * @param  array<string, mixed>  $fields
     */
    public function updateDraft(Candidature $candidature, array $fields): Candidature
    {
        if ($candidature->statut !== Candidature::STATUT_POSTULANT) {
            throw new RuntimeException(
                'Cannot update a candidature that has already been submitted (statut: '
                .$candidature->statut.').'
            );
        }

        // Empêche la modification de champs systèmes via le body PUT.
        $forbidden = ['id', 'uuid', 'numero_dossier', 'campagne_id', 'user_id',
            'statut', 'submitted_at', 'reviewed_at', 'decided_at', 'withdrawn_at',
            'frais_paye', 'mode_paiement', 'reference_paiement', 'date_paiement',
            'recipisse_pdf_path', 'recipisse_hash_sha256',
            'created_at', 'updated_at', 'deleted_at',
        ];
        $clean = array_diff_key($fields, array_flip($forbidden));

        $candidature->fill($clean)->save();

        return $candidature->refresh();
    }

    /**
     * Vérifie qu'une Candidature peut être soumise. Retourne un dictionnaire
     * `{champ => message}` des erreurs (vide si tout OK).
     *
     * Combine :
     *  - Champs profil obligatoires (cf. spec §M6) qui doivent tous être remplis.
     *  - Règles métier (cf. ajout 3 PR C) : annee_diplome cohérente, region/dept
     *    obligatoires si pays_residence == CM, specialite dans la liste blanche.
     *
     * @return array<string, string>
     */
    public function checkSubmittable(Candidature $candidature): array
    {
        $errors = [];

        if (empty($candidature->photo_path)) {
            $errors['photo'] = 'La photo d\'identité est obligatoire pour soumettre la candidature.';
        }

        $required = [
            'civilite', 'nom', 'prenom', 'date_naissance', 'lieu_naissance',
            'genre', 'statut_matrimonial', 'nationalite', 'pays_origine',
            'pays_residence', 'adresse', 'ville_residence',
            'indicatif1', 'telephone1',
            'specialite', 'type_etude', 'premiere_langue',
            'diplome_obtenu', 'institut', 'specialite_diplome', 'annee_diplome',
            'statut_actuel', 'engagement_nom',
        ];

        foreach ($required as $field) {
            $value = $candidature->{$field};
            if ($value === null || $value === '') {
                $errors[$field] = "Champ obligatoire manquant pour la soumission : {$field}.";
            }
        }

        if ($candidature->annee_diplome !== null && $candidature->annee_diplome > now()->year) {
            $errors['annee_diplome'] = "L'année du diplôme ne peut pas être dans le futur.";
        }

        if ($candidature->date_naissance !== null && $candidature->annee_diplome !== null) {
            $diff = $candidature->annee_diplome - (int) $candidature->date_naissance->format('Y');
            if ($diff < 18) {
                $errors['annee_diplome'] = "L'écart entre la date de naissance et l'année du diplôme doit être d'au moins 18 ans.";
            }
        }

        if ($candidature->pays_residence === 'CM') {
            if (empty($candidature->region)) {
                $errors['region'] = 'La région est obligatoire pour un candidat résidant au Cameroun.';
            }
            if (empty($candidature->departement)) {
                $errors['departement'] = 'Le département est obligatoire pour un candidat résidant au Cameroun.';
            }
        }

        $allowed = array_values((array) config('specialites', []));
        if (! empty($candidature->specialite) && ! in_array($candidature->specialite, $allowed, true)) {
            $errors['specialite'] = 'La spécialité demandée n\'est pas reconnue.';
        }

        return $errors;
    }

    /**
     * Transition postulant -> candidat. Génère le PDF récépissé, hash SHA256,
     * stocke dans MinIO, log dans activity_log.
     *
     * Idempotent via X-Idempotency-Key : un même UUID re-joué dans la fenêtre
     * de 5 min retourne le résultat précédent sans régénérer le PDF.
     *
     * @return array{candidature: Candidature, pdf_url: string}
     */
    public function submit(Candidature $candidature, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey !== null) {
            $cacheKey = $this->idempotencyCacheKey($candidature, $idempotencyKey);
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return [
                    'candidature' => $candidature->refresh(),
                    'pdf_url' => $cached['pdf_url'],
                ];
            }
        }

        if ($candidature->statut !== Candidature::STATUT_POSTULANT) {
            throw new RuntimeException(
                'Cannot submit a candidature already at statut '.$candidature->statut.'.'
            );
        }

        $result = $this->db->transaction(function () use ($candidature): array {
            // Verrou pessimiste : deux soumissions concurrentes (double-clic,
            // même clé d'idempotence en cache-miss) seraient sérialisées ici.
            // La seconde relit statut=candidat et échoue → pas de double
            // récépissé / email / activity_log (cf. revue candidature LOT A).
            $locked = Candidature::query()
                ->whereKey($candidature->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->statut !== Candidature::STATUT_POSTULANT) {
                throw new RuntimeException(
                    'Cannot submit a candidature already at statut '.($locked->statut ?? 'missing').'.'
                );
            }

            $pdf = $this->recipisse->generate($locked);

            $locked->update([
                'statut' => Candidature::STATUT_CANDIDAT,
                'submitted_at' => now(),
                'recipisse_pdf_path' => $pdf['path'],
                'recipisse_hash_sha256' => $pdf['hash'],
            ]);

            activity('candidatures')
                ->causedBy(auth()->user())
                ->performedOn($locked)
                ->withProperties([
                    'ip' => request()?->ip(),
                    'pdf_path' => $pdf['path'],
                    'pdf_hash' => $pdf['hash'],
                ])
                ->event('candidature_submitted')
                ->log('Candidature soumise (postulant -> candidat)');

            return ['path' => $pdf['path'], 'url' => $this->recipisse->signedUrl($pdf['path'])];
        });

        if ($idempotencyKey !== null) {
            Cache::put(
                $this->idempotencyCacheKey($candidature, $idempotencyKey),
                ['pdf_url' => $result['url']],
                self::SUBMIT_IDEMPOTENCY_TTL_SECONDS
            );
        }

        $fresh = $candidature->refresh();

        // Email de confirmation + instructions frais CREMINCAM. Dispatché
        // hors transaction et seulement sur une soumission réelle (un rejeu
        // idempotent sort en amont via le cache). Le listener dégrade
        // proprement si le candidat n'a pas d'email.
        //
        // Best-effort : une indisponibilité de la queue Redis ne doit pas
        // renvoyer 500 alors que la soumission est déjà committée (le récépissé
        // reste accessible depuis l'espace candidat). On trace et on continue.
        try {
            CandidatureSubmitted::dispatch($fresh);
        } catch (\Throwable $e) {
            Log::channel('single')->error(
                'Dispatch CandidatureSubmitted échoué (queue indisponible ?) — soumission committée, email différé.',
                ['candidature_uuid' => $fresh->uuid, 'error' => $e->getMessage()],
            );
        }

        return [
            'candidature' => $fresh,
            'pdf_url' => $result['url'],
        ];
    }

    private function idempotencyCacheKey(Candidature $candidature, string $key): string
    {
        return "candidature:submit:{$candidature->uuid}:".sha1($key);
    }

    private function extractFirstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));

        return $parts[0] ?? '';
    }

    private function extractLastName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        if (count($parts) <= 1) {
            return '';
        }

        return implode(' ', array_slice($parts, 1));
    }

    /**
     * Retrait par le candidat lui-même (cf. PR F endpoint).
     *
     * Refusé si la candidature est déjà décidée (accepte/refuse) ou
     * déjà retirée. Set withdrawn_at + activity_log.
     *
     * @throws RuntimeException avec un kind ('already_decided' | 'already_withdrawn')
     */
    public function withdraw(Candidature $candidature): void
    {
        if ($candidature->withdrawn_at !== null) {
            throw new RuntimeException('already_withdrawn');
        }

        if (in_array($candidature->statut, [Candidature::STATUT_ACCEPTE, Candidature::STATUT_REFUSE], true)) {
            throw new RuntimeException('already_decided');
        }

        $candidature->update(['withdrawn_at' => now()]);

        activity('candidatures')
            ->causedBy(auth()->user())
            ->performedOn($candidature)
            ->withProperties([
                'ip' => request()?->ip(),
                'previous_statut' => $candidature->statut,
            ])
            ->event('candidature_withdrawn_self')
            ->log('Candidature retirée par le candidat lui-même');
    }
}
