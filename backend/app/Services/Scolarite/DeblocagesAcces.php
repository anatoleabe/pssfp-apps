<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\EngagementPriseEnCharge;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Accès anticipé (spec module 4, lot A1) : ouvre l'accès à eCampus avant le
 * paiement des droits d'inscription, le prolonge, le révoque, et le régularise
 * dès que les droits sont soldés. Seul point d'écriture de `deblocages_acces`.
 *
 * Chaque geste revérifie sa permission (même si un écran l'a déjà fait), se fait
 * sous verrou et finit au journal `scolarite`. Les InvalidArgumentException
 * portent un message destiné à l'agent. Un montant n'est jamais tenu pour payé.
 * L'octroi et la régularisation lisent la situation de l'argent : ils prennent
 * d'abord le verrou de l'auditeur, comme toute écriture d'argent (ADR-0013, R8).
 */
final class DeblocagesAcces
{
    /** Virement annoncé, prolongation limitée. */
    public const PERMISSION = 'scolarite.deblocage';

    /** Moratoire ou dérogation, toute prolongation, révocation. */
    public const PERMISSION_EXCEPTIONNEL = 'scolarite.deblocage_exceptionnel';

    public const MOTIF_MIN = 10;

    public const MOTIF_MAX = 1000;

    public const JOURS_DEFAUT_VIREMENT = 15;

    /** Échéance au plus loin, en jours à compter d'aujourd'hui. */
    public const JOURS_MAX = [
        DeblocageAcces::VIREMENT_ANNONCE => 60,
        DeblocageAcces::MORATOIRE => 180,
    ];

    /** L'agent prolonge un virement annoncé une seule fois, de 30 jours au plus. */
    public const PROLONGATION_AGENT_JOURS = 30;

    public const PROLONGATIONS_AGENT = 1;

    public const MOTIF_REGULARISATION = 'Droits d\'inscription soldés';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly NotificationAcces $notification,
        private readonly ImputationsCompte $imputations,
    ) {}

    /**
     * Cas qu'un compte peut accorder. La prise en charge passe toujours par un
     * engagement (lot A2) : `rattacher`, jamais `accorder`.
     *
     * @return array<string, string>
     */
    public static function typesPermis(?User $u): array
    {
        $types = [];
        if ($u?->can(self::PERMISSION) || $u?->can(self::PERMISSION_EXCEPTIONNEL)) {
            $types[DeblocageAcces::VIREMENT_ANNONCE] = DeblocageAcces::TYPES[DeblocageAcces::VIREMENT_ANNONCE];
            $types[DeblocageAcces::PRISE_EN_CHARGE] = DeblocageAcces::TYPES[DeblocageAcces::PRISE_EN_CHARGE];
        }
        if ($u?->can(self::PERMISSION_EXCEPTIONNEL)) {
            $types[DeblocageAcces::MORATOIRE] = DeblocageAcces::TYPES[DeblocageAcces::MORATOIRE];
        }

        return $types;
    }

    public static function echeanceParDefaut(string $type): ?CarbonImmutable
    {
        return $type === DeblocageAcces::VIREMENT_ANNONCE ? InscriptionAnnuelle::aujourdhui()->addDays(self::JOURS_DEFAUT_VIREMENT) : null;
    }

    public static function echeanceMax(string $type): ?CarbonImmutable
    {
        return isset(self::JOURS_MAX[$type]) ? InscriptionAnnuelle::aujourdhui()->addDays(self::JOURS_MAX[$type]) : null;
    }

    /**
     * Une révocation est le garde-fou du responsable (les codes remis ne se reprennent
     * pas) : après elle, lui seul accorde un nouveau déblocage sur cette inscription.
     */
    public static function peutAccorderApresRevocation(?InscriptionAnnuelle $inscription, ?User $u): bool
    {
        return $inscription === null
            || (bool) $u?->can(self::PERMISSION_EXCEPTIONNEL)
            || ! DeblocageAcces::query()->where('inscription_annuelle_id', $inscription->id)->where('statut', DeblocageAcces::REVOQUE)->exists();
    }

    public static function peutProlonger(DeblocageAcces $d, ?User $u): bool
    {
        // L'échéance d'un auditeur couvert est celle de son engagement : on prolonge l'engagement (lot A2).
        if (! $d->estEnCours() || $d->engagement_id !== null) {
            return false;
        }
        if ($u?->can(self::PERMISSION_EXCEPTIONNEL)) {
            return true;
        }

        return (bool) $u?->can(self::PERMISSION)
            && $d->type === DeblocageAcces::VIREMENT_ANNONCE
            && $d->prolongations < self::PROLONGATIONS_AGENT;
    }

    /** Nouvelle échéance au plus loin : 180 jours pour le responsable, l'échéance + 30 jours pour l'agent. */
    public static function echeanceMaxProlongation(DeblocageAcces $d, ?User $u): CarbonImmutable
    {
        return $u?->can(self::PERMISSION_EXCEPTIONNEL)
            ? InscriptionAnnuelle::aujourdhui()->addDays(self::JOURS_MAX[DeblocageAcces::MORATOIRE])
            : self::jour($d->echeance->toDateString())->addDays(self::PROLONGATION_AGENT_JOURS);
    }

    /**
     * Moratoire demandé par l'agent au responsable (lot A3) : les contrôles d'accorder(), hors droits de l'auteur,
     * sans rien écrire ni verrouiller. accorder() les refait à l'exécution de la demande, sous verrou.
     *
     * @return array{echeance: string, justificatif_path: ?string}
     *
     * @throws InvalidArgumentException
     */
    public static function controlerMoratoire(InscriptionAnnuelle $inscription, mixed $echeance, mixed $piece): array
    {
        $echeance = self::echeance(DeblocageAcces::MORATOIRE, $echeance);
        $piece = self::piece(DeblocageAcces::MORATOIRE, $piece);
        self::dossierOuvert($inscription);
        $courante = InscriptionAnnuelle::query()->with('bareme')->findOrFail($inscription->id);
        if ($courante->situation()->inscriptionSoldee()) {
            throw new InvalidArgumentException('Les droits d\'inscription sont déjà soldés : l\'accès est ouvert sans déblocage.');
        }
        if (DeblocageAcces::query()->enCours()->where('inscription_annuelle_id', $inscription->id)->exists()) {
            throw new InvalidArgumentException(self::messageDejaEnCours());
        }

        return ['echeance' => $echeance->toDateString(), 'justificatif_path' => $piece];
    }

    /**
     * @param  array{type?: mixed, motif?: mixed, echeance?: mixed, justificatif_path?: mixed}  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function accorder(InscriptionAnnuelle $inscription, array $saisie, User $auteur): ResultatDeblocage
    {
        $type = (string) ($saisie['type'] ?? '');
        if (! array_key_exists($type, DeblocageAcces::TYPES)) {
            throw new InvalidArgumentException('Choisissez un cas de la liste.');
        }
        if ($type === DeblocageAcces::PRISE_EN_CHARGE) {
            throw new InvalidArgumentException('Une prise en charge passe par un engagement de l\'organisme : rattachez l\'auditeur à son engagement (écran « Prises en charge »).');
        }
        abort_unless(array_key_exists($type, self::typesPermis($auteur)), 403);

        $motif = self::motif($saisie['motif'] ?? null);
        $echeance = self::echeance($type, $saisie['echeance'] ?? null);
        $piece = self::piece($type, $saisie['justificatif_path'] ?? null);
        $dossier = self::dossierOuvert($inscription);

        try {
            // Verrou de l'auditeur, puis de l'inscription (ordre R8) : la situation lue ne bouge plus.
            $deblocage = $this->imputations->sousVerrou((int) $inscription->auditeur_id, function () use ($inscription, $type, $motif, $echeance, $piece, $auteur): DeblocageAcces {
                $verrouillee = InscriptionAnnuelle::query()->with('bareme')->whereKey($inscription->id)->lockForUpdate()->firstOrFail();
                if ($verrouillee->situation()->inscriptionSoldee()) {
                    throw new InvalidArgumentException('Les droits d\'inscription sont déjà soldés : l\'accès est ouvert sans déblocage.');
                }
                if (DeblocageAcces::query()->enCours()->where('inscription_annuelle_id', $inscription->id)->exists()) {
                    throw new InvalidArgumentException(self::messageDejaEnCours());
                }
                if (! self::peutAccorderApresRevocation($verrouillee, $auteur)) {
                    throw new InvalidArgumentException('Le responsable de scolarité a révoqué un accès anticipé sur cette inscription : lui seul peut en accorder un nouveau.');
                }

                $d = new DeblocageAcces;
                $d->forceFill([
                    'inscription_annuelle_id' => $inscription->id,
                    'type' => $type,
                    'motif' => $motif,
                    'justificatif_path' => $piece,
                    'echeance' => $echeance->toDateString(),
                    'statut' => DeblocageAcces::EN_COURS,
                    'accorde_par' => $auteur->id,
                ])->save();

                activity('scolarite')->causedBy($auteur)->performedOn($d)
                    ->event('deblocage_accorde')
                    ->withProperties(['type' => $type, 'echeance' => $echeance->toDateString(), 'motif' => $motif, 'piece' => $piece !== null, 'inscription' => $verrouillee->uuid])
                    ->log('Accès anticipé accordé ('.mb_strtolower(DeblocageAcces::TYPES[$type]).'), à régulariser avant le '.$echeance->format('d/m/Y'));

                return $d;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux agents sur la même fiche, au même moment : l'index tranche.
            throw new InvalidArgumentException(self::messageDejaEnCours());
        }

        // Comme après un paiement : SMS et e-mail avec le lien vers la lettre d'accès.
        return new ResultatDeblocage($deblocage, $this->notification->siDisponible($dossier, $auteur));
    }

    /** @throws InvalidArgumentException */
    public function prolonger(DeblocageAcces $deblocage, mixed $echeance, mixed $motif, User $auteur): void
    {
        $exceptionnel = $auteur->can(self::PERMISSION_EXCEPTIONNEL);
        abort_unless($exceptionnel || $auteur->can(self::PERMISSION), 403);
        $motif = self::motif($motif);
        if (blank($echeance)) {
            throw new InvalidArgumentException('Indiquez la nouvelle échéance.');
        }
        $nouvelle = self::jour($echeance);

        $this->db->transaction(function () use ($deblocage, $nouvelle, $motif, $auteur, $exceptionnel): void {
            $d = DeblocageAcces::query()->whereKey($deblocage->id)->lockForUpdate()->firstOrFail();
            if (! $d->estEnCours()) {
                throw new InvalidArgumentException('Ce déblocage est clos : il ne se prolonge plus. Accordez-en un nouveau si besoin.');
            }
            if ($d->engagement_id !== null) {
                throw new InvalidArgumentException(self::messageEngagement());
            }
            if (! $exceptionnel) {
                // L'agent ne prolonge que le virement annoncé ; le reste revient au responsable.
                abort_unless($d->type === DeblocageAcces::VIREMENT_ANNONCE, 403);
                if ($d->prolongations >= self::PROLONGATIONS_AGENT) {
                    throw new InvalidArgumentException('L\'agent ne prolonge un virement annoncé qu\'une seule fois : adressez-vous au responsable de scolarité.');
                }
            }
            $ancienne = $d->echeance->toDateString();
            if ($nouvelle->toDateString() <= $ancienne) {
                throw new InvalidArgumentException('La nouvelle échéance doit tomber après l\'échéance actuelle, le '.$d->echeance->format('d/m/Y').'.');
            }
            if ($nouvelle->toDateString() <= InscriptionAnnuelle::aujourdhui()->toDateString()) {
                throw new InvalidArgumentException('L\'échéance doit être postérieure à aujourd\'hui.');
            }
            $max = self::echeanceMaxProlongation($d, $auteur);
            if ($nouvelle->toDateString() > $max->toDateString()) {
                throw new InvalidArgumentException($exceptionnel
                    ? 'Échéance trop lointaine : au plus '.self::JOURS_MAX[DeblocageAcces::MORATOIRE].' jours, soit le '.$max->format('d/m/Y').'.'
                    : 'L\'agent prolonge de '.self::PROLONGATION_AGENT_JOURS.' jours au plus, soit jusqu\'au '.$max->format('d/m/Y').'.');
            }

            $d->forceFill(['echeance' => $nouvelle->toDateString(), 'prolongations' => $d->prolongations + 1])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($d)
                ->event('deblocage_prolonge')
                ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $nouvelle->toDateString(), 'motif' => $motif])
                ->log('Accès anticipé prolongé jusqu\'au '.$nouvelle->format('d/m/Y'));
        });
    }

    /**
     * Rattache l'inscription courante d'un auditeur à un engagement de prise en charge (lot A2,
     * §9.5) : un déblocage `prise_en_charge` lié à l'engagement, à son échéance, sans pièce (la
     * lettre est sur l'engagement). Mêmes refus qu'un déblocage (A1), plus : autre année, autre
     * engagement, échéance dépassée. Verrous : l'engagement en partage, puis l'auditeur (R8),
     * l'ordre du règlement réparti.
     *
     * @throws InvalidArgumentException
     */
    public function rattacher(EngagementPriseEnCharge $engagement, InscriptionAnnuelle $inscription, User $auteur): ResultatDeblocage
    {
        abort_unless(isset(self::typesPermis($auteur)[DeblocageAcces::PRISE_EN_CHARGE]), 403);
        $dossier = self::dossierOuvert($inscription);
        if ($inscription->annee_academique !== $engagement->annee_academique) {
            throw new InvalidArgumentException('L\'engagement couvre l\'année '.$engagement->annee_academique.' ; l\'inscription en cours de l\'auditeur est de '.$inscription->annee_academique.'.');
        }

        try {
            $deblocage = $this->db->transaction(function () use ($engagement, $inscription, $auteur): DeblocageAcces {
                $e = EngagementPriseEnCharge::query()->whereKey($engagement->id)->sharedLock()->firstOrFail();
                if ($e->estEchu()) {
                    throw new InvalidArgumentException('L\'échéance de l\'engagement, le '.$e->echeance->format('d/m/Y').', est dépassée : faites-la prolonger par le responsable de scolarité avant de rattacher des auditeurs.');
                }

                return $this->imputations->sousVerrou((int) $inscription->auditeur_id, function () use ($e, $inscription, $auteur): DeblocageAcces {
                    $verrouillee = InscriptionAnnuelle::query()->with('bareme')->whereKey($inscription->id)->lockForUpdate()->firstOrFail();
                    if ($verrouillee->situation()->inscriptionSoldee()) {
                        throw new InvalidArgumentException('Les droits d\'inscription sont déjà soldés : l\'accès est ouvert sans prise en charge. Le règlement de l\'organisme pour cet auditeur s\'enregistre sur sa fiche (payé par : son employeur).');
                    }
                    if (DeblocageAcces::query()->enCours()->where('inscription_annuelle_id', $inscription->id)->exists()) {
                        throw new InvalidArgumentException(self::messageDejaEnCours());
                    }
                    if (! self::peutAccorderApresRevocation($verrouillee, $auteur)) {
                        throw new InvalidArgumentException('Le responsable de scolarité a révoqué un accès anticipé sur cette inscription : lui seul peut en accorder un nouveau.');
                    }
                    $autre = DeblocageAcces::query()->with('engagement:id,organisme')->where('inscription_annuelle_id', $inscription->id)
                        ->whereNotNull('engagement_id')->where('engagement_id', '<>', $e->id)->where('statut', '<>', DeblocageAcces::REVOQUE)->first();
                    if ($autre !== null) {
                        throw new InvalidArgumentException('Cette inscription est déjà couverte par un autre engagement de prise en charge ('.$autre->engagement?->organisme.') : faites d\'abord révoquer ce lien.');
                    }

                    $motif = mb_substr('Prise en charge : '.$e->organisme.', lettre '.$e->libelleLettre(), 0, self::MOTIF_MAX);
                    $d = new DeblocageAcces;
                    $d->forceFill([
                        'inscription_annuelle_id' => $inscription->id,
                        'type' => DeblocageAcces::PRISE_EN_CHARGE,
                        'engagement_id' => $e->id,
                        'motif' => $motif,
                        'justificatif_path' => null,
                        'echeance' => $e->echeance->toDateString(),
                        'statut' => DeblocageAcces::EN_COURS,
                        'accorde_par' => $auteur->id,
                    ])->save();

                    activity('scolarite')->causedBy($auteur)->performedOn($d)
                        ->event('deblocage_accorde')
                        ->withProperties(['type' => DeblocageAcces::PRISE_EN_CHARGE, 'echeance' => $e->echeance->toDateString(), 'motif' => $motif,
                            'piece' => false, 'inscription' => $verrouillee->uuid, 'engagement' => $e->uuid])
                        ->log('Accès anticipé accordé (prise en charge), à régulariser avant le '.$e->echeance->format('d/m/Y'));

                    return $d;
                });
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException(self::messageDejaEnCours());
        }

        return new ResultatDeblocage($deblocage, $this->notification->siDisponible($dossier, $auteur));
    }

    /**
     * Reporte l'échéance des déblocages en cours d'un engagement prolongé (lot A2, §9.4). Appelé par
     * EngagementsPriseEnCharge, dans sa transaction, sous le verrou de l'engagement.
     *
     * @return int déblocages prolongés
     */
    public function prolongerEngagement(EngagementPriseEnCharge $engagement, CarbonImmutable $nouvelle, string $motif, User $auteur): int
    {
        abort_unless($auteur->can(self::PERMISSION_EXCEPTIONNEL), 403);
        $deblocages = DeblocageAcces::query()->enCours()->where('engagement_id', $engagement->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($deblocages as $d) {
            $ancienne = $d->echeance->toDateString();
            $d->forceFill(['echeance' => $nouvelle->toDateString(), 'prolongations' => $d->prolongations + 1])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($d)
                ->event('deblocage_prolonge')
                ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $nouvelle->toDateString(), 'motif' => $motif, 'engagement' => $engagement->uuid])
                ->log('Accès anticipé prolongé jusqu\'au '.$nouvelle->format('d/m/Y').' (engagement prolongé)');
        }

        return $deblocages->count();
    }

    /**
     * L'accès redevient « bloqué » si les droits ne sont pas soldés. Les codes
     * déjà remis restent valables sur eCampus : on ne peut pas les reprendre.
     *
     * @throws InvalidArgumentException
     */
    public function revoquer(DeblocageAcces $deblocage, mixed $motif, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION_EXCEPTIONNEL), 403);
        $motif = self::motif($motif);

        $this->db->transaction(function () use ($deblocage, $motif, $auteur): void {
            $d = DeblocageAcces::query()->whereKey($deblocage->id)->lockForUpdate()->firstOrFail();
            if (! $d->estEnCours()) {
                throw new InvalidArgumentException('Ce déblocage est déjà clos.');
            }
            $d->forceFill(['statut' => DeblocageAcces::REVOQUE, 'clos_le' => now(), 'clos_par' => $auteur->id, 'motif_cloture' => $motif])->save();
            activity('scolarite')->causedBy($auteur)->performedOn($d)
                ->event('deblocage_revoque')
                ->withProperties(['type' => $d->type, 'motif' => $motif])
                ->log('Accès anticipé révoqué');
        });
    }

    /**
     * Clôt le déblocage en cours dès que les droits d'inscription sont soldés :
     * après un versement ou une remise, et en rattrapage par la tâche quotidienne.
     */
    public function regulariser(InscriptionAnnuelle $inscription, ?User $auteur = null): bool
    {
        // Sous le verrou de l'auditeur : une annulation simultanée ne peut pas défaire le solde lu.
        return $this->imputations->sousVerrou((int) $inscription->auditeur_id, function () use ($inscription, $auteur): bool {
            $d = DeblocageAcces::query()->enCours()->where('inscription_annuelle_id', $inscription->id)->first();
            $courante = InscriptionAnnuelle::query()->with('bareme')->find($inscription->id);
            if ($d === null || $courante === null || ! $courante->situation()->inscriptionSoldee()) {
                return false;
            }
            // Mise à jour conditionnelle : deux saisies simultanées ne journalisent qu'une régularisation.
            $modifies = DeblocageAcces::query()->whereKey($d->id)->enCours()->update([
                'statut' => DeblocageAcces::REGULARISE, 'clos_le' => now(), 'clos_par' => $auteur?->id,
                'motif_cloture' => self::MOTIF_REGULARISATION, 'updated_at' => now(),
            ]);
            if ($modifies === 0) {
                return false;
            }
            activity('scolarite')->causedBy($auteur)->performedOn($d)
                ->event('deblocage_regularise')
                ->withProperties(['type' => $d->type, 'echeance' => $d->echeance->toDateString()])
                ->log('Accès anticipé régularisé : droits d\'inscription soldés');

            return true;
        });
    }

    private static function motif(mixed $motif): string
    {
        $motif = trim((string) $motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new InvalidArgumentException('Indiquez le motif (au moins '.self::MOTIF_MIN.' caractères).');
        }

        return mb_substr($motif, 0, self::MOTIF_MAX);
    }

    private static function echeance(string $type, mixed $saisie): CarbonImmutable
    {
        if (blank($saisie)) {
            return self::echeanceParDefaut($type)
                ?? throw new InvalidArgumentException('Indiquez l\'échéance du délai accordé.');
        }
        $date = self::jour($saisie);
        if ($date->toDateString() <= InscriptionAnnuelle::aujourdhui()->toDateString()) {
            throw new InvalidArgumentException('L\'échéance doit être postérieure à aujourd\'hui.');
        }
        $max = self::echeanceMax($type);
        if ($max !== null && $date->toDateString() > $max->toDateString()) {
            throw new InvalidArgumentException('Échéance trop lointaine : au plus '.self::JOURS_MAX[$type].' jours pour ce cas, soit le '.$max->format('d/m/Y').'.');
        }

        return $date;
    }

    /** Pièce déposée par le formulaire, obligatoire pour un virement annoncé. */
    private static function piece(string $type, mixed $chemin): ?string
    {
        $chemin = blank($chemin) ? null : (string) $chemin;
        if ($type === DeblocageAcces::VIREMENT_ANNONCE && ! DeblocageAcces::cheminPieceValide($chemin)) {
            throw new InvalidArgumentException('Joignez la preuve du virement (PDF, JPG ou PNG) : elle est obligatoire pour un virement annoncé.');
        }
        if ($chemin !== null && ! DeblocageAcces::cheminPieceValide($chemin)) {
            throw new InvalidArgumentException('La pièce jointe est invalide : déposez-la à nouveau.');
        }
        // Une preuve ne sert qu'une fois : jamais le bordereau d'un versement ni la pièce d'un autre déblocage.
        if ($chemin !== null && Versement::pieceDejaJointe($chemin)) {
            throw new InvalidArgumentException('Cette pièce est déjà jointe à un versement ou à un autre déblocage : déposez la pièce propre à ce dossier.');
        }

        return $chemin;
    }

    /** Dossier non retiré, auditeur ni exclu ni en abandon, inscription de l'année courante. */
    private static function dossierOuvert(InscriptionAnnuelle $inscription): Candidature
    {
        $auditeur = Auditeur::query()->with(['candidature', 'inscriptionCourante'])->find($inscription->auditeur_id);
        $dossier = $auditeur?->candidature;
        if ($dossier === null || $dossier->withdrawn_at !== null) {
            throw new InvalidArgumentException('Ce dossier est retiré : aucun déblocage n\'est possible. L\'historique reste consultable.');
        }
        if (in_array($auditeur->statut, MessagesAuditeurs::STATUTS_EXCLUS, true)) {
            throw new InvalidArgumentException('Auditeur au statut « '.mb_strtolower(Auditeur::STATUTS[$auditeur->statut]).' » : aucun déblocage n\'est possible.');
        }
        if ($auditeur->inscriptionCourante?->id !== $inscription->id) {
            throw new InvalidArgumentException('Un déblocage ne porte que sur l\'inscription de l\'année en cours.');
        }

        return $dossier;
    }

    private static function jour(mixed $saisie): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $saisie, InscriptionAnnuelle::FUSEAU)->startOfDay();
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException('Date invalide : choisissez-la dans le calendrier.');
        }
    }

    private static function messageEngagement(): string
    {
        return 'Cet accès dépend d\'un engagement de prise en charge : prolongez l\'engagement (écran « Prises en charge »).';
    }

    private static function messageDejaEnCours(): string
    {
        return 'Un déblocage est déjà en cours sur cette inscription : prolongez-le, ou faites-le révoquer, plutôt que d\'en accorder un second.';
    }
}
