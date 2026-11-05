<?php

declare(strict_types=1);

namespace App\Services\Ecampus;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\ExportsRetenus;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\NotificationAcces;
use App\Support\TexteImprimable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Compte eCampus d'un auditeur, créé à l'attribution de son matricule.
 *
 * Un compte déjà présent n'est jamais modifié, son mot de passe encore moins.
 * Seuls les comptes créés ici portent le matricule dans « idnumber » : cette
 * marque permet de terminer une création interrompue sans toucher aux autres.
 *
 * Le traitement reste synchrone pour que le mot de passe ne passe pas par la file.
 */
final class ComptesEcampus
{
    public const CREE = 'cree';

    public const COHORTE_A_FINIR = 'cohorte_a_finir';

    public const EXISTANT = 'existant';

    public const ECHEC = 'echec';

    public const DETAIL_INJOIGNABLE = 'eCampus ne répond pas : réessayez plus tard.';

    private const RAPPROCHEMENT_PAR_LOT = 50;

    public function __construct(
        private readonly ClientMoodle $moodle,
        private readonly NotificationAcces $notification,
    ) {}

    public static function actif(): bool
    {
        return filled(config('pssfp.ecampus.jeton'));
    }

    /** Aucun compte confirmé pour ce dossier : à créer, ou à reprendre après un échec. */
    public static function aTraiter(Candidature $c): bool
    {
        return IdentifiantsMoodle::disponibles($c)
            && ! in_array($c->ecampus_statut, [self::CREE, self::EXISTANT], true);
    }

    public function traiter(Candidature $c, User $auteur): ?string
    {
        // Contrôle ici aussi : un futur appelant ne doit pas contourner la permission de l'admin.
        abort_unless($auteur->can(IdentifiantsMoodle::PERMISSION), 403);
        if (! IdentifiantsMoodle::disponibles($c)) {
            throw new InvalidArgumentException("Le dossier {$c->numero_dossier} n'est pas un retenu doté d'un matricule.");
        }

        return $this->tenter($c, $auteur)['statut'];
    }

    /**
     * S'arrête à la première panne et une fois le temps alloué écoulé : les
     * dossiers restants gardent leur état et se reprennent avec le bouton.
     *
     * @param  Collection<int, Candidature>  $dossiers
     * @return array{crees: int, existants: int, echecs: int, restants: int}|null null sans jeton configuré
     */
    public function traiterPlusieurs(Collection $dossiers, User $auteur): ?array
    {
        if (! self::actif()) {
            return null;
        }

        abort_unless($auteur->can(IdentifiantsMoodle::PERMISSION), 403);

        $bilan = ['crees' => 0, 'existants' => 0, 'echecs' => 0, 'restants' => 0];
        $limite = microtime(true) + (int) config('pssfp.ecampus.budget_secondes', 20);
        $interrompu = false;

        foreach ($dossiers as $c) {
            if (! IdentifiantsMoodle::disponibles($c)) {
                continue;
            }
            if ($interrompu || microtime(true) >= $limite) {
                $bilan['restants']++;

                continue;
            }

            try {
                $resultat = $this->tenter($c, $auteur);
            } catch (Throwable $e) {
                Log::error('Compte eCampus non traité.', ['erreur' => $e::class]);
                $bilan['restants']++;
                $interrompu = true;

                continue;
            }

            match ($resultat['statut']) {
                self::CREE => $bilan['crees']++,
                self::EXISTANT => $bilan['existants']++,
                // Sans état : traité au même moment par un autre agent.
                null => $bilan['restants']++,
                default => $bilan['echecs']++,
            };
            $interrompu = $resultat['general'];
        }

        return $bilan;
    }

    /**
     * Suite d'une attribution de matricules, déjà validée : aucun incident ici
     * ne doit la faire apparaître comme ratée.
     *
     * @param  list<int>  $ids
     * @return array{crees: int, existants: int, echecs: int, restants: int}|null
     */
    public function apresAttribution(array $ids, User $auteur): ?array
    {
        if ($ids === []) {
            return null;
        }

        try {
            return $this->traiterPlusieurs(Candidature::query()->with('campagne')->whereIn('id', $ids)->get(), $auteur);
        } catch (Throwable $e) {
            Log::error('Comptes eCampus non traités après attribution.', ['erreur' => $e::class]);

            return ['crees' => 0, 'existants' => 0, 'echecs' => 0, 'restants' => count($ids)];
        }
    }

    /**
     * Note l'état des comptes déjà présents, sans aucune écriture sur eCampus.
     * Les comptes absents restent à créer.
     *
     * @return array{existants: int, absents: int, ecarts: int, a_finir: int}
     */
    public function rapprocher(CampagneCandidature $campagne): array
    {
        $bilan = ['existants' => 0, 'absents' => 0, 'ecarts' => 0, 'a_finir' => 0];

        AttributionMatricules::retenus($campagne)
            ->whereNotNull('matricule')
            ->whereNull('ecampus_statut')
            ->chunkById(self::RAPPROCHEMENT_PAR_LOT, function (Collection $lot) use (&$bilan): void {
                $identifiants = $lot->map(fn (Candidature $c): string => IdentifiantsMoodle::identifiant($c))->all();
                $comptes = collect($this->moodle->comptesParChamp('username', $identifiants))->keyBy('username');

                foreach ($lot as $c) {
                    $compte = $comptes->get(IdentifiantsMoodle::identifiant($c));
                    if ($compte === null) {
                        $bilan['absents']++;

                        continue;
                    }
                    // Marque du portail sans état noté : la création a été coupée
                    // avant la fin. L'entrée dans la cohorte reste à faire.
                    if ($this->porteLaMarque($c, $compte)) {
                        $this->noter($c, self::COHORTE_A_FINIR, 'Création interrompue : à terminer.');
                        $bilan['a_finir']++;

                        continue;
                    }
                    $detail = $this->ecarts($c, $compte);
                    $this->noter($c, self::EXISTANT, $detail);
                    $bilan['existants']++;
                    $bilan['ecarts'] += $detail === null ? 0 : 1;
                }
            });

        return $bilan;
    }

