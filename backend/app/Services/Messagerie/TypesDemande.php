<?php

declare(strict_types=1);

namespace App\Services\Messagerie;

use App\Models\DeblocageAcces;
use App\Models\DemandeAutorisation;
use App\Models\DocumentDelivre;
use App\Models\EtapeSaisie;
use App\Models\EtatPaie;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\PointageEnseignant;
use App\Models\QuotaHoraire;
use App\Models\RecuVersement;
use App\Models\User;
use App\Models\Versement;
use App\Services\Audit\VerrouillageComptes;
use App\Services\Lmd\DocumentsOfficiels;
use App\Services\Lmd\EtatsPaie;
use App\Services\Lmd\PermissionsLmd;
use App\Services\Lmd\PointagesEnseignant;
use App\Services\Lmd\Programmations;
use App\Services\Lmd\SaisieNotes;
use App\Services\Lmd\ValidationMaquette;
use App\Services\Scolarite\ConfirmationsVersements;
use App\Services\Scolarite\CorrectionsVersements;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\DemandeMoratoire;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\RecusVersements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lot 14c (D31) : catalogue des demandes d'autorisation (port de `type_demande` et `executer_demande`).
 * Chaque type : l'objet visé, la permission de ses décideurs, le visa éventuel, et l'opération existante qu'il déclenche.
 */
final class TypesDemande
{
    public const ROUVRIR_ETAPE = 'rouvrir_etape_saisie';

    public const VALIDER_QUOTA = 'valider_quota';

    public const VALIDER_MAQUETTE = 'valider_maquette';

    public const VALIDER_AUTRE = 'valider_autre';

    public const ANNULER_POINTAGE = 'annuler_pointage';

    public const REVOQUER_DOCUMENT = 'revoquer_document';

    public const REJETER_ETAT_PAIE = 'rejeter_etat_paie';

    public const DEVERROUILLER_COMPTE = 'deverrouiller_compte';

    /** Lot A3 (scolarité) : l'agent demande au responsable un moratoire sur les droits d'inscription. */
    public const ACCORDER_MORATOIRE = 'accorder_moratoire';

    /** Espace Finances (ADR-0015) : le service financier demande la correction ou l'annulation d'un versement. */
    public const CORRIGER_VERSEMENT = DemandeAutorisation::TYPE_CORRIGER_VERSEMENT;

    /** Règle du 25/11/2026 : le service financier demande l'annulation d'un reçu, toujours suivie de sa réémission. */
    public const REEMETTRE_RECU = DemandeAutorisation::TYPE_REEMETTRE_RECU;

    public const LEVEE_VERROU = 'levee_verrou';

    public const VALIDATION = 'validation';

    public const ANNULATION = 'annulation';

    public const FAMILLES = [self::LEVEE_VERROU => 'Levée de verrou', self::VALIDATION => 'Validation', self::ANNULATION => 'Annulation'];

