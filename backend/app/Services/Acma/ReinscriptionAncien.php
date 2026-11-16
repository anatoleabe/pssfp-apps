<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Parcours;
use App\Models\ReinscriptionAcma;
use App\Models\User;
use App\Services\AjoutRetenu\CompteRetenu;
use App\Services\CandidatUserService;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\InscriptionAnneeEnCours;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Réinscription d'un ancien auditeur depuis son dossier d'archive ACMA (spec de la réinscription des anciens, §7) :
 * un seul geste, une seule transaction — candidature hors PV, compte candidat, auditeur au matricule ACMA, inscription
 * dans l'année en cours, lien de l'archive posé par une décision signée (« décision d'abord »), trace en ajout seul.
 *
 * Aucun envoi ici. L'accès s'ouvre par le chemin normal (argent confirmé, ou déblocage) et l'avis part alors une fois.
 * Le compte eCampus se crée après la transaction (`ComptesEcampus`), comme à l'attribution des matricules.
 */
final class ReinscriptionAncien
{
    public const PERMISSION = 'scolarite.inscrire_ancien';

    public const EVENEMENT = 'ancien_reinscrit';

    public const MOTIF_MINIMUM = 10;

    public function __construct(
        private readonly CandidatUserService $comptes,
        private readonly InscriptionAnneeEnCours $inscriptions,
        private readonly ArchivesAcma $archives,
        private readonly DoublonsAncien $doublons,
    ) {}

    /**
     * @param  array<string, mixed>  $saisie  champs du formulaire (§7.3)
     *
     * @throws InvalidArgumentException message destiné à l'agent ; rien n'est écrit
     */
    public function reinscrire(AcmaDossier $dossier, array $saisie, User $auteur): Candidature
    {
        abort_unless($auteur->can(self::PERMISSION) && $auteur->can(ArchivesAcma::CONSULTER), 403);
        $s = $this->valider($saisie);

        try {
            return DB::transaction(function () use ($dossier, $s, $auteur): Candidature {
                $campagne = CampagneCandidature::query()->whereKey($s['campagne_id'])->lockForUpdate()->first()
                    ?? throw new InvalidArgumentException('Promotion de rattachement introuvable.');
                $d = AcmaDossier::query()->whereKey($dossier->id)->lockForUpdate()->firstOrFail();
                $this->prealables($d);
                $this->doublons->refuser($d, $s);
                $niveau = $this->controlerNiveau($d, $s, $auteur);
                $parcours = $this->parcours($s);
                $naissance = $this->dateNaissance($d, $s, $auteur);

                $candidature = $this->candidature($d, $campagne, $s, $naissance, $parcours, $auteur);
                $auditeur = Auditeur::query()->create(['candidature_id' => $candidature->id]);
                $inscription = $this->inscriptions->inscrire($auditeur, $niveau, $parcours, $auteur);
                $rapprochement = LienArchive::poser($d, $auditeur, 'Réinscription depuis l\'archive : '.$s['motif'], $auteur);

                $trace = new ReinscriptionAcma;
                $trace->forceFill([
                    'dossier_acma_id' => $d->id, 'candidature_id' => $candidature->id, 'auditeur_id' => $auditeur->id,
                    'inscription_annuelle_id' => $inscription->id, 'campagne_id' => $campagne->id, 'rapprochement_id' => $rapprochement,
                    'mode' => ReinscriptionAcma::CREATION, 'niveau' => $niveau, 'parcours' => $parcours->code,
                    'derogation_m2' => (bool) $s['derogation_m2'], 'derogation_piece' => $s['derogation_m2'] ? $s['derogation_piece'] : null,
                    'coordonnees_modifiees' => $this->coordonneesModifiees($d, $s), 'motif' => $s['motif'], 'auteur_id' => $auteur->id,
                ])->save();

                $this->journaliser($d, $candidature, $trace, $campagne, $auteur);

                return $candidature;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ce dossier, ce matricule ou ce téléphone vient d\'être enregistré par ailleurs. Vérifiez, puis réessayez.');
        }
    }

    /**
     * Compte eCampus, après la transaction, comme à l'attribution des matricules, dans la cohorte de la promotion
     * d'accueil (15l). Un compte déjà présent sous ce matricule n'est jamais modifié, et le mot de passe généré, qui
     * n'est pas le sien, est effacé : l'espace n'affiche jamais un mot de passe faux. Aucun envoi : l'accès est fermé
     * tant que rien n'est payé ni débloqué.
     *
     * @return string|null état eCampus, ou null si rien n'a été tenté
     */
    public function ouvrirEcampus(Candidature $candidature, User $auteur): ?string
    {
        $candidature->loadMissing(['campagne', 'auditeur.inscriptionCourante']);
        if (! ComptesEcampus::actif() || ! $auteur->can(IdentifiantsMoodle::PERMISSION)) {
            return null;
        }
        try {
            $statut = app(ComptesEcampus::class)->traiter($candidature, $auteur);
        } catch (Throwable $e) {
            Log::error('Compte eCampus d\'un ancien réinscrit non traité.', ['erreur' => $e::class]);

            return ComptesEcampus::ECHEC;
        }
        if ($statut === ComptesEcampus::EXISTANT) {
            $candidature->forceFill(['moodle_password' => null])->save();
        }

        return $statut;
    }

    /**
     * @param  array<string, mixed>  $saisie
     * @return array<string, mixed>
     */
    private function valider(array $saisie): array
    {
        $motif = trim((string) ($saisie['motif'] ?? ''));
        if (mb_strlen($motif) < self::MOTIF_MINIMUM) {
            throw new InvalidArgumentException('Indiquez le motif (au moins '.self::MOTIF_MINIMUM.' caractères).');
        }
        foreach (['nom' => 'le nom', 'prenom' => 'le ou les prénoms', 'date_naissance' => 'la date de naissance', 'genre' => 'le genre', 'niveau' => 'le niveau', 'parcours' => 'le parcours'] as $champ => $libelle) {
            if (trim((string) ($saisie[$champ] ?? '')) === '') {
                throw new InvalidArgumentException("Indiquez {$libelle}.");
            }
        }
        $naissance = CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $saisie['date_naissance'], 0, 10)) ?: null;
        if ($naissance === null || $naissance->year < 1920 || $naissance->isFuture()) {
            throw new InvalidArgumentException('Date de naissance invalide.');
        }
        $email = filled($saisie['email'] ?? null) ? CoordonneesAcma::email((string) $saisie['email']) : null;
        if (filled($saisie['email'] ?? null) && $email === null) {
            throw new InvalidArgumentException('L\'e-mail n\'a pas une forme valide.');
        }
        if (AnneeEnCours::annee() === null) {
            throw new InvalidArgumentException('Année en cours non réglée : aucune réinscription possible.');
        }

        return array_merge($saisie, [
            'motif' => $motif,
            'nom' => mb_strtoupper(trim((string) $saisie['nom'])),
            'prenom' => trim((string) $saisie['prenom']),
            'date_naissance' => $naissance->toDateString(),
            'phone_e164' => CompteRetenu::telephone((string) ($saisie['phone_e164'] ?? '')),
            'email' => $email,
            'niveau' => (string) $saisie['niveau'],
            'derogation_m2' => (bool) ($saisie['derogation_m2'] ?? false),
            'derogation_piece' => trim((string) ($saisie['derogation_piece'] ?? '')),
        ]);
    }

    /** §7.2 : un dossier se réinscrit une fois, jamais retiré, écarté, déjà lié ou en attente d'arbitrage. */
    private function prealables(AcmaDossier $d): void
    {
        if ($d->retire_de_la_source_at !== null) {
            throw new InvalidArgumentException('Dossier disparu de l\'export ACMA : réinscription impossible.');
        }
        if (ReinscriptionAcma::query()->where('dossier_acma_id', $d->id)->exists()) {
            throw new InvalidArgumentException('Cet ancien auditeur est déjà réinscrit.');
        }
        match ($d->statut_rapprochement) {
            'ecarte' => throw new InvalidArgumentException('Dossier écarté (fiche de test ou neutralisée) : réinscription impossible.'),
            'lie_auto', 'lie_arbitre' => throw new InvalidArgumentException('Ce dossier est déjà lié à un auditeur de la plateforme : ouvrez sa fiche.'),
            'a_arbitrer' => throw new InvalidArgumentException('Arbitrez d\'abord le rapprochement de ce dossier : un auditeur de la plateforme est peut-être la même personne.'),
            default => null,
        };
        if (Candidature::withTrashed()->where('matricule', $d->matricule)->exists()) {
            throw new InvalidArgumentException("Le matricule {$d->matricule} est déjà porté par un dossier de la plateforme.");
        }
    }

    /**
     * §7.4 : le M2 exige un M1 admis dans l'archive (décision enregistrée ou transcrite), sauf dérogation du
     * responsable avec sa pièce ; un M1 déjà admis demande une confirmation.
     *
     * @param  array<string, mixed>  $s
     */
    private function controlerNiveau(AcmaDossier $d, array $s, User $auteur): string
    {
        $niveau = (string) $s['niveau'];
        if (! in_array($niveau, [BaremeScolarite::NIVEAU_M1, BaremeScolarite::NIVEAU_M2], true)) {
            throw new InvalidArgumentException('Niveau inconnu : M1 ou M2.');
        }
        $m1Admis = $d->inscriptions()->whereNull('retire_de_la_source_at')->where('niveau', 'M1')->get()
            ->contains(fn (AcmaInscription $i): bool => ArchivesAcma::decision($i)['code'] === 'admis');
        if ($niveau === BaremeScolarite::NIVEAU_M2 && ! $m1Admis && ! $s['derogation_m2']) {
            throw new InvalidArgumentException('Aucun M1 admis dans l\'archive (décision enregistrée ou transcrite d\'un PV) : transcrivez d\'abord la décision, ou accordez une dérogation motivée avec sa pièce.');
        }
        if ($s['derogation_m2'] && ($niveau !== BaremeScolarite::NIVEAU_M2 || $s['derogation_piece'] === '')) {
            throw new InvalidArgumentException('Une dérogation ne vaut que pour le M2, avec la référence de sa pièce.');
        }
        if ($niveau === BaremeScolarite::NIVEAU_M1 && $m1Admis && ! ($s['confirmer_m1_admis'] ?? false)) {
            throw new InvalidArgumentException('Cet ancien auditeur est déjà admis en M1 dans l\'archive : confirmez la réinscription en M1.');
        }

        return $niveau;
    }

    /** @param  array<string, mixed>  $s */
    private function parcours(array $s): Parcours
    {
        return Parcours::query()->where('code', (string) $s['parcours'])->where('niveau', (string) $s['niveau'])->where('actif', true)->first()
            ?? throw new InvalidArgumentException('Parcours inconnu pour ce niveau.');
    }

    /**
     * Date de naissance retenue : celle de l'archive en vigueur, ou une date saisie d'après une pièce, alors portée en
     * complément de l'archive (ajout seul) pour que l'archive et la plateforme disent la même chose.
     *
     * @param  array<string, mixed>  $s
     */
    private function dateNaissance(AcmaDossier $d, array $s, User $auteur): string
    {
        $archive = self::dateNaissanceArchive($d);
        if ($archive === $s['date_naissance']) {
            return $archive;
        }
        $piece = trim((string) ($s['date_naissance_piece'] ?? ''));
        if ($piece === '') {
            throw new InvalidArgumentException($archive === null
                ? 'L\'archive n\'a pas de date de naissance exploitable : indiquez la pièce qui la porte (acte de naissance, pièce d\'identité).'
                : 'La date saisie diffère de celle de l\'archive : indiquez la pièce qui la porte.');
        }
        $this->archives->completerDateNaissance($d, $s['date_naissance'], $piece, 'Réinscription : '.$s['motif'], $auteur);

        return $s['date_naissance'];
    }

    /** Date en vigueur dans l'archive : le dernier complément, sinon la date ACMA si elle est exploitable. */
    public static function dateNaissanceArchive(AcmaDossier $d): ?string
    {
        $complement = $d->complements()->where('champ', 'date_naissance')->latest('id')->value('valeur');

        return $complement ?? ($d->date_naissance_a_verifier ? null : $d->date_naissance?->toDateString());
    }

    /** @param  array<string, mixed>  $s */
    private function candidature(AcmaDossier $d, CampagneCandidature $campagne, array $s, string $naissance, Parcours $parcours, User $auteur): Candidature
    {
        $telephone = (string) $s['phone_e164'];
        CompteRetenu::refuserTelephonePris($campagne, $telephone);
        $compte = CompteRetenu::compteExistant($campagne, $telephone, $naissance);
        $nationalite = filled($s['nationalite'] ?? null) ? strtoupper((string) $s['nationalite']) : null;
        $pays = CompteRetenu::paysDuTelephone($telephone, $nationalite);
        $compte ??= $this->comptes->createCandidat($telephone, Str::random(40), [
            'prenom' => $s['prenom'], 'nom' => $s['nom'], 'phone_country' => $pays, 'date_naissance' => $naissance,
        ]);

        $candidature = new Candidature(array_filter([
            'campagne_id' => $campagne->id, 'user_id' => $compte->id, 'phone_e164' => $telephone, 'phone_country' => $pays,
            'civilite' => $s['civilite'] ?? null, 'genre' => $s['genre'], 'nom' => $s['nom'], 'prenom' => $s['prenom'],
            'epouse' => filled($s['epouse'] ?? null) ? trim((string) $s['epouse']) : null,
            'date_naissance' => $naissance, 'lieu_naissance' => self::texte($s['lieu_naissance'] ?? null, 100),
            'nationalite' => $nationalite ?? $pays, 'pays_origine' => $nationalite ?? $pays, 'pays_residence' => $pays,
            'email' => $s['email'], 'adresse' => self::texte($s['adresse'] ?? null, 200), 'ville_residence' => self::texte($s['ville_residence'] ?? null, 100),
            'specialite' => $parcours->specialite,
            'statut' => Candidature::STATUT_ACCEPTE, 'decided_at' => now(), 'frais_paye' => false,
        ], fn ($v): bool => $v !== null && $v !== ''));
        $candidature->forceFill([
            // Matricule ACMA conservé (un matricule ne change pas) ; aucun rang : il n'entre pas dans la numérotation.
            'matricule' => $d->matricule,
            'categorie_matricule' => str_contains($d->matricule, Candidature::CATEGORIE_EFSC) ? Candidature::CATEGORIE_EFSC : Candidature::CATEGORIE_EFP,
            'rang_retenu' => null,
            'matricule_attribue_at' => now(),
            'moodle_password' => AttributionMatricules::motDePasse(),
            // Hors PV et hors statistiques, comme un retenu ajouté depuis l'admin.
            'ajoute_par' => $auteur->id,
        ])->save();

        return $candidature;
    }

    /**
     * Noms des champs de coordonnées changés par rapport à la proposition ACMA (jamais une valeur).
     *
     * @param  array<string, mixed>  $s
     * @return list<string>|null
     */
    private function coordonneesModifiees(AcmaDossier $d, array $s): ?array
    {
        $propose = CoordonneesAcma::propositions($d);
        $modifies = array_keys(array_filter([
            'telephone' => $propose['telephone'] !== $s['phone_e164'],
            'email' => $propose['email'] !== $s['email'],
            'adresse' => NormalisationAcma::texte($d->adresse_source) !== self::texte($s['adresse'] ?? null, 200),
        ]));

        return $modifies === [] ? null : $modifies;
    }

    private function journaliser(AcmaDossier $d, Candidature $candidature, ReinscriptionAcma $trace, CampagneCandidature $campagne, User $auteur): void
    {
        activity(Journaux::ARCHIVES)->causedBy($auteur)->event('rapprochement_decide')
            ->withProperties(['dossier' => $d->uuid, 'decision' => 'lie', 'origine' => 'reinscription'])
            ->log('Rapprochement d\'un dossier d\'archive ACMA décidé par la réinscription');
        activity(Journaux::SCOLARITE)->causedBy($auteur)->performedOn($candidature)->event(self::EVENEMENT)
            ->withProperties([
                'dossier' => $d->uuid, 'promotion' => $campagne->promotion_numero,
                'niveau' => $trace->niveau, 'parcours' => $trace->parcours, 'derogation_m2' => $trace->derogation_m2,
                'champs_modifies' => $trace->coordonnees_modifiees ?? [], 'motif' => $trace->motif,
            ])
            ->log('Ancien auditeur réinscrit depuis l\'archive ACMA');
        activity(Journaux::CANDIDATURES)->causedBy($auteur)->performedOn($candidature)->event('retenu_reinscrit_acma')
            ->withProperties(['numero' => $candidature->numero_dossier])
            ->log('Dossier créé par la réinscription d\'un ancien auditeur');
    }

    private static function texte(mixed $v, int $max): ?string
    {
        $t = NormalisationAcma::texte(is_string($v) ? $v : null);

        return $t === null ? null : mb_substr($t, 0, $max);
    }
}