    /** @return array{statut: ?string, general: bool} */
    private function tenter(Candidature $c, User $auteur): array
    {
        if (! self::aTraiter($c)) {
            return ['statut' => $c->ecampus_statut, 'general' => false];
        }

        // Deux clics simultanés ne doivent pas lancer deux créations.
        $resultat = Cache::lock('ecampus-compte:'.$c->id, 60)->get(fn (): array => $this->synchroniser($c, $auteur));

        return $resultat === false ? ['statut' => $c->ecampus_statut, 'general' => false] : $resultat;
    }

    /** @return array{statut: string, general: bool} */
    private function synchroniser(Candidature $c, User $auteur): array
    {
        // Un compte déjà créé par le portail le reste, même si la reprise échoue.
        $compteDuPortail = $c->ecampus_statut === self::COHORTE_A_FINIR;
        $etape = $compteDuPortail ? self::COHORTE_A_FINIR : self::ECHEC;

        try {
            $identifiant = IdentifiantsMoodle::identifiant($c);
            $compte = $this->moodle->comptesParChamp('username', [$identifiant])[0] ?? null;

            if ($compte !== null && ! $compteDuPortail && ! $this->porteLaMarque($c, $compte)) {
                return ['statut' => $this->journaliser($c, self::EXISTANT, $this->ecarts($c, $compte), $auteur), 'general' => false];
            }

            if ($compte === null) {
                $this->creer($c, $identifiant);
            }

            $etape = self::COHORTE_A_FINIR;
            $this->moodle->ajouterACohorte($this->cohorte($c), $identifiant);

            return ['statut' => $this->journaliser($c, self::CREE, null, $auteur), 'general' => false];
        } catch (EchecEcampus $e) {
            return ['statut' => $this->journaliser($c, $etape, $e->getMessage(), $auteur), 'general' => $e->general];
        }
    }

    /** @param  array<string, mixed>  $compte */
    private function porteLaMarque(Candidature $c, array $compte): bool
    {
        return strcasecmp((string) ($compte['idnumber'] ?? ''), (string) $c->matricule) === 0;
    }

    private function creer(Candidature $c, string $identifiant): void
    {
        $email = (string) $c->email;
        if ($email === '') {
            throw new EchecEcampus('E-mail manquant : eCampus en exige un pour créer le compte.');
        }

        // allowaccountssameemail est désactivé : autant dire quel compte bloque.
        $autre = $this->moodle->comptesParChamp('email', [$email])[0] ?? null;
        if ($autre !== null) {
            throw new EchecEcampus('E-mail déjà utilisé sur eCampus par le compte '.($autre['username'] ?? 'inconnu').'.');
        }

        $this->moodle->creerCompte(array_filter([
            'username' => $identifiant,
            'password' => (string) $c->moodle_password,
            'firstname' => TexteImprimable::nettoyer($c->prenom),
            'lastname' => TexteImprimable::majuscules($c->nom),
            'email' => $email,
            'idnumber' => (string) $c->matricule,
            'phone1' => $this->telephone($c->phone_e164),
            'phone2' => $this->telephone(($c->indicatif2 ?? '').($c->telephone2 ?? '')),
            'department' => (string) config('exports_retenus.moodle.department', 'PSSFP'),
            'city' => TexteImprimable::nettoyer($c->ville_residence),
        ], fn (string $valeur): bool => $valeur !== ''));
    }

    private function cohorte(Candidature $c): string
    {
        return ExportsRetenus::cohorteParDefaut($c->campagne);
    }

    private function telephone(?string $numero): string
    {
        return (string) preg_replace('/\D+/', '', (string) $numero);
    }

    /** @param  array<string, mixed>  $compte */
    private function ecarts(Candidature $c, array $compte): ?string
    {
        $differe = fn (string $champ, string $attendu): bool => isset($compte[$champ])
            && mb_strtolower(trim((string) $compte[$champ])) !== mb_strtolower(trim($attendu));

        $ecarts = array_keys(array_filter([
            'e-mail' => $differe('email', (string) $c->email),
            'nom' => $differe('lastname', TexteImprimable::majuscules($c->nom)),
            'prénom' => $differe('firstname', TexteImprimable::nettoyer($c->prenom)),
        ]));

        return $ecarts === [] ? null : 'Différence avec eCampus : '.implode(', ', $ecarts).'.';
    }

    private function journaliser(Candidature $c, string $statut, ?string $detail, User $auteur): string
    {
        $this->noter($c, $statut, $detail);

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('ecampus_compte')
            ->withProperties(['statut' => $statut])
            ->log('Compte eCampus du dossier traité');

        // Inscription soldée avant la création du compte : l'accès vient de s'ouvrir.
        if (AccesEcampus::compteExiste($c)) {
            $this->notification->siDisponible($c);
        }

        return $statut;
    }

    private function noter(Candidature $c, string $statut, ?string $detail): void
    {
        $c->forceFill([
            'ecampus_statut' => $statut,
            'ecampus_detail' => $detail === null ? null : mb_substr($detail, 0, 255),
            'ecampus_synchronise_at' => now(),
        ])->save();
    }
}