    /** @var array<string, array{libelle: string, famille: string, permission: string, visa: bool, heures: int}> */
    public const TYPES = [
        self::ROUVRIR_ETAPE => ['libelle' => 'Levée du verrou d\'une étape de saisie des notes', 'famille' => self::LEVEE_VERROU, 'permission' => PermissionsLmd::NOTES_ROUVRIR, 'visa' => false, 'heures' => 24],
        self::VALIDER_QUOTA => ['libelle' => 'Validation d\'un quota horaire', 'famille' => self::VALIDATION, 'permission' => PermissionsLmd::PROGRAMMATION_VALIDER, 'visa' => false, 'heures' => 48],
        self::VALIDER_MAQUETTE => ['libelle' => 'Validation d\'une maquette', 'famille' => self::VALIDATION, 'permission' => PermissionsLmd::VALIDER_MAQUETTE, 'visa' => false, 'heures' => 72],
        self::VALIDER_AUTRE => ['libelle' => 'Demande de validation (autre objet)', 'famille' => self::VALIDATION, 'permission' => 'settings.manage', 'visa' => false, 'heures' => 72],
        self::ANNULER_POINTAGE => ['libelle' => 'Annulation d\'un émargement d\'enseignant validé, non payé', 'famille' => self::ANNULATION, 'permission' => PermissionsLmd::POINTAGE, 'visa' => true, 'heures' => 48],
        self::REVOQUER_DOCUMENT => ['libelle' => 'Révocation d\'un document délivré', 'famille' => self::ANNULATION, 'permission' => PermissionsLmd::DOCUMENTS_REVOQUER, 'visa' => true, 'heures' => 48],
        self::REJETER_ETAT_PAIE => ['libelle' => 'Rejet d\'un état de paie vérifié, visé ou transmis', 'famille' => self::ANNULATION, 'permission' => PermissionsLmd::PAIE_VERIFIER, 'visa' => true, 'heures' => 48],
        self::DEVERROUILLER_COMPTE => ['libelle' => 'Déverrouillage d\'un compte', 'famille' => self::LEVEE_VERROU, 'permission' => VerrouillageComptes::PERMISSION_DEVERROUILLER, 'visa' => false, 'heures' => 8],
        self::ACCORDER_MORATOIRE => ['libelle' => 'Accord d\'un moratoire sur les droits d\'inscription', 'famille' => self::LEVEE_VERROU, 'permission' => DeblocagesAcces::PERMISSION_EXCEPTIONNEL, 'visa' => false, 'heures' => 48],
        // Second regard d'un administrateur, sans visa du contrôle interne (décision Q1 du 16/11/2026 ; le responsable de
        // scolarité ne décide plus depuis la règle du 25/11/2026).
        self::CORRIGER_VERSEMENT => ['libelle' => 'Correction ou annulation d\'un versement', 'famille' => self::ANNULATION, 'permission' => CorrectionsVersements::PERMISSION_DECIDER, 'visa' => false, 'heures' => 24],
        self::REEMETTRE_RECU => ['libelle' => 'Annulation et réémission d\'un reçu', 'famille' => self::ANNULATION, 'permission' => CorrectionsVersements::PERMISSION_DECIDER, 'visa' => false, 'heures' => 24],
    ];

    /**
     * Qui peut viser un objet de ce type, donc voir la liste des objets et soumettre la demande : l'une de ces permissions
     * (liste vide = tout le personnel). Ce sont les permissions de consultation des écrans concernés.
     *
     * @var array<string, list<string>>
     */
    public const VOIR = [
        self::ROUVRIR_ETAPE => [PermissionsLmd::CONSULTER, PermissionsLmd::NOTES_SAISIR],
        self::VALIDER_QUOTA => [PermissionsLmd::CONSULTER, PermissionsLmd::PROGRAMMATION, PermissionsLmd::PROGRAMMATION_VALIDER],
        self::VALIDER_MAQUETTE => [PermissionsLmd::CONSULTER],
        self::VALIDER_AUTRE => [],
        self::ANNULER_POINTAGE => [PermissionsLmd::POINTAGE],
        self::REVOQUER_DOCUMENT => [PermissionsLmd::CONSULTER],
        self::REJETER_ETAT_PAIE => [PermissionsLmd::PAIE_PREPARER, PermissionsLmd::PAIE_VERIFIER, PermissionsLmd::PAIE_VISER, PermissionsLmd::PAIE_PAYER],
        // Un collègue demande pour le compte verrouillé, qui ne peut plus se connecter.
        self::DEVERROUILLER_COMPTE => [],
        // L'agent de scolarité, qui accorde un virement annoncé mais pas un moratoire.
        self::ACCORDER_MORATOIRE => [DeblocagesAcces::PERMISSION],
        // Le service financier, qui encaisse mais n'annule pas.
        self::CORRIGER_VERSEMENT => [CorrectionsVersements::PERMISSION],
        self::REEMETTRE_RECU => [RecusVersements::PERMISSION_EMETTRE],
    ];

    /** @var array<string, class-string<Model>> */
    public const CLASSES = [
        self::ROUVRIR_ETAPE => EtapeSaisie::class, self::VALIDER_QUOTA => QuotaHoraire::class, self::VALIDER_MAQUETTE => Maquette::class,
        self::ANNULER_POINTAGE => PointageEnseignant::class, self::REVOQUER_DOCUMENT => DocumentDelivre::class, self::REJETER_ETAT_PAIE => EtatPaie::class,
        self::DEVERROUILLER_COMPTE => User::class, self::ACCORDER_MORATOIRE => InscriptionAnnuelle::class,
        self::CORRIGER_VERSEMENT => Versement::class, self::REEMETTRE_RECU => RecuVersement::class,
    ];

