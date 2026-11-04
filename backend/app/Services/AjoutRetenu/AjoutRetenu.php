<?php

declare(strict_types=1);

namespace App\Services\AjoutRetenu;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Pays;
use App\Models\User;
use App\Services\CandidatUserService;
use App\Services\ResultatsSelection\DetecteurDoublons;
use App\Support\PhoneMasker;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

/**
 * Crée depuis l'admin le dossier d'un retenu qui n'est pas passé par le portail.
 *
 * Le dossier naît retenu et sans soumission en ligne (`submitted_at` nul) : il
 * n'entre donc ni dans les dépôts soumis, ni dans le PV du jury, mais suit le
 * parcours ordinaire d'un retenu (matricule, lettres, exports). Aucun message
 * ne part : les identifiants et la lettre sont remis comme aux autres.
 *
 * Un compte candidat est rattaché, car la connexion par matricule en exige un.
 * Son code est inconnu de tous : l'auditeur se connecte avec ses identifiants
 * FOAD, ou reçoit un code par l'action « Nouveau code » ou « Code oublié ».
 */
final class AjoutRetenu
{
    public const PERMISSION = 'candidature.ajouter_retenu';

    public const EVENEMENT = 'retenu_ajoute';

    public const EVENEMENT_TELEPHONE = 'retenu_telephone_corrige';

    public const STATUTS_CAMPAGNE = ['open', 'closed'];

