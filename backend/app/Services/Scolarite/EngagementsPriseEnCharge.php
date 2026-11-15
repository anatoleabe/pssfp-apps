<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Jobs\SendThrottledMail;
use App\Mail\RelanceOrganismeMail;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
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
 * Engagements de prise en charge (spec module 4, lot A2 §9.4, §9.5 et §9.8) : créer,
 * modifier le contact, prolonger, rattacher des auditeurs, relancer l'organisme. Seul
 * point d'écriture de `engagements_prise_en_charge` ; les déblocages passent par
 * DeblocagesAcces, l'argent par ReglementsOrganismes.
 *
 * Chaque geste revérifie sa permission et finit au journal `scolarite`. Les
 * InvalidArgumentException portent un message destiné à l'agent. La relance de
 * l'organisme ne part que sur clic : aucune tâche planifiée ne l'appelle.
 */
final class EngagementsPriseEnCharge
{
    public const ORGANISME_MAX = 200;

    public const LETTRE_REFERENCE_MAX = 100;

    public const CONTACT_NOM_MAX = 150;

    public const CONTACT_TELEPHONE_MAX = 30;

    public const NOTES_MAX = 2000;

    /** Échéance au plus loin, en jours à compter d'aujourd'hui : celle d'un moratoire. */
    public const JOURS_MAX = 180;

    private const CHAMPS_CONTACT = ['contact_nom', 'contact_email', 'contact_telephone', 'notes'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DeblocagesAcces $deblocages,
    ) {}