    /** Types soumis depuis l'écran de leur objet, qui saisit leurs paramètres : absents de l'écran général « Nouvelle demande ». */
    public const DEPUIS_LEUR_ECRAN = [self::ACCORDER_MORATOIRE, self::CORRIGER_VERSEMENT, self::REEMETTRE_RECU];

    public const ETATS_PAIE_REJETABLES = [EtatPaie::VERIFIE, EtatPaie::VISE, EtatPaie::TRANSMIS];

    /** Objets proposés au plus dans la liste de choix. */
    public const OBJETS_MAX = 50;

    /** @return array{libelle: string, famille: string, permission: string, visa: bool, heures: int} */
    public static function type(string $code): array
    {
        return self::TYPES[$code] ?? throw new InvalidArgumentException('Type de demande inconnu.');
    }

    /** @return array<string, string> code => libellé */
    public static function libelles(): array
    {
        return array_map(fn (array $t): string => $t['libelle'], self::TYPES);
    }

    public static function peutViser(User $user, string $code): bool
    {
        $permissions = self::VOIR[$code] ?? null;

        return $permissions !== null && ($permissions === [] || collect($permissions)->contains(fn (string $p): bool => $user->can($p)));
    }

    /** @return array<string, string> types ouverts à l'utilisateur dans l'écran général « Nouvelle demande » */
    public static function libellesPour(User $user): array
    {
        return array_filter(self::libelles(), fn (string $code): bool => ! in_array($code, self::DEPUIS_LEUR_ECRAN, true)
            && self::peutViser($user, $code), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Paramètres que le type exige en plus de son objet, contrôlés et normalisés ; null pour un type qui n'en a pas.
     *
     * @param  array<string, mixed>  $saisie
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException
     */
    public function parametres(string $code, ?int $entiteId, array $saisie): ?array
    {
        if ($code === self::ACCORDER_MORATOIRE) {
            return DemandeMoratoire::parametres(InscriptionAnnuelle::query()->findOrFail($entiteId), $saisie);
        }
        if ($code === self::CORRIGER_VERSEMENT) {
            return CorrectionsVersements::parametres(Versement::query()->findOrFail($entiteId), $saisie);
        }
        if (array_filter($saisie, fn (mixed $v): bool => filled($v)) !== []) {
            throw new InvalidArgumentException('Ce type de demande ne prend pas de paramètres.');
        }

        return null;
    }

    /**
     * Ce que la demande précise en plus de son objet, ligne à ligne (message aux décideurs, fiche de la demande).
     *
     * @param  array<string, mixed>|null  $parametres
     * @return list<string>
     */
    public static function precisions(string $code, ?array $parametres): array
    {
        return match ($code) {
            self::ACCORDER_MORATOIRE => DemandeMoratoire::precisions($parametres),
            self::CORRIGER_VERSEMENT => CorrectionsVersements::precisions($parametres),
            default => [],
        };
    }

    /** Pièce jointe à la demande, seulement si son chemin est bien celui d'un justificatif (jamais un chemin forgé). */
    public static function piece(string $code, ?array $parametres): ?string
    {
        $chemin = in_array($code, [self::ACCORDER_MORATOIRE, self::CORRIGER_VERSEMENT], true) ? ($parametres['justificatif_path'] ?? null) : null;
        if (! is_string($chemin)) {
            return null;
        }

        return ($code === self::CORRIGER_VERSEMENT ? Versement::cheminJustificatifValide($chemin) : DeblocageAcces::cheminPieceValide($chemin)) ? $chemin : null;
    }

    /** Types qu'on soumet même en détenant le droit : la correction attestée exige toujours une seconde personne (ADR-0015). */
    public static function toujoursParDemande(string $code): bool
    {
        return $code === self::CORRIGER_VERSEMENT;
    }

    public static function aUnObjet(string $code): bool
    {
        return $code !== self::VALIDER_AUTRE;
    }

    /** Permission des décideurs : pour un état de paie transmis, celle du paiement (comme EtatsPaie::peutRejeter). */
    public function permission(string $code, ?int $entiteId): string
    {
        $permission = self::type($code)['permission'];
        if ($code === self::REJETER_ETAT_PAIE && $entiteId !== null
            && EtatPaie::query()->whereKey($entiteId)->value('statut') === EtatPaie::TRANSMIS) {
            return PermissionsLmd::PAIE_PAYER;
        }

        return $permission;
    }

    /**
     * Objets qui se prêtent au type, filtrés sur leur libellé.
     *
     * @return array<int, string> identifiant => libellé
     */
    public function objets(string $code, ?string $terme = null): array
    {
        if (! self::aUnObjet($code)) {
            return [];
        }
        $terme = trim((string) $terme);
        $requete = $this->eligibles($code);
        if ($code === self::REVOQUER_DOCUMENT && $terme !== '') {
            $requete->where('numero', 'ilike', '%'.addcslashes($terme, '%_\\').'%');
        }
        $objets = [];
        foreach ($requete->latest('id')->limit(500)->get() as $m) {
            $libelle = $this->libelle($code, $m);
            if ($terme === '' || Str::contains(Str::lower(Str::ascii($libelle)), Str::lower(Str::ascii($terme)))) {
                $objets[$m->getKey()] = $libelle;
            }
            if (count($objets) >= self::OBJETS_MAX) {
                break;
            }
        }

        return $objets;
    }

    /** Libellé de l'objet s'il se prête encore au type, sinon null. */
    public function libelleObjet(string $code, int $id): ?string
    {
        $m = $this->eligibles($code)->whereKey($id)->first();

        return $m === null ? null : $this->libelle($code, $m);
    }

    /**
     * Empreinte du contenu de l'objet (et, pour une maquette, de ses UE et ECUE) : ce que le responsable a examiné.
     * L'exécution la recompare ; tout changement depuis la soumission fait échouer la demande.
     */
    public function empreinte(string $code, ?int $id): ?string
    {
        // Moratoire : les contrôles d'accorder(), refaits à l'exécution, remplacent l'empreinte (spec A3, §10).
        if (! self::aUnObjet($code) || $id === null || $code === self::ACCORDER_MORATOIRE) {
            return null;
        }
        $m = (self::CLASSES[$code])::query()->find($id);
        if ($m === null) {
            return null;
        }
        // Versement : la ligne, sa confirmation et son reçu en vigueur (R-C9) ; tout changement fait échouer l'exécution.
        if ($m instanceof Versement) {
            return hash('sha256', (string) json_encode(Arr::except($m->getAttributes(), ['updated_at']) + [
                'confirmation' => $m->confirmation()->value('id'),
                'recu' => $m->recus()->whereDoesntHave('annulation')->value('id'),
            ]));
        }
        // Reçu : la ligne et son annulation éventuelle (une annulation passée entre-temps fait échouer l'exécution).
        if ($m instanceof RecuVersement) {
            return hash('sha256', (string) json_encode(Arr::except($m->getAttributes(), ['updated_at']) + [
                'annulation' => $m->annulation()->value('id'),
            ]));
        }
        $sans = fn (Model $x): array => Arr::except($x->getAttributes(), ['updated_at']);
        // Un compte change sans cesse (connexions, échecs comptés pendant le verrouillage) : seul son verrouillage compte ici.
        $donnees = $m instanceof User ? ['id' => $m->id, 'verrouille_le' => $m->getAttributes()['verrouille_le'] ?? null] : $sans($m);
        if ($m instanceof Maquette) {
            $donnees['ues'] = $m->ues()->with('ecues')->orderBy('id')->get()
                ->map(fn (Model $ue): array => $sans($ue) + ['ecues' => $ue->ecues->sortBy('id')->map($sans)->values()->all()])->all();
        }

        return hash('sha256', (string) json_encode($donnees));
    }

    /**
     * Exécute l'opération demandée, sous l'identité du responsable qui l'a approuvée. Renvoie le résultat lisible.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function executer(string $code, ?int $id, User $auteur, string $motif, array $parametres = [], ?DemandeAutorisation $demande = null, array $options = []): string
    {
        $objet = fn (string $classe): Model => $classe::query()->findOrFail($id);

        return match ($code) {
            self::ROUVRIR_ETAPE => $this->faire(fn () => app(SaisieNotes::class)->rouvrir($objet(EtapeSaisie::class), $auteur, $motif), 'Étape de saisie rouverte'),
            self::VALIDER_QUOTA => $this->faire(fn () => app(Programmations::class)->validerQuota($objet(QuotaHoraire::class), $auteur), 'Quota horaire validé'),
            self::VALIDER_MAQUETTE => $this->faire(fn () => app(ValidationMaquette::class)->valider($objet(Maquette::class), $auteur), 'Maquette validée'),
            self::VALIDER_AUTRE => 'Validation accordée : l\'action reste au demandeur',
            self::ANNULER_POINTAGE => $this->faire(fn () => app(PointagesEnseignant::class)->annulerValide($objet(PointageEnseignant::class), $motif, $auteur), 'Émargement annulé'),
            self::REVOQUER_DOCUMENT => $this->faire(function () use ($id, $motif, $auteur): void {
                $document = DocumentDelivre::query()->lockForUpdate()->findOrFail($id);
                if ($document->estRevoque()) {
                    throw new InvalidArgumentException('Document déjà révoqué entre-temps.');
                }
                app(DocumentsOfficiels::class)->revoquer($document, $motif, $auteur);
            }, 'Document révoqué (la vérification publique affiche « révoqué »)'),
            self::REJETER_ETAT_PAIE => $this->faire(fn () => app(EtatsPaie::class)->rejeter($objet(EtatPaie::class), $motif, $auteur), 'État de paie rejeté ; émargements libérés'),
            self::DEVERROUILLER_COMPTE => $this->faire(fn () => app(VerrouillageComptes::class)->deverrouiller($objet(User::class), $auteur, $motif), 'Compte déverrouillé'),
            self::ACCORDER_MORATOIRE => DemandeMoratoire::executer($objet(InscriptionAnnuelle::class), $parametres, $auteur, $motif),
            self::CORRIGER_VERSEMENT => $this->corriger($objet(Versement::class), $parametres, $auteur, $demande, $options),
            self::REEMETTRE_RECU => $this->reemettre($objet(RecuVersement::class), $auteur, $demande),
            default => throw new InvalidArgumentException('Type de demande inconnu.'),
        };
    }

    /**
     * Correction approuvée (ADR-0015) : l'annulation au nom du décideur, le remplaçant et son reçu au nom du demandeur.
     *
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $options
     */
    private function corriger(Model $versement, array $parametres, User $decideur, ?DemandeAutorisation $demande, array $options): string
    {
        if ($demande === null || ! $versement instanceof Versement) {
            throw new InvalidArgumentException('Une correction s\'exécute depuis sa demande.');
        }
        $correction = app(CorrectionsVersements::class)->executer($versement, $parametres, $demande->motif,
            User::query()->findOrFail($demande->demandeur_id), $decideur, $options, $demande);

        return 'Correction '.$correction->numero.' exécutée'.($correction->recuRemplacant !== null ? ' : reçu '.$correction->recuRemplacant->numero.' émis' : '');
    }

    /**
     * Réémission approuvée (règle du 25/11/2026) : l'annulation au nom du décideur, le nouveau reçu au nom du demandeur.
     */
    private function reemettre(Model $recu, User $decideur, ?DemandeAutorisation $demande): string
    {
        if ($demande === null || ! $recu instanceof RecuVersement) {
            throw new InvalidArgumentException('Une réémission s\'exécute depuis sa demande.');
        }
        $nouveau = app(RecusVersements::class)->reemettre($recu, $demande->motif, $decideur, User::query()->findOrFail($demande->demandeur_id));

        return 'Reçu '.$recu->numero.' annulé ; reçu '.$nouveau->numero.' émis';
    }

    /**
     * Avant d'enregistrer l'approbation d'une correction : les attestations exigées par la décision (R-C7, R-C8). Un oubli
     * refuse la décision au lieu de faire échouer l'exécution.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    public function verifierApprobation(DemandeAutorisation $d, array $options, ?User $decideur = null): void
    {
        if ($d->type !== self::CORRIGER_VERSEMENT || $d->entite_id === null) {
            return;
        }
        $versement = Versement::query()->find($d->entite_id);
        if ($versement === null || CorrectionsVersements::raisonRefus($versement) !== null) {
            return;
        }
        $exigences = app(CorrectionsVersements::class)->exigences($versement, $d->parametres ?? []);
        if ($exigences[CorrectionsVersements::OPTION_ATTESTATION] && ! ConfirmationsVersements::peutConfirmer($decideur)) {
            throw new InvalidArgumentException(CorrectionsVersements::REFUS_ATTESTANT);
        }
        if ($exigences[CorrectionsVersements::OPTION_ATTESTATION] && ! ($options[CorrectionsVersements::OPTION_ATTESTATION] ?? false)) {
            throw new InvalidArgumentException('Cochez « '.CorrectionsVersements::ATTESTATION.' » pour approuver.');
        }
        if ($exigences[CorrectionsVersements::OPTION_FERMETURE] && ! ($options[CorrectionsVersements::OPTION_FERMETURE] ?? false)) {
            throw new InvalidArgumentException('Cochez « '.CorrectionsVersements::FERMETURE.' » pour approuver.');
        }
    }

    private function faire(callable $operation, string $resultat): string
    {
        $operation();

        return $resultat;
    }

    private function eligibles(string $code): Builder
    {
        return match ($code) {
            self::ROUVRIR_ETAPE => EtapeSaisie::query()->where('statut', EtapeSaisie::VERROUILLEE)->with('ecue:id,code,intitule'),
            self::VALIDER_QUOTA => QuotaHoraire::query()->where('statut', QuotaHoraire::PROPOSE)->with(['ecue:id,code,intitule', 'periode:id,libelle']),
            self::VALIDER_MAQUETTE => Maquette::query()->where('statut', Maquette::BROUILLON)->with('parcours'),
            self::ANNULER_POINTAGE => PointageEnseignant::query()->where('statut', PointageEnseignant::VALIDE)->whereNull('ligne_paie_id')
                ->with(['seance.ecue:id,code,intitule', 'enseignant:id,name']),
            // Un PV de délibération ne se révoque que par une rectification (lot 16a).
            self::REVOQUER_DOCUMENT => DocumentDelivre::query()->whereNull('revoque_at')->where('type', '<>', DocumentDelivre::PV),
            self::REJETER_ETAT_PAIE => EtatPaie::query()->whereIn('statut', self::ETATS_PAIE_REJETABLES),
            self::DEVERROUILLER_COMPTE => VerrouillageComptes::verrouilles(),
            self::ACCORDER_MORATOIRE => InscriptionAnnuelle::query()->whereHas('auditeur.candidature')->with('auditeur.candidature'),
            self::CORRIGER_VERSEMENT => Versement::query()->whereNull('annule_at')->whereNull('reglement_organisme_id')->with('auditeur.candidature:id,matricule'),
            self::REEMETTRE_RECU => RecuVersement::query()->whereDoesntHave('annulation')->whereHas('versement', fn (Builder $q) => $q->whereNull('annule_at')),
            default => throw new InvalidArgumentException('Ce type de demande ne vise pas d\'objet.'),
        };
    }

    private function libelle(string $code, Model $m): string
    {
        $ecue = fn ($e): string => trim(($e?->code ?? '').' '.($e?->intitule ?? ''));

        return Str::limit(match ($code) {
            self::ROUVRIR_ETAPE => $ecue($m->ecue).' — '.(EtapeSaisie::ETAPES[$m->etape] ?? $m->etape).', '.(EtapeSaisie::SESSIONS[$m->session] ?? $m->session),
            self::VALIDER_QUOTA => $ecue($m->ecue).' — '.($m->periode?->libelle ?? 'période inconnue').' — '.str_replace('.', ',', (string) round($m->total(), 2)).' h',
            self::VALIDER_MAQUETTE => $m->libelle(),
            self::ANNULER_POINTAGE => 'Séance du '.($m->seance?->date_seance?->format('d/m/Y') ?? '?').' — '.$ecue($m->seance?->ecue).' — '.($m->enseignant?->name ?? ''),
            self::REVOQUER_DOCUMENT => $m->numero.' — '.(DocumentDelivre::TYPES[$m->type] ?? $m->type),
            self::REJETER_ETAT_PAIE => $m->numero.' — '.(EtatPaie::STATUTS[$m->statut] ?? $m->statut),
            self::DEVERROUILLER_COMPTE => $m->name.' — verrouillé le '.$m->verrouille_le?->setTimezone('Africa/Douala')->format('d/m/Y à H:i'),
            self::ACCORDER_MORATOIRE => DemandeMoratoire::libelle($m),
            self::CORRIGER_VERSEMENT => 'Versement '.$m->reference.' de '.LibelleSituation::fcfa((int) $m->montant).' du '.$m->date_versement->format('d/m/Y')
                .' — '.($m->auditeur?->candidature?->matricule ?? 'sans matricule'),
            self::REEMETTRE_RECU => 'Reçu '.$m->numero.' de '.LibelleSituation::fcfa((int) $m->montant).' — '.($m->matricule ?? 'sans matricule'),
            default => (string) $m->getKey(),
        }, 290);
    }
}
