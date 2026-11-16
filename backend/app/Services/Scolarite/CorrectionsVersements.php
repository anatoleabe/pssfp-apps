<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Candidature;
use App\Models\CorrectionVersement;
use App\Models\DemandeAutorisation;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Correction d'un versement par contre-passation liée (ADR-0015 ; spec module-4-scolarite-espace-financier §7 ; décisions
 * du 16/11/2026). Rien n'est modifié ni supprimé : le versement erroné est annulé avec son reçu, un remplaçant est
 * enregistré (sauf annulation seule), et une pièce COR-AAAA-NNNNN relie le tout. Une seule transaction, sous le verrou
 * des auditeurs concernés pris par id croissant.
 *
 * Deux regards : le service financier demande (`scolarite.versement_corriger`), un titulaire de
 * `scolarite.versement_annuler` décide par le circuit du lot 14c. Le remplaçant et son reçu sont au nom du demandeur,
 * l'annulation au nom du décideur. La confirmation de la scolarité se reporte (R-C8) : d'office à montant et auditeur
 * égaux, sur attestation du décideur sinon. Une correction à montant et auditeur égaux ne change donc pas l'accès.
 *
 * Les exceptions InvalidArgumentException portent un message destiné à l'agent.
 */
final class CorrectionsVersements
{
    public const PERMISSION = 'scolarite.versement_corriger';

    public const PERMISSION_DECIDER = RegistreVersements::PERMISSION_ANNULER;

    /** Champs qu'un remplacement peut changer (R-C4) ; l'inscription désignée ne change pas, sauf avec l'auditeur. */
    public const CHAMPS = ['montant', 'date_versement', 'mode', 'reference', 'payeur', 'auditeur_id'];

    public const LIBELLES_CHAMPS = [
        'montant' => 'Montant', 'date_versement' => 'Date du versement', 'mode' => 'Mode', 'reference' => 'Référence',
        'payeur' => 'Payé par', 'auditeur_id' => 'Auditeur', 'justificatif_path' => 'Bordereau',
    ];

    /** Options de la décision : attestation sur le scan d'origine (R-C8), fermeture d'accès acceptée (R-C7). */
    public const OPTION_ATTESTATION = 'attestation';

    public const OPTION_FERMETURE = 'fermeture';

    public const ATTESTATION = 'J\'atteste, sur le scan du bordereau d\'origine, que le versement corrigé est justifié : montant et auditeur';