    private const CHAMPS = [
        'civilite', 'genre', 'nom', 'prenom', 'epouse', 'date_naissance', 'lieu_naissance', 'statut_matrimonial',
        'nationalite', 'pays_origine', 'pays_residence', 'region', 'departement', 'adresse', 'ville_residence',
        'email', 'specialite', 'type_etude', 'premiere_langue', 'diplome_obtenu', 'annee_diplome',
        'diplome_requis', 'annee_diplome_requis', 'domaine_diplome_requis', 'specialite_diplome_requis',
        'institut_diplome_requis', 'statut_actuel', 'fonction_actuelle', 'employeur', 'adresse_employeur',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CandidatUserService $comptes,
        private readonly DetecteurDoublons $doublons,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  champs du dossier, `campagne_id`, `phone_e164` et `motif`
     *
     * @throws InvalidArgumentException message destiné à l'agent : doublon ou téléphone inutilisable
     */
    public function ajouter(array $donnees, User $auteur): Candidature
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        $telephone = $this->telephone((string) ($donnees['phone_e164'] ?? ''));
        $motif = trim((string) ($donnees['motif'] ?? ''));
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de l\'ajout.');
        }
        $campagne = CampagneCandidature::query()->whereKey($donnees['campagne_id'] ?? null)->whereIn('status', self::STATUTS_CAMPAGNE)->first()
            ?? throw new InvalidArgumentException('Choisissez une campagne ouverte ou close : une campagne en préparation ou archivée n\'a pas de promotion à compléter.');
        $naissance = CarbonImmutable::parse((string) $donnees['date_naissance'])->toDateString();
        $champs = array_merge(
            array_intersect_key($donnees, array_flip(self::CHAMPS)),
            [
                'nom' => trim((string) $donnees['nom']),
                'prenom' => trim((string) $donnees['prenom']),
                'date_naissance' => $naissance,
            ],
        );

        try {
            return $this->db->transaction(function () use ($campagne, $champs, $telephone, $naissance, $motif, $auteur): Candidature {
                // Verrou sur la campagne : un double clic ne crée pas deux dossiers.
                CampagneCandidature::query()->whereKey($campagne->id)->lockForUpdate()->first();

                $this->refuserTelephonePris($campagne, $telephone);
                $compte = $this->compteExistant($campagne, $telephone, $naissance);
                $this->refuserMemePersonne($campagne, $champs);

                $pays = $this->paysDuTelephone($telephone, $champs['nationalite'] ?? null);
                $compte ??= $this->comptes->createCandidat($telephone, Str::random(40), [
                    'prenom' => $champs['prenom'],
                    'nom' => $champs['nom'],
                    'phone_country' => $pays,
                    'date_naissance' => $naissance,
                ]);

                $candidature = new Candidature($champs + [
                    'campagne_id' => $campagne->id,
                    'user_id' => $compte->id,
                    'phone_e164' => $telephone,
                    'phone_country' => $pays,
                    'statut' => Candidature::STATUT_ACCEPTE,
                    'decided_at' => now(),
                    'frais_paye' => false,
                ]);
                $candidature->forceFill(['ajoute_par' => $auteur->id])->save();

                activity('candidatures')->causedBy($auteur)->performedOn($candidature)
                    ->event(self::EVENEMENT)
                    ->withProperties(['motif' => $motif, 'numero' => $candidature->numero_dossier])
                    ->log('Retenu ajouté depuis l\'admin');

                return $candidature;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce téléphone vient d\'être enregistré par ailleurs. Vérifiez la liste, puis réessayez.');
        }
    }

    /**
     * Corrige le téléphone d'un retenu ajouté depuis l'admin. Le titulaire du
     * numéro erroné perd l'accès : plus de compte à ce numéro, code et sessions
     * annulés. Le matricule et le mot de passe FOAD ne changent jamais : s'ils
     * lui ont déjà été envoyés, l'agent en est averti (identifiantsDejaEnvoyes).
     *
     * @throws InvalidArgumentException message destiné à l'agent : numéro inutilisable
     */
    public function corrigerTelephone(Candidature $dossier, string $nouveau, string $motif, User $auteur): Candidature
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        if ($dossier->ajoute_par === null) {
            throw new InvalidArgumentException('Ce dossier a été déposé sur le portail : son téléphone appartient au candidat.');
        }
        $telephone = $this->telephone($nouveau);
        if ($telephone === $dossier->phone_e164) {
            throw new InvalidArgumentException('Ce téléphone est déjà celui du dossier.');
        }
        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de la correction.');
        }

        try {
            return $this->db->transaction(function () use ($dossier, $telephone, $motif, $auteur): Candidature {
                $campagne = CampagneCandidature::query()->whereKey($dossier->campagne_id)->lockForUpdate()->firstOrFail();

                $this->refuserTelephonePris($campagne, $telephone);
                $naissance = (string) $dossier->date_naissance?->toDateString();
                $cible = $this->compteExistant($campagne, $telephone, $naissance);
                $pays = $this->paysDuTelephone($telephone, $dossier->nationalite);
                $ancien = $dossier->user()->with('roles')->first();
                // Ce compte n'existe que pour ce dossier : son titulaire n'a rien d'autre à y perdre.
                $dedie = $ancien !== null
                    && $ancien->roles->pluck('name')->all() === ['candidat']
                    && Candidature::withTrashed()->where('user_id', $ancien->id)->whereKeyNot($dossier->id)->doesntExist();

                if ($cible === null && $dedie) {
                    $ancien->tokens()->delete();
                    $ancien->forceFill(['phone_e164' => $telephone, 'phone_country' => $pays, 'password' => Str::random(40)])->save();
                    $compte = $ancien;
                } else {
                    $compte = $cible ?? $this->comptes->createCandidat($telephone, Str::random(40), [
                        'prenom' => (string) $dossier->prenom,
                        'nom' => (string) $dossier->nom,
                        'phone_country' => $pays,
                        'date_naissance' => $naissance,
                    ]);
                }

                $ancienTelephone = (string) $dossier->phone_e164;
                $dossier->forceFill(['user_id' => $compte->id, 'phone_e164' => $telephone, 'phone_country' => $pays])->save();

                if ($dedie && ! $compte->is($ancien)) {
                    $ancien->tokens()->delete();
                    $ancien->delete();
                }

                activity('candidatures')->causedBy($auteur)->performedOn($dossier)
                    ->event(self::EVENEMENT_TELEPHONE)
                    ->withProperties([
                        'motif' => $motif,
                        'ancien' => PhoneMasker::mask($ancienTelephone),
                        'nouveau' => PhoneMasker::mask($telephone),
                    ])
                    ->log('Téléphone d\'un retenu ajouté corrigé');

                return $dossier->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce téléphone vient d\'être enregistré par ailleurs. Vérifiez la liste, puis réessayez.');
        }
    }

    /** Le matricule et le mot de passe FOAD sont-ils déjà partis vers les anciennes coordonnées ? */
    public function identifiantsDejaEnvoyes(Candidature $dossier): bool
    {
        return Activity::query()
            ->where('subject_type', $dossier->getMorphClass())
            ->where('subject_id', $dossier->getKey())
            ->where('event', 'moodle_identifiants_envoyes')
            ->exists();
    }

    private function telephone(string $saisie): string
    {
        $telephone = (string) preg_replace('/[\s.\-()]/', '', $saisie);
        if (preg_match('/^\+[1-9]\d{6,14}$/', $telephone) !== 1) {
            throw new InvalidArgumentException('Le téléphone doit être au format international, par exemple +237699000000.');
        }

        return $telephone;
    }

    private function refuserTelephonePris(CampagneCandidature $campagne, string $telephone): void
    {
        // withTrashed : l'index unique (campagne, téléphone) couvre aussi les dossiers supprimés.
        $existant = Candidature::withTrashed()->forCampagne($campagne->id)->where('phone_e164', $telephone)->first();
        if ($existant !== null) {
            throw new InvalidArgumentException(
                "Ce téléphone figure déjà sur le dossier {$existant->numero_dossier}".($existant->trashed() ? ' (supprimé)' : '').' de cette campagne.'
            );
        }
    }

    private function compteExistant(CampagneCandidature $campagne, string $telephone, string $naissance): ?User
    {
        $compte = User::query()->with('roles')->where('phone_e164', $telephone)->first();
        if ($compte === null) {
            return null;
        }
        // Même règle que NouveauCodeCandidat::compteCandidat : un agent ne devient jamais auditeur.
        if ($compte->roles->pluck('name')->all() !== ['candidat']) {
            throw new InvalidArgumentException('Ce téléphone appartient à un compte du personnel : il ne peut pas servir à un auditeur.');
        }
        // Rattacher le dossier à la mauvaise personne lui ouvrirait un espace qui n'est pas le sien.
        if ($compte->date_naissance?->toDateString() !== $naissance) {
            throw new InvalidArgumentException('Ce téléphone appartient à un compte candidat dont la date de naissance diffère ou manque : vérifiez le numéro.');
        }
        $dossier = Candidature::withTrashed()->forCampagne($campagne->id)->where('user_id', $compte->id)->first();
        if ($dossier !== null) {
            throw new InvalidArgumentException("Le compte de ce téléphone porte déjà le dossier {$dossier->numero_dossier} de cette campagne.");
        }

        return $compte;
    }

    /** @param  array<string, mixed>  $champs */
    private function refuserMemePersonne(CampagneCandidature $campagne, array $champs): void
    {
        $nouveau = (new Candidature)->forceFill([
            'nom' => $champs['nom'],
            'prenom' => $champs['prenom'],
            'date_naissance' => $champs['date_naissance'],
        ]);
        $identite = $this->doublons->identite($nouveau);

        // Un dossier retiré ne compte pas : l'administration peut reprendre cette
        // personne, son rang et son matricule éventuel restant réservés.
        $existant = Candidature::query()->forCampagne($campagne->id)
            ->whereNull('withdrawn_at')
            ->whereDate('date_naissance', $champs['date_naissance'])
            ->get(['id', 'numero_dossier', 'nom', 'prenom', 'date_naissance'])
            ->first(fn (Candidature $c): bool => $this->doublons->identite($c) === $identite);
        if ($existant !== null) {
            throw new InvalidArgumentException("Cette personne a déjà le dossier {$existant->numero_dossier} dans cette campagne (même nom, même date de naissance).");
        }
    }

    /**
     * Pays de l'indicatif. Plusieurs pays partagent parfois un indicatif (+1) :
     * la nationalité départage, sinon le préfixe le plus long l'emporte.
     */
    private function paysDuTelephone(string $telephone, ?string $nationalite): string
    {
        $candidats = Pays::query()->whereNotNull('indicatif')->get(['code_iso', 'indicatif'])
            ->filter(fn (Pays $p): bool => str_starts_with($telephone, (string) $p->indicatif));

        return ($candidats->firstWhere('code_iso', $nationalite)
            ?? $candidats->sortByDesc(fn (Pays $p): int => strlen((string) $p->indicatif))->first())?->code_iso
            ?? 'CM';
    }
}
