<?php

declare(strict_types=1);

namespace App\Services\Messagerie;

use App\Models\DecisionDemande;
use App\Models\DemandeAutorisation;
use App\Models\EtatPaie;
use App\Models\Message;
use App\Models\User;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Supervision\Supervision;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Lot 14c (D31) : demandes d'autorisation (port de `soumettre_demande`, `decider_demande`, `executer_demande`).
 * Le demandeur ne décide jamais ; une annulation exige le responsable puis le visa du contrôle interne, par deux personnes.
 */
final class Demandes
{
    public const FUSEAU = 'Africa/Douala';

    public const COMMENTAIRE_MIN = 10;

    /** Demandes qu'un compte soumet au plus par heure, et qu'il peut avoir en attente à la fois. */
    public const SOUMISSIONS_PAR_HEURE = 10;

    public const EN_ATTENTE_MAX = 20;

    /** Faute de décideur habilité, la demande est signalée à ces rôles (qui accorderont le droit, ou décideront s'ils l'ont). */
    public const REPLIS = ['admin', 'super_admin'];

    public const ROUTE_FICHE = 'filament.admin.resources.demandes.view';

    public function __construct(
        private readonly TypesDemande $types,
        private readonly Messagerie $messagerie,
    ) {}

    public function soumettre(User $demandeur, string $type, ?int $entiteId, string $motif, ?string $objetLibre = null): DemandeAutorisation
    {
        $t = TypesDemande::type($type);
        $motif = trim($motif);
        if (mb_strlen($motif) < DemandeAutorisation::MOTIF_MIN || mb_strlen($motif) > 2000) {
            throw new InvalidArgumentException('Le motif compte de '.DemandeAutorisation::MOTIF_MIN.' à 2 000 caractères.');
        }
        if (! Messagerie::personnel()->whereKey($demandeur->id)->exists()) {
            throw new InvalidArgumentException('Seul le personnel soumet une demande.');
        }
        if (! TypesDemande::peutViser($demandeur, $type)) {
            throw new InvalidArgumentException('Ce type de demande ne vous est pas ouvert.');
        }
        $cle = 'demandes:'.$demandeur->id;
        if (RateLimiter::tooManyAttempts($cle, self::SOUMISSIONS_PAR_HEURE)
            || DemandeAutorisation::query()->where('demandeur_id', $demandeur->id)->whereIn('statut', DemandeAutorisation::EN_ATTENTE)->count() >= self::EN_ATTENTE_MAX) {
            throw new InvalidArgumentException('Trop de demandes soumises ou en attente : patientez, ou retirez celles qui n\'ont plus lieu d\'être.');
        }
        $objet = $this->objet($type, $entiteId, $objetLibre);
        if (! $t['visa'] && $demandeur->can($this->types->permission($type, $entiteId))) {
            throw new InvalidArgumentException('Vous détenez déjà ce droit : agissez directement, sans demande.');
        }

        $demande = $this->enregistrer($demandeur, $type, $entiteId, $objet, $motif, $t['heures']);
        RateLimiter::hit($cle, 3600);
        $this->prevenir($demandeur, $this->destinataires($demande), $demande, 'Demande '.$demande->numero.' — '.$t['libelle'],
            $this->resume($demande)."\n\nRéponse attendue avant le ".$demande->echeance->setTimezone(self::FUSEAU)->format('d/m/Y à H:i').'.'
            .($t['visa'] ? "\nCette demande exige aussi le visa du contrôle interne." : ''),
            $t['famille'] === TypesDemande::LEVEE_VERROU ? Message::HAUTE : Message::NORMALE);
        $this->journal($demandeur, $demande, 'demande_soumise', 'Demande d\'autorisation soumise', ['motif' => $motif]);

        return $demande;
    }