    public const FERMETURE = 'Je sais que cette correction ferme l\'accès eCampus d\'un auditeur';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RegistreVersements $registre,
        private readonly RecusVersements $recus,
    ) {}

    public static function peutDemander(?User $u): bool
    {
        return (bool) $u?->can(self::PERMISSION);
    }

    public static function peutDecider(?User $u): bool
    {
        return (bool) $u?->can(self::PERMISSION_DECIDER);
    }

    /** Qui détient tous les droits du geste (en pratique `super_admin`) corrige sans demande : le circuit le refuserait. */
    public static function agitDirectement(?User $u): bool
    {
        return self::peutDemander($u) && self::peutDecider($u)
            && $u->can(RegistreVersements::PERMISSION_SAISIR) && $u->can(RecusVersements::PERMISSION_EMETTRE);
    }

    /** Le versement peut-il être corrigé (R-C5, hors demande en cours) ? null si oui, sinon la raison. */
    public static function raisonRefus(Versement $v): ?string
    {
        if ($v->estAnnule()) {
            return 'Ce versement est déjà annulé.';
        }
        if ($v->reglement_organisme_id !== null) {
            return 'Ce versement fait partie d\'un règlement d\'organisme : il se corrige avec le règlement entier, depuis l\'écran « Prises en charge ».';
        }
        if (count($annees = RegistreVersements::anneesTouchees($v)) > 1) {
            return (new AnnulationMultiAnnees($annees))->getMessage();
        }

        return null;
    }

    /**
     * Paramètres normalisés d'une demande (figés dans `demandes_autorisation.parametres`) : nature, catégorie, champs
     * modifiés (avant, après) et scan éventuel.
     *
     * @param  array<string, mixed>  $saisie
     * @return array{nature: string, categorie: string, champs: array<string, array{avant: mixed, apres: mixed}>, justificatif_path?: string}
     *
     * @throws InvalidArgumentException
     */
    public static function parametres(Versement $v, array $saisie): array
    {
        if (($raison = self::raisonRefus($v)) !== null) {
            throw new InvalidArgumentException($raison);
        }
        $nature = (string) ($saisie['nature'] ?? '');
        if (! array_key_exists($nature, CorrectionVersement::NATURES)) {
            throw new InvalidArgumentException('Choisissez la nature de la correction : remplacement ou annulation.');
        }
        $categorie = (string) ($saisie['categorie'] ?? '');
        if (! array_key_exists($categorie, CorrectionVersement::CATEGORIES)) {
            throw new InvalidArgumentException('Choisissez la catégorie de l\'erreur.');
        }
        if ($nature === CorrectionVersement::ANNULATION) {
            return ['nature' => $nature, 'categorie' => $categorie, 'champs' => []];
        }

        $avant = self::valeurs($v);
        $champs = [];
        foreach (self::CHAMPS as $champ) {
            if (! array_key_exists($champ, $saisie) || blank($saisie[$champ])) {
                continue;
            }
            $apres = self::normaliser($champ, $saisie[$champ]);
            if ($apres !== $avant[$champ]) {
                $champs[$champ] = ['avant' => $avant[$champ], 'apres' => $apres];
            }
        }
        $parametres = ['nature' => $nature, 'categorie' => $categorie, 'champs' => $champs];
        $scan = $saisie['justificatif_path'] ?? null;
        if (filled($scan)) {
            if (! Versement::cheminJustificatifValide((string) $scan) || Versement::pieceDejaJointe((string) $scan)) {
                throw new InvalidArgumentException('Le scan du bordereau est invalide ou déjà joint à une autre pièce : déposez-le à nouveau.');
            }
            $parametres['justificatif_path'] = (string) $scan;
        }
        if ($champs === []) {
            throw new InvalidArgumentException('Le remplacement ne change rien : modifiez au moins un champ, ou demandez une annulation.');
        }
        self::verifierValeurs(self::nouvellesValeurs($v, $parametres));

        return $parametres;
    }

    /**
     * Ce que la décision doit attester ou accepter : l'attestation sur le scan quand un versement confirmé change de
     * montant ou d'auditeur (R-C8), l'acceptation quand la correction ferme un accès (R-C7).
     *
     * @param  array<string, mixed>  $parametres
     * @return array{attestation: bool, fermeture: bool}
     */
    public function exigences(Versement $v, array $parametres): array
    {
        $champs = $parametres['champs'] ?? [];
        $attestation = ($parametres['nature'] ?? null) === CorrectionVersement::REMPLACEMENT && $v->confirmation()->exists()
            && (isset($champs['montant']) || isset($champs['auditeur_id']));
        $fermeture = collect($this->prevoir($v, $parametres))->contains(fn (array $l): bool => $l['acces_avant'] && ! $l['acces_apres']);

        return [self::OPTION_ATTESTATION => $attestation, self::OPTION_FERMETURE => $fermeture];
    }

    /**
     * Effet prévu (R-C7), pour chaque auditeur touché : accès eCampus, versé, à confirmer et reste de l'inscription courante,
     * avant et après. La correction est jouée dans une transaction annulée : le calcul est celui de l'exécution.
     *
     * @param  array<string, mixed>  $parametres
     * @return list<array{matricule: string, acces_avant: bool, acces_apres: bool, verse_avant: int, verse_apres: int, a_confirmer_avant: int, a_confirmer_apres: int, reste_avant: int, reste_apres: int}>
     */
    public function prevoir(Versement $v, array $parametres): array
    {
        $auditeurs = self::auditeursTouches($v, $parametres);
        $avant = array_map(fn (int $id): array => self::photo($id), $auditeurs);
        $apres = [];
        $this->db->beginTransaction();
        try {
            $executant = User::query()->findOrFail($v->saisi_par ?? User::query()->value('id'));
            $this->appliquer($v, $parametres, 'Prévision de la correction', $executant, $executant, null, null, true);
            $apres = array_map(fn (int $id): array => self::photo($id), $auditeurs);
        } catch (InvalidArgumentException) {
            $apres = $avant;
        } finally {
            $this->db->rollBack();
        }

        return array_values(array_map(fn (array $a, array $b): array => [
            'matricule' => $a['matricule'],
            'acces_avant' => $a['acces'], 'acces_apres' => $b['acces'],
            'verse_avant' => $a['verse'], 'verse_apres' => $b['verse'],
            'a_confirmer_avant' => $a['a_confirmer'], 'a_confirmer_apres' => $b['a_confirmer'],
            'reste_avant' => $a['reste'], 'reste_apres' => $b['reste'],
        ], $avant, $apres));
    }

    /**
     * Exécution d'une correction approuvée (circuit 14c) ou directe. `$demandeur` : le service financier, au nom duquel
     * le remplaçant et son reçu sont écrits ; `$executant` : le décideur, au nom duquel l'annulation est passée.
     *
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $options  attestation et fermeture (OPTION_*)
     *
     * @throws InvalidArgumentException
     */
    public function executer(Versement $v, array $parametres, string $motif, User $demandeur, User $executant, array $options = [], ?DemandeAutorisation $demande = null): CorrectionVersement
    {
        abort_unless(self::peutDecider($executant), 403);
        if (! self::peutDemander($demandeur)) {
            throw new InvalidArgumentException('Le demandeur n\'a plus le droit de demander une correction.');
        }
        $remplacement = ($parametres['nature'] ?? null) === CorrectionVersement::REMPLACEMENT;
        if ($remplacement && ! ($demandeur->can(RegistreVersements::PERMISSION_SAISIR) && $demandeur->can(RecusVersements::PERMISSION_EMETTRE))) {
            throw new InvalidArgumentException('Le demandeur n\'a plus le droit d\'enregistrer un versement et d\'émettre son reçu.');
        }
        $this->verifierOptions($v, $parametres, $options);
        $attestant = $remplacement && ($options[self::OPTION_ATTESTATION] ?? false) ? $executant : null;

        $correction = $this->appliquer($v, $parametres, $motif, $demandeur, $executant, $attestant, $demande, false);
        $this->apres($correction, $executant);

        return $correction;
    }

    /**
     * Annulation directe par le responsable de scolarité (§7.7, décision Q11) : catégorie de motif obligatoire et pièce de
     * nature « annulation », sans demande.
     *
     * @throws InvalidArgumentException
     */
    public function annulerDirectement(Versement $v, string $categorie, string $motif, User $responsable, array $options = []): CorrectionVersement
    {
        abort_unless(self::peutDecider($responsable), 403);
        if (($demande = $v->correctionDemandee()) !== null) {
            throw new InvalidArgumentException('Une correction de ce versement est demandée ('.$demande->numero.') : décidez-la depuis l\'écran des demandes.');
        }
        $parametres = self::parametres($v, ['nature' => CorrectionVersement::ANNULATION, 'categorie' => $categorie]);
        $this->verifierOptions($v, $parametres, $options);
        $correction = $this->appliquer($v, $parametres, $motif, $responsable, $responsable, null, null, false);
        $this->apres($correction, $responsable);

        return $correction;
    }

    /** Valeurs actuelles du versement, au format des paramètres. @return array<string, mixed> */
    public static function valeurs(Versement $v): array
    {
        return [
            'montant' => (int) $v->montant,
            'date_versement' => $v->date_versement->toDateString(),
            'mode' => (string) $v->mode,
            'reference' => (string) $v->reference,
            'payeur' => (string) $v->payeur,
            'auditeur_id' => (int) $v->auditeur_id,
        ];
    }

    /**
     * Lignes lisibles de la demande (fiche, message aux décideurs).
     *
     * @param  array<string, mixed>|null  $parametres
     * @return list<string>
     */
    public static function precisions(?array $parametres): array
    {
        if ($parametres === null) {
            return [];
        }
        $lignes = ['Nature : '.(CorrectionVersement::NATURES[$parametres['nature'] ?? ''] ?? '—'),
            'Catégorie : '.(CorrectionVersement::CATEGORIES[$parametres['categorie'] ?? ''] ?? '—')];
        foreach ($parametres['champs'] ?? [] as $champ => $valeurs) {
            $lignes[] = (self::LIBELLES_CHAMPS[$champ] ?? $champ).' : '.self::afficher($champ, $valeurs['avant'] ?? null).' → '.self::afficher($champ, $valeurs['apres'] ?? null);
        }
        if (filled($parametres['justificatif_path'] ?? null)) {
            $lignes[] = 'Nouveau scan du bordereau joint';
        }

        return $lignes;
    }

    public static function afficher(string $champ, mixed $valeur): string
    {
        return match ($champ) {
            'montant' => LibelleSituation::fcfa((int) $valeur),
            'date_versement' => CarbonImmutable::parse((string) $valeur)->format('d/m/Y'),
            'mode' => Versement::libelleMode((string) $valeur),
            'payeur' => Versement::PAYEURS[(string) $valeur] ?? (string) $valeur,
            'auditeur_id' => self::libelleAuditeur((int) $valeur),
            default => (string) $valeur,
        };
    }

    /**
     * Le cœur : annulation, remplaçant, avance, reçu, pièce, report de la confirmation. En simulation, sans reçu ni avis :
     * l'appelant annule la transaction.
     *
     * @param  array<string, mixed>  $parametres
     *
     * @throws InvalidArgumentException
     */
    private function appliquer(Versement $versement, array $parametres, string $motif, User $demandeur, User $executant, ?User $attestant,
        ?DemandeAutorisation $demande, bool $simulation): CorrectionVersement
    {
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new InvalidArgumentException('Le motif de la correction compte au moins 10 caractères.');
        }

        return $this->db->transaction(function () use ($versement, $parametres, $motif, $demandeur, $executant, $attestant, $demande, $simulation): CorrectionVersement {
            $auditeurs = self::auditeursTouches($versement, $parametres);
            // Verrous des auditeurs par id croissant (R8, ordre prévu pour D4) : deux corrections croisées ne s'interbloquent pas.
            Auditeur::query()->whereKey($auditeurs)->orderBy('id')->lockForUpdate()->get(['id']);
            $v = Versement::query()->with('confirmation')->whereKey($versement->id)->lockForUpdate()->firstOrFail();
            if (($raison = self::raisonRefus($v)) !== null) {
                throw new InvalidArgumentException($raison);
            }
            $remplacement = ($parametres['nature'] ?? null) === CorrectionVersement::REMPLACEMENT;
            $nouvelles = $remplacement ? self::nouvellesValeurs($v, $parametres) : null;
            if ($nouvelles !== null) {
                self::verifierValeurs($nouvelles);
            }
            $origine = $v->confirmation;
            $ancienRecu = $v->recus()->whereDoesntHave('annulation')->first();
            [$annee, $sequence] = $this->prochainNumero();
            $numero = sprintf('COR-%d-%05d', $annee, $sequence);
            $categorie = CorrectionVersement::CATEGORIES[$parametres['categorie']] ?? (string) $parametres['categorie'];

            $annulation = $this->registre->annulerPourCorrection($v, 'Correction '.$numero.' ('.mb_strtolower($categorie).') : '.$motif, $executant);

            $remplacant = null;
            if ($nouvelles !== null) {
                $remplacant = $this->registre->enregistrerRemplacant($this->inscriptionCible($v, $nouvelles), [
                    'montant' => $nouvelles['montant'], 'date_versement' => $nouvelles['date_versement'], 'mode' => $nouvelles['mode'],
                    'reference' => $nouvelles['reference'], 'payeur' => $nouvelles['payeur'],
                    'justificatif_path' => $parametres['justificatif_path'] ?? null,
                ], $demandeur);
            }
            foreach ($auditeurs as $auditeurId) {
                $this->registre->imputerAvanceApresCorrection($auditeurId, $executant);
            }
            $recu = null;
            if (! $simulation && $remplacant !== null && $this->recus->raisonRefus($remplacant) === null) {
                $recu = $this->recus->emettre($remplacant, $demandeur, $ancienRecu);
            }

            $report = $remplacant !== null && $origine !== null
                ? (self::changeMontantOuAuditeur($parametres) ? CorrectionVersement::REPORT_ATTESTE : CorrectionVersement::REPORT_OFFICE)
                : null;
            if ($report === CorrectionVersement::REPORT_ATTESTE && $attestant === null && ! $simulation) {
                throw new InvalidArgumentException('Ce versement était confirmé et change de montant ou d\'auditeur : la décision exige l\'attestation sur le scan d\'origine.');
            }
            $correction = (new CorrectionVersement)->forceFill([
                'numero' => $numero, 'annee_numero' => $annee, 'sequence' => $sequence,
                'nature' => $parametres['nature'], 'categorie' => $parametres['categorie'],
                'versement_annule_id' => $v->id, 'versement_remplacant_id' => $remplacant?->id,
                'annulation_recu_id' => $annulation?->id, 'recu_remplacant_id' => $recu?->id,
                'champs' => (object) ($parametres['champs'] ?? []), 'motif' => mb_substr($motif, 0, 2000),
                'demande_id' => $demande?->id, 'demandee_par' => $demandeur->id, 'executee_par' => $executant->id,
                'rectification_immediate' => false, 'confirmation_reportee' => $report,
            ]);
            $correction->save();
            if ($report !== null) {
                ConfirmationsVersements::reporter($remplacant, $origine, $correction,
                    $report === CorrectionVersement::REPORT_ATTESTE ? ($attestant ?? $executant) : null);
            }

            activity('scolarite')->causedBy($executant)->performedOn($v)->event('versement_corrige')
                ->withProperties([
                    'correction' => $numero, 'nature' => $parametres['nature'], 'categorie' => $parametres['categorie'],
                    'champs' => $parametres['champs'] ?? [], 'remplacant' => $remplacant?->uuid, 'recu' => $recu?->numero,
                    'ecart' => $remplacant === null ? -(int) $v->montant : (int) $remplacant->montant - (int) $v->montant,
                    'confirmation_reportee' => $report, 'demande' => $demande?->numero, 'demandeur' => $demandeur->id,
                ])
                ->log($remplacant === null ? 'Versement annulé par correction' : 'Versement corrigé et remplacé');

            return $correction;
        });
    }

    /**
     * Après la validation : l'avis du nouveau reçu est déjà programmé par son émission ; régularisation de l'accès anticipé
     * et notification des accès pour chaque inscription touchée, sans jamais défaire la correction.
     */
    private function apres(CorrectionVersement $correction, User $auteur): void
    {
        $ids = array_filter([$correction->versement_annule_id, $correction->versement_remplacant_id]);
        $inscriptions = Versement::query()->whereKey($ids)->pluck('inscription_annuelle_id')->unique();
        DB::afterCommit(function () use ($inscriptions, $auteur): void {
            foreach (InscriptionAnnuelle::query()->whereKey($inscriptions)->get() as $inscription) {
                try {
                    $this->registre->apresCorrection($inscription, $auteur);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    private function verifierOptions(Versement $v, array $parametres, array $options): void
    {
        $exigences = $this->exigences($v, $parametres);
        if ($exigences[self::OPTION_ATTESTATION] && ! ($options[self::OPTION_ATTESTATION] ?? false)) {
            throw new InvalidArgumentException('Cochez « '.self::ATTESTATION.' » : ce versement était confirmé et change de montant ou d\'auditeur.');
        }
        if ($exigences[self::OPTION_FERMETURE] && ! ($options[self::OPTION_FERMETURE] ?? false)) {
            throw new InvalidArgumentException('Cochez « '.self::FERMETURE.' » : la correction ferme un accès ouvert.');
        }
    }

    /** @param  array<string, mixed>  $parametres */
    private static function changeMontantOuAuditeur(array $parametres): bool
    {
        return isset($parametres['champs']['montant']) || isset($parametres['champs']['auditeur_id']);
    }

    /**
     * Auditeurs dont le compte change : celui du versement, et le nouveau en cas d'erreur d'auditeur ; croissants.
     *
     * @param  array<string, mixed>  $parametres
     * @return list<int>
     */
    private static function auditeursTouches(Versement $v, array $parametres): array
    {
        $ids = [(int) $v->auditeur_id];
        if (isset($parametres['champs']['auditeur_id']['apres'])) {
            $ids[] = (int) $parametres['champs']['auditeur_id']['apres'];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private static function nouvellesValeurs(Versement $v, array $parametres): array
    {
        $valeurs = self::valeurs($v);
        foreach ($parametres['champs'] ?? [] as $champ => $avantApres) {
            if (in_array($champ, self::CHAMPS, true)) {
                $valeurs[$champ] = $avantApres['apres'];
            }
        }

        return $valeurs;
    }

    /**
     * @param  array<string, mixed>  $valeurs
     *
     * @throws InvalidArgumentException
     */
    private static function verifierValeurs(array $valeurs): void
    {
        $montant = $valeurs['montant'];
        if (! is_int($montant) || $montant <= 0 || $montant > RegistreVersements::MONTANT_MAX) {
            throw new InvalidArgumentException('Le montant corrigé doit être un nombre entier de FCFA, supérieur à zéro.');
        }
        if (CarbonImmutable::parse((string) $valeurs['date_versement'], InscriptionAnnuelle::FUSEAU)->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException('La date corrigée ne peut pas être dans le futur.');
        }
        if (! array_key_exists((string) $valeurs['mode'], Versement::MODES)) {
            throw new InvalidArgumentException('Choisissez un mode de paiement de la liste.');
        }
        if (! array_key_exists((string) $valeurs['payeur'], Versement::PAYEURS)) {
            throw new InvalidArgumentException('Choisissez un payeur de la liste.');
        }
        $reference = (string) $valeurs['reference'];
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Indiquez la référence du bordereau (100 caractères au plus).');
        }
        $auditeur = Auditeur::query()->with(['candidature', 'inscriptionCourante'])->find((int) $valeurs['auditeur_id']);
        if ($auditeur === null || $auditeur->candidature === null || $auditeur->candidature->withdrawn_at !== null || $auditeur->inscriptionCourante === null) {
            throw new InvalidArgumentException('L\'auditeur choisi n\'a pas d\'inscription en cours, ou son dossier est retiré : le remplacement est impossible.');
        }
    }

    /** Même auditeur : l'inscription désignée du versement corrigé ; autre auditeur : son inscription courante. */
    private function inscriptionCible(Versement $v, array $nouvelles): InscriptionAnnuelle
    {
        if ((int) $nouvelles['auditeur_id'] === (int) $v->auditeur_id) {
            return InscriptionAnnuelle::query()->findOrFail($v->inscription_annuelle_id);
        }

        return Auditeur::query()->findOrFail((int) $nouvelles['auditeur_id'])->inscriptionCourante()->firstOrFail();
    }

    private static function normaliser(string $champ, mixed $valeur): mixed
    {
        return match ($champ) {
            'montant' => filter_var($valeur, FILTER_VALIDATE_INT) === false ? $valeur : (int) $valeur,
            'auditeur_id' => (int) $valeur,
            'date_versement' => CarbonImmutable::parse((string) $valeur, InscriptionAnnuelle::FUSEAU)->toDateString(),
            'reference' => Versement::normaliserReference((string) $valeur),
            default => (string) $valeur,
        };
    }

    /** @return array{matricule: string, acces: bool, verse: int, a_confirmer: int, reste: int} */
    private static function photo(int $auditeurId): array
    {
        $dossier = Candidature::query()->whereHas('auditeur', fn ($q) => $q->whereKey($auditeurId))->first();
        $inscription = Auditeur::query()->findOrFail($auditeurId)->inscriptionCourante()->first();
        $s = $inscription?->situation();

        return [
            'matricule' => (string) ($dossier?->matricule ?? '—'),
            'acces' => $dossier !== null && AccesEcampus::accesOuvert($dossier),
            'verse' => (int) ($s?->verse ?? 0),
            'a_confirmer' => (int) ($s?->aConfirmer ?? 0),
            'reste' => (int) ($s?->reste ?? 0),
        ];
    }

    private static function libelleAuditeur(int $id): string
    {
        $c = Auditeur::query()->with('candidature:id,nom,prenom,matricule')->find($id)?->candidature;

        return $c === null ? 'auditeur inconnu' : trim(($c->matricule ?? '—').' — '.mb_strtoupper((string) $c->nom).' '.$c->prenom);
    }

    /** @return array{int, int} année civile (Yaoundé) et numéro suivant, sous verrou du compteur : aucun trou. */
    private function prochainNumero(): array
    {
        $annee = (int) now(InscriptionAnnuelle::FUSEAU)->format('Y');
        DB::table('compteurs_corrections')->insertOrIgnore(['annee' => $annee, 'dernier' => 0]);
        $dernier = (int) DB::table('compteurs_corrections')->where('annee', $annee)->lockForUpdate()->value('dernier');
        DB::table('compteurs_corrections')->where('annee', $annee)->update(['dernier' => $dernier + 1]);

        return [$annee, $dernier + 1];
    }
}