    /** Créer, modifier le contact, rattacher, relancer : les droits de déblocage. */
    public static function peutGerer(?User $u): bool
    {
        return (bool) ($u?->can(DeblocagesAcces::PERMISSION) || $u?->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL));
    }

    public static function peutProlonger(?User $u): bool
    {
        return (bool) $u?->can(DeblocagesAcces::PERMISSION_EXCEPTIONNEL);
    }

    /** @return array<string, string> années des barèmes, la plus récente d'abord */
    public static function annees(): array
    {
        $annees = BaremeScolarite::query()->distinct()->orderByDesc('annee_academique')->pluck('annee_academique')->all();

        return array_combine($annees, $annees) ?: [];
    }

    public static function echeanceMax(): CarbonImmutable
    {
        return InscriptionAnnuelle::aujourdhui()->addDays(self::JOURS_MAX);
    }

    /**
     * @param  array<string, mixed>  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function creer(array $saisie, User $auteur): EngagementPriseEnCharge
    {
        abort_unless(self::peutGerer($auteur), 403);

        $organisme = trim((string) ($saisie['organisme'] ?? ''));
        if ($organisme === '' || mb_strlen($organisme) > self::ORGANISME_MAX) {
            throw new InvalidArgumentException('Indiquez l\'organisme qui prend en charge ('.self::ORGANISME_MAX.' caractères au plus).');
        }
        $reference = Versement::normaliserReference((string) ($saisie['lettre_reference'] ?? ''));
        if ($reference === '' || mb_strlen($reference) > self::LETTRE_REFERENCE_MAX) {
            throw new InvalidArgumentException('Indiquez la référence de la lettre ('.self::LETTRE_REFERENCE_MAX.' caractères au plus).');
        }
        if (blank($saisie['lettre_date'] ?? null)) {
            throw new InvalidArgumentException('Indiquez la date de la lettre.');
        }
        $dateLettre = self::jour($saisie['lettre_date']);
        if ($dateLettre->toDateString() > InscriptionAnnuelle::aujourdhui()->toDateString()) {
            throw new InvalidArgumentException('La date de la lettre ne peut pas être dans le futur.');
        }
        $lettre = self::lettre($saisie['lettre_path'] ?? null);
        $portee = (string) ($saisie['portee'] ?? '');
        if (! array_key_exists($portee, EngagementPriseEnCharge::PORTEES)) {
            throw new InvalidArgumentException('Choisissez la portée de l\'engagement : droits d\'inscription ou scolarité de l\'année.');
        }
        $annee = (string) ($saisie['annee_academique'] ?? '');
        if (! array_key_exists($annee, self::annees())) {
            throw new InvalidArgumentException('Choisissez une année académique dotée d\'un barème.');
        }
        $echeance = self::echeance($saisie['echeance'] ?? null);
        $contact = self::contact($saisie, []);

        if (EngagementPriseEnCharge::query()->whereRaw('lower(organisme) = lower(?)', [$organisme])
            ->where('lettre_reference', $reference)->where('annee_academique', $annee)->exists()) {
            throw new InvalidArgumentException(self::messageDoublon());
        }

        try {
            $e = $this->db->transaction(function () use ($organisme, $reference, $dateLettre, $lettre, $portee, $annee, $echeance, $contact, $auteur): EngagementPriseEnCharge {
                $e = new EngagementPriseEnCharge;
                $e->forceFill([
                    'organisme' => $organisme,
                    'lettre_reference' => $reference,
                    'lettre_date' => $dateLettre->toDateString(),
                    'lettre_path' => $lettre,
                    'portee' => $portee,
                    'annee_academique' => $annee,
                    'echeance' => $echeance->toDateString(),
                    'cree_par' => $auteur->id,
                ] + $contact)->save();

                activity('scolarite')->causedBy($auteur)->performedOn($e)
                    ->event('engagement_cree')
                    ->withProperties(['organisme' => $organisme, 'lettre' => $reference, 'portee' => $portee, 'annee' => $annee, 'echeance' => $echeance->toDateString()])
                    ->log('Engagement de prise en charge enregistré ('.$organisme.')');

                return $e;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux agents sur la même lettre, au même moment : l'index tranche.
            throw new InvalidArgumentException(self::messageDoublon());
        }

        return $e;
    }

    /**
     * Contact et notes seulement : organisme, lettre, portée et année ne changent pas.
     *
     * @param  array<string, mixed>  $saisie
     *
     * @throws InvalidArgumentException
     */
    public function modifierContact(EngagementPriseEnCharge $engagement, array $saisie, User $auteur): void
    {
        abort_unless(self::peutGerer($auteur), 403);
        $valeurs = self::contact($saisie, $engagement->only(self::CHAMPS_CONTACT));

        $this->db->transaction(function () use ($engagement, $valeurs, $auteur): void {
            $e = EngagementPriseEnCharge::query()->whereKey($engagement->id)->lockForUpdate()->firstOrFail();
            $avant = [];
            $apres = [];
            foreach ($valeurs as $champ => $valeur) {
                if ($e->getAttribute($champ) !== $valeur) {
                    $avant[$champ] = $e->getAttribute($champ);
                    $apres[$champ] = $valeur;
                }
            }
            if ($apres === []) {
                return;
            }
            $e->forceFill($apres)->save();
            activity('scolarite')->causedBy($auteur)->performedOn($e)
                ->event('engagement_modifie')
                ->withProperties(['avant' => $avant, 'apres' => $apres])
                ->log('Contact de l\'engagement modifié');
        });
    }

    /**
     * Nouvelle échéance de l'engagement et de tous ses déblocages en cours (responsable).
     *
     * @return int déblocages prolongés
     *
     * @throws InvalidArgumentException
     */
    public function prolonger(EngagementPriseEnCharge $engagement, mixed $echeance, mixed $motif, User $auteur): int
    {
        abort_unless(self::peutProlonger($auteur), 403);
        $motif = trim((string) $motif);
        if (mb_strlen($motif) < DeblocagesAcces::MOTIF_MIN) {
            throw new InvalidArgumentException('Indiquez le motif (au moins '.DeblocagesAcces::MOTIF_MIN.' caractères).');
        }
        $motif = mb_substr($motif, 0, DeblocagesAcces::MOTIF_MAX);
        if (blank($echeance)) {
            throw new InvalidArgumentException('Indiquez la nouvelle échéance.');
        }
        $nouvelle = self::jour($echeance);

        return $this->db->transaction(function () use ($engagement, $nouvelle, $motif, $auteur): int {
            $e = EngagementPriseEnCharge::query()->whereKey($engagement->id)->lockForUpdate()->firstOrFail();
            $ancienne = $e->echeance->toDateString();
            if ($nouvelle->toDateString() <= $ancienne) {
                throw new InvalidArgumentException('La nouvelle échéance doit tomber après l\'échéance actuelle, le '.$e->echeance->format('d/m/Y').'.');
            }
            self::bornerEcheance($nouvelle);

            $e->forceFill(['echeance' => $nouvelle->toDateString(), 'prolongations' => $e->prolongations + 1])->save();
            $n = $this->deblocages->prolongerEngagement($e, $nouvelle, $motif, $auteur);
            activity('scolarite')->causedBy($auteur)->performedOn($e)
                ->event('engagement_prolonge')
                ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $nouvelle->toDateString(), 'motif' => $motif, 'deblocages' => $n])
                ->log('Engagement prolongé jusqu\'au '.$nouvelle->format('d/m/Y'));

            return $n;
        });
    }

    /**
     * Rattache des auditeurs, chacun séparément : les uns rattachés, les autres refusés avec leur
     * motif, par matricule (§9.5).
     *
     * @param  iterable<Auditeur|string>  $auditeurs  auditeurs ou leurs uuid
     */
    public function rattacher(EngagementPriseEnCharge $engagement, iterable $auditeurs, User $auteur): ResultatRattachement
    {
        abort_unless(self::peutGerer($auteur), 403);

        $rattaches = [];
        $refus = [];
        $prevenus = 0;
        $echecs = 0;
        foreach ($auditeurs as $a) {
            $auditeur = Auditeur::query()->with(['candidature', 'inscriptionCourante'])
                ->when($a instanceof Auditeur, fn ($q) => $q->whereKey($a->getKey()), fn ($q) => $q->where('uuid', (string) $a))->first();
            if ($auditeur === null) {
                continue;
            }
            $matricule = (string) ($auditeur->candidature?->matricule ?: 'sans matricule');
            if ($auditeur->inscriptionCourante === null) {
                $refus[$matricule] = 'Aucune inscription en cours.';

                continue;
            }
            try {
                $resultat = $this->deblocages->rattacher($engagement, $auditeur->inscriptionCourante, $auteur);
            } catch (InvalidArgumentException $e) {
                $refus[$matricule] = $e->getMessage();

                continue;
            }
            $rattaches[] = $matricule;
            $prevenus += $resultat->notification === true ? 1 : 0;
            $echecs += $resultat->notification === false ? 1 : 0;
        }

        return new ResultatRattachement($rattaches, $refus, $prevenus, $echecs);
    }

    /**
     * E-mail au contact de l'organisme, sur clic seulement (§9.8) : les auditeurs couverts qui
     * ont un reste. Par la file bridée des envois.
     *
     * @throws InvalidArgumentException
     */
    public function relancer(EngagementPriseEnCharge $engagement, User $auteur): void
    {
        abort_unless(self::peutGerer($auteur), 403);
        $e = EngagementPriseEnCharge::query()->findOrFail($engagement->id);
        if (blank($e->contact_email)) {
            throw new InvalidArgumentException('Le contact de l\'organisme n\'a pas d\'adresse e-mail : complétez-le avant de relancer.');
        }
        $suivi = SuiviEngagement::de($e);
        $ouvertes = array_values(array_filter($suivi->lignes, fn (array $l): bool => $l['reste'] > 0));
        if ($ouvertes === []) {
            throw new InvalidArgumentException('Rien à relancer : aucun auditeur couvert n\'a de reste à régler.');
        }
        $candidatures = Auditeur::query()->with('candidature:id,nom,prenom,matricule')
            ->whereKey(array_map(fn (array $l): int => (int) $l['inscription']->auditeur_id, $ouvertes))->get()->keyBy('id');
        $lignes = array_map(function (array $l) use ($candidatures): array {
            $c = $candidatures->get($l['inscription']->auditeur_id)?->candidature;

            return [
                'matricule' => (string) ($c?->matricule ?: '—'),
                'nom' => trim(mb_strtoupper((string) $c?->nom).' '.$c?->prenom),
                'reste' => RecusVersements::fcfa($l['reste']),
            ];
        }, $ouvertes);
        $reste = array_sum(array_column($ouvertes, 'reste'));

        SendThrottledMail::dispatch([(string) $e->contact_email], new RelanceOrganismeMail(
            $e->organisme, $e->libelleLettre(), $e->libellePortee(), $e->annee_academique, $e->echeance->format('d/m/Y'),
            $lignes, RecusVersements::fcfa($reste),
        ));

        EngagementPriseEnCharge::query()->whereKey($e->id)->increment('relances', 1, ['derniere_relance_le' => now()]);
        activity('scolarite')->causedBy($auteur)->performedOn($e)
            ->event('engagement_relance')
            ->withProperties(['destinataire' => (string) $e->contact_email, 'reste' => $reste, 'auditeurs' => count($ouvertes)])
            ->log('Organisme relancé par e-mail');
    }

    /**
     * Contact et notes validés ; un champ absent de la saisie garde sa valeur.
     *
     * @param  array<string, mixed>  $saisie
     * @param  array<string, mixed>  $actuel
     * @return array{contact_nom: ?string, contact_email: ?string, contact_telephone: ?string, notes: ?string}
     */
    private static function contact(array $saisie, array $actuel): array
    {
        $valeur = function (string $champ, int $max, string $libelle) use ($saisie, $actuel): ?string {
            $v = array_key_exists($champ, $saisie) ? trim((string) $saisie[$champ]) : ($actuel[$champ] ?? null);
            if ($v === null || $v === '') {
                return null;
            }
            if (mb_strlen($v) > $max) {
                throw new InvalidArgumentException($libelle.' : '.$max.' caractères au plus.');
            }

            return $v;
        };
        $email = $valeur('contact_email', 255, 'Adresse e-mail du contact');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('L\'adresse e-mail du contact est invalide.');
        }

        return [
            'contact_nom' => $valeur('contact_nom', self::CONTACT_NOM_MAX, 'Nom du contact'),
            'contact_email' => $email === null ? null : mb_strtolower($email),
            'contact_telephone' => $valeur('contact_telephone', self::CONTACT_TELEPHONE_MAX, 'Téléphone du contact'),
            'notes' => $valeur('notes', self::NOTES_MAX, 'Notes'),
        ];
    }

    /** Lettre déposée par le formulaire, dans le dossier des justificatifs, propre à cet engagement. */
    private static function lettre(mixed $chemin): string
    {
        if (blank($chemin)) {
            throw new InvalidArgumentException('Joignez la lettre de prise en charge de l\'organisme : elle est obligatoire.');
        }
        $chemin = (string) $chemin;
        if (! Versement::cheminJustificatifValide($chemin)) {
            throw new InvalidArgumentException('La lettre jointe est invalide : déposez-la à nouveau.');
        }
        if (Versement::pieceDejaJointe($chemin)) {
            throw new InvalidArgumentException('Cette pièce est déjà jointe à un versement, à un accès anticipé, à un engagement ou à un règlement : déposez la lettre propre à cet engagement.');
        }

        return $chemin;
    }

    private static function echeance(mixed $saisie): CarbonImmutable
    {
        if (blank($saisie)) {
            throw new InvalidArgumentException('Indiquez l\'échéance de l\'engagement.');
        }
        $date = self::jour($saisie);
        self::bornerEcheance($date);

        return $date;
    }

    private static function bornerEcheance(CarbonImmutable $date): void
    {
        if ($date->toDateString() <= InscriptionAnnuelle::aujourdhui()->toDateString()) {
            throw new InvalidArgumentException('L\'échéance doit être postérieure à aujourd\'hui.');
        }
        $max = self::echeanceMax();
        if ($date->toDateString() > $max->toDateString()) {
            throw new InvalidArgumentException('Échéance trop lointaine : au plus '.self::JOURS_MAX.' jours, soit le '.$max->format('d/m/Y').'.');
        }
    }

    private static function jour(mixed $saisie): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $saisie, InscriptionAnnuelle::FUSEAU)->startOfDay();
        } catch (InvalidFormatException) {
            throw new InvalidArgumentException('Date invalide : choisissez-la dans le calendrier.');
        }
    }

    private static function messageDoublon(): string
    {
        return 'Cet engagement est déjà enregistré : même organisme, même lettre, même année. Retrouvez-le dans la liste des prises en charge.';
    }
}