    /**
     * Décideurs habilités pour l'étape en cours : la permission du type (responsable) ou `supervision.traiter` (visa),
     * jamais le demandeur ni, pour le visa, le responsable qui a approuvé.
     *
     * @return list<int>
     */
    public function decideurs(DemandeAutorisation $demande): array
    {
        $requete = Messagerie::personnel()->whereKeyNot($demande->demandeur_id);
        if ($demande->statut === DemandeAutorisation::VISA_ATTENDU) {
            $responsable = $demande->decisions()->where('qualite', DecisionDemande::RESPONSABLE)->value('decideur_id');
            $requete->permission(Supervision::TRAITER)->whereKeyNot((int) $responsable);
        } elseif ($demande->statut === DemandeAutorisation::SOUMISE) {
            $requete->permission($this->types->permission($demande->type, $demande->entite_id));
        } else {
            return [];
        }

        return $requete->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function peutDecider(User $user, DemandeAutorisation $demande): bool
    {
        return $this->aDecider($user)->whereKey($demande->id)->exists();
    }

    /**
     * Demandes qui attendent la décision de l'utilisateur, en une requête : la permission du type pour une demande soumise
     * (celle du paiement pour un état de paie transmis), `supervision.traiter` pour un visa qu'il n'a pas déjà donné comme responsable.
     */
    public function aDecider(User $user): Builder
    {
        $requete = DemandeAutorisation::query()->whereIn('statut', DemandeAutorisation::EN_ATTENTE)->where('demandeur_id', '!=', $user->id);
        if (! Messagerie::personnel()->whereKey($user->id)->exists()) {
            return $requete->whereKey([]);
        }
        $types = array_keys(array_filter(TypesDemande::TYPES, fn (array $t, string $code): bool => $code !== TypesDemande::REJETER_ETAT_PAIE
            && $user->can($t['permission']), ARRAY_FILTER_USE_BOTH));
        $etats = fn (bool $transmis) => EtatPaie::query()->select('id')->where('statut', $transmis ? '=' : '!=', EtatPaie::TRANSMIS);
        $visa = $user->can(Supervision::TRAITER);

        return $requete->where(function (Builder $q) use ($types, $etats, $visa, $user): void {
            $q->where(function (Builder $s) use ($types, $etats, $user): void {
                $s->where('statut', DemandeAutorisation::SOUMISE)->where(function (Builder $t) use ($types, $etats, $user): void {
                    $t->whereIn('type', $types === [] ? [''] : $types);
                    if ($user->can(PermissionsLmd::PAIE_VERIFIER)) {
                        $t->orWhere(fn (Builder $e) => $e->where('type', TypesDemande::REJETER_ETAT_PAIE)->whereIn('entite_id', $etats(false)));
                    }
                    if ($user->can(PermissionsLmd::PAIE_PAYER)) {
                        $t->orWhere(fn (Builder $e) => $e->where('type', TypesDemande::REJETER_ETAT_PAIE)->whereIn('entite_id', $etats(true)));
                    }
                });
            });
            if ($visa) {
                $q->orWhere(fn (Builder $v) => $v->where('statut', DemandeAutorisation::VISA_ATTENDU)
                    ->whereDoesntHave('decisions', fn (Builder $d) => $d->where('qualite', DecisionDemande::RESPONSABLE)->where('decideur_id', $user->id)));
            }
        });
    }

    /** Ce que l'utilisateur voit : ses demandes, celles qu'il peut décider ou a décidées ; toutes pour le contrôle interne. */
    public function visiblesPar(User $user): Builder
    {
        $requete = DemandeAutorisation::query();
        if (Supervision::peutConsulter($user)) {
            return $requete;
        }

        return $requete->where(fn (Builder $q) => $q->where('demandeur_id', $user->id)
            ->orWhereIn('id', $this->aDecider($user)->select('id'))
            ->orWhereHas('decisions', fn (Builder $d) => $d->where('decideur_id', $user->id)));
    }

    /**
     * Approuve ou rejette. `$statutVu` : le statut affiché quand l'utilisateur a cliqué ; s'il a changé, la décision est refusée.
     * Renvoie le résultat lisible (exécution, visa attendu, rejet, échec).
     */
    public function decider(User $decideur, DemandeAutorisation $demande, bool $approuve, ?string $commentaire = null, ?string $statutVu = null): string
    {
        $commentaire = trim((string) $commentaire);
        if (! $approuve && mb_strlen($commentaire) < self::COMMENTAIRE_MIN) {
            throw new InvalidArgumentException('Un rejet se motive (10 caractères au moins).');
        }
        [$d, $resultat, $suite] = DB::transaction(function () use ($decideur, $demande, $approuve, $commentaire, $statutVu): array {
            $d = DemandeAutorisation::query()->lockForUpdate()->findOrFail($demande->id);
            if (! $d->enAttente()) {
                throw new InvalidArgumentException('Demande déjà traitée ('.(DemandeAutorisation::STATUTS[$d->statut] ?? $d->statut).').');
            }
            if ($statutVu !== null && $statutVu !== $d->statut) {
                throw new InvalidArgumentException('La demande a changé depuis l\'ouverture de la fiche : rechargez-la avant de décider.');
            }
            if ($decideur->id === $d->demandeur_id) {
                throw new InvalidArgumentException('Le demandeur ne décide pas sa propre demande.');
            }
            if (! $this->peutDecider($decideur, $d)) {
                throw new InvalidArgumentException($d->statut === DemandeAutorisation::VISA_ATTENDU
                    ? 'Le visa revient au contrôle interne, et à une autre personne que le responsable.'
                    : 'Vous n\'avez pas le droit requis pour décider cette demande.');
            }
            $qualite = $d->statut === DemandeAutorisation::VISA_ATTENDU ? DecisionDemande::CONTROLE_INTERNE : DecisionDemande::RESPONSABLE;
            DecisionDemande::query()->create([
                'demande_id' => $d->id, 'decideur_id' => $decideur->id, 'qualite' => $qualite,
                'decision' => $approuve ? DecisionDemande::APPROUVE : DecisionDemande::REJETE,
                'commentaire' => $commentaire === '' ? null : mb_substr($commentaire, 0, 1000), 'decide_le' => now(),
            ]);
            $this->journal($decideur, $d, $approuve ? 'demande_approuvee' : 'demande_rejetee',
                $approuve ? 'Demande approuvée' : 'Demande rejetée', ['qualite' => $qualite, 'commentaire' => $commentaire]);

            if (! $approuve) {
                $d->forceFill(['statut' => DemandeAutorisation::REJETEE, 'traitee_le' => now(), 'resultat' => 'Rejetée : '.mb_substr($commentaire, 0, 980)])->save();

                return [$d, 'Demande rejetée', null];
            }
            if ($qualite === DecisionDemande::RESPONSABLE && TypesDemande::type($d->type)['visa']) {
                $d->forceFill(['statut' => DemandeAutorisation::VISA_ATTENDU])->save();

                return [$d, 'Approuvée par le responsable ; visa du contrôle interne attendu', 'visa'];
            }

            return [$d, $this->executer($d), null];
        });

        $this->prevenir($decideur, [$d->demandeur_id], $d, 'Demande '.$d->numero.' — '.$resultat,
            $this->resume($d)."\n\n".$resultat.($commentaire !== '' ? "\nCommentaire : ".$commentaire : ''), Message::NORMALE, Message::DECISION);
        if ($suite === 'visa') {
            $this->prevenir($decideur, $this->destinataires($d), $d, 'Demande '.$d->numero.' — visa du contrôle interne requis',
                'Le responsable a approuvé cette demande ; elle attend votre visa.'."\n\n".$this->resume($d), Message::HAUTE);
        }

        return $resultat;
    }

    public function retirer(User $demandeur, DemandeAutorisation $demande): void
    {
        DB::transaction(function () use ($demandeur, $demande): void {
            $d = DemandeAutorisation::query()->lockForUpdate()->findOrFail($demande->id);
            if ($d->demandeur_id !== $demandeur->id || $d->statut !== DemandeAutorisation::SOUMISE || $d->decisions()->exists()) {
                throw new InvalidArgumentException('Seul le demandeur retire sa demande, et seulement avant toute décision.');
            }
            $d->forceFill(['statut' => DemandeAutorisation::RETIREE, 'traitee_le' => now(), 'resultat' => 'Retirée par le demandeur'])->save();
            $this->journal($demandeur, $d, 'demande_retiree', 'Demande retirée', []);
        });
    }

    public static function lien(DemandeAutorisation $demande): string
    {
        return route(self::ROUTE_FICHE, ['record' => $demande->uuid]);
    }

    /**
     * Exécution dans un point de sauvegarde, sous l'identité du responsable qui a approuvé, sur l'objet tel qu'il était à la
     * soumission (empreinte). Un échec garde les décisions et passe la demande en « échec d'exécution ».
     */
    private function executer(DemandeAutorisation $d): string
    {
        $responsable = User::query()->findOrFail($d->decisions()->where('qualite', DecisionDemande::RESPONSABLE)->value('decideur_id'));
        $motif = 'Demande '.$d->numero.' : '.$d->motif;
        try {
            $resultat = DB::transaction(function () use ($d, $responsable, $motif): string {
                if ($d->empreinte !== null && $this->types->empreinte($d->type, $d->entite_id) !== $d->empreinte) {
                    throw new InvalidArgumentException('l\'objet a été modifié depuis la demande ; soumettez-en une nouvelle sur sa version actuelle.');
                }

                return $this->types->executer($d->type, $d->entite_id, $responsable, $motif);
            });
            $d->forceFill(['statut' => DemandeAutorisation::EXECUTEE, 'traitee_le' => now(), 'resultat' => mb_substr($resultat, 0, 1000)])->save();
            $this->journal($responsable, $d, 'demande_executee', 'Demande exécutée', ['resultat' => $resultat]);

            return $resultat;
        } catch (InvalidArgumentException|HttpExceptionInterface|ModelNotFoundException $e) {
            $raison = match (true) {
                $e instanceof HttpExceptionInterface => 'droit retiré au responsable entre-temps',
                $e instanceof ModelNotFoundException => 'l\'objet visé n\'existe plus',
                default => $e->getMessage(),
            };
            $resultat = Str::limit('Échec de l\'exécution : '.$raison, 1000, '');
            $d->forceFill(['statut' => DemandeAutorisation::ECHEC, 'traitee_le' => now(), 'resultat' => $resultat])->save();
            $this->journal($responsable, $d, 'demande_echec', 'Échec de l\'exécution d\'une demande', ['raison' => $raison]);

            return $resultat;
        }
    }

    private function enregistrer(User $demandeur, string $type, ?int $entiteId, string $objet, string $motif, int $heures): DemandeAutorisation
    {
        for ($essai = 1; ; $essai++) {
            try {
                return DB::transaction(function () use ($demandeur, $type, $entiteId, $objet, $motif, $heures): DemandeAutorisation {
                    $annee = (int) CarbonImmutable::now(self::FUSEAU)->format('Y');
                    $sequence = (int) DemandeAutorisation::query()->where('annee', $annee)->max('sequence') + 1;
                    $d = new DemandeAutorisation;
                    $d->forceFill([
                        'numero' => sprintf('DM-%d-%05d', $annee, $sequence), 'annee' => $annee, 'sequence' => $sequence,
                        'type' => $type, 'demandeur_id' => $demandeur->id, 'entite_id' => $entiteId, 'objet' => $objet, 'motif' => $motif,
                        'empreinte' => $this->types->empreinte($type, $entiteId),
                        'statut' => DemandeAutorisation::SOUMISE, 'soumise_le' => now(), 'echeance' => now()->addHours($heures),
                    ])->save();

                    return $d;
                });
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), 'demandes_autorisation_en_cours')) {
                    throw new InvalidArgumentException('Une demande de ce type est déjà en cours pour cet objet.');
                }
                if ($essai >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function objet(string $type, ?int $entiteId, ?string $objetLibre): string
    {
        if (! TypesDemande::aUnObjet($type)) {
            $objetLibre = trim((string) $objetLibre);
            if ($objetLibre === '' || mb_strlen($objetLibre) > 300) {
                throw new InvalidArgumentException('Décrivez l\'objet de la demande (300 caractères au plus).');
            }

            return $objetLibre;
        }
        $libelle = $entiteId === null ? null : $this->types->libelleObjet($type, $entiteId);
        if ($libelle === null) {
            throw new InvalidArgumentException('Cet objet ne se prête pas, ou plus, à ce type de demande.');
        }
        if (DemandeAutorisation::query()->where('type', $type)->where('entite_id', $entiteId)->whereIn('statut', DemandeAutorisation::EN_ATTENTE)->exists()) {
            throw new InvalidArgumentException('Une demande de ce type est déjà en cours pour cet objet.');
        }

        return $libelle;
    }

    /**
     * Décideurs de l'étape en cours ; faute de décideur habilité, l'administrateur, puis le super administrateur, sont saisis.
     *
     * @return list<int>
     */
    private function destinataires(DemandeAutorisation $d): array
    {
        $ids = $this->decideurs($d);
        foreach (self::REPLIS as $role) {
            if ($ids !== []) {
                break;
            }
            $ids = Messagerie::personnel()->whereHas('roles', fn (Builder $query) => $query->where('name', $role))
                ->whereKeyNot($d->demandeur_id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $ids;
    }

    /** @param  list<int>  $destinataires */
    private function prevenir(User $expediteur, array $destinataires, DemandeAutorisation $d, string $objet, string $corps, string $priorite,
        string $categorie = Message::DEMANDE): void
    {
        if ($destinataires === []) {
            return;
        }
        try {
            $this->messagerie->envoyer($expediteur, $destinataires, Str::limit($objet, Message::OBJET_MAX, ''),
                $corps."\n\nVoir la demande : ".self::lien($d), $categorie, $priorite, ['email']);
        } catch (Throwable $e) {
            // Le message informe ; il ne doit jamais défaire la demande ni la décision.
            report($e);
        }
    }

    private function resume(DemandeAutorisation $d): string
    {
        return 'Type : '.TypesDemande::type($d->type)['libelle']."\nObjet : ".$d->objet."\nDemandeur : ".($d->demandeur?->name ?? '—')."\nMotif : ".$d->motif;
    }

    /** @param  array<string, mixed>  $proprietes */
    private function journal(User $auteur, DemandeAutorisation $d, string $evenement, string $libelle, array $proprietes): void
    {
        activity(Journaux::MESSAGERIE)->causedBy($auteur)->performedOn($d)->event($evenement)
            ->withProperties(['demande' => $d->numero, 'type' => $d->type, ...$proprietes])->log($libelle);
    }
}
