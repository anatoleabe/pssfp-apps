<?php

declare(strict_types=1);

namespace App\Services\CorrectionDossier;

use App\Models\Candidature;
use App\Models\User;
use App\Services\Ecampus\EchecEcampus;
use App\Services\Ecampus\IdentiteEcampus;
use App\Support\PhoneMasker;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Correction des informations personnelles d'un dossier par l'administrateur (24/11/2026), dossier décidé et auditeur
 * compris : le formulaire des candidatures reste fermé aux dossiers décidés, cette correction ne touche que les champs
 * ci-dessous, jamais le matricule, le statut, les choix pédagogiques ni les pièces.
 *
 * - Motif obligatoire ; le journal garde, champ par champ, l'ancienne et la nouvelle valeur (téléphones masqués).
 * - Le compte de connexion du candidat suit (nom affiché, date de naissance), s'il n'est qu'un compte candidat.
 * - Le compte eCampus suit (nom, prénom, e-mail) ; une panne d'eCampus n'annule pas la correction, elle est signalée.
 * - Les documents déjà remis ne changent pas ; ceux produits ensuite portent les nouvelles informations.
 */
final class CorrectionDossier
{
    public const PERMISSION = 'candidature.dossier_corriger';

    public const EVENEMENT = 'dossier_corrige';

    /** Champs corrigeables, dans l'ordre du formulaire, avec leur libellé. */
    public const CHAMPS = [
        'civilite' => 'Civilité',
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'epouse' => 'Nom de naissance',
        'date_naissance' => 'Date de naissance',
        'lieu_naissance' => 'Lieu de naissance',
        'genre' => 'Genre',
        'statut_matrimonial' => 'Situation matrimoniale',
        'nationalite' => 'Nationalité',
        'email' => 'E-mail',
        'indicatif1' => 'Indicatif du téléphone 1',
        'telephone1' => 'Téléphone 1',
        'indicatif2' => 'Indicatif du téléphone 2',
        'telephone2' => 'Téléphone 2',
        'pays_origine' => 'Pays d\'origine',
        'pays_residence' => 'Pays de résidence',
        'region' => 'Région',
        'departement' => 'Département',
        'adresse' => 'Adresse',
        'ville_residence' => 'Ville de résidence',
    ];

    private const CHAMPS_ECAMPUS = ['nom', 'prenom', 'email'];

    private const TELEPHONES = ['telephone1', 'telephone2'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly IdentiteEcampus $ecampus,
    ) {}

    /**
     * Ce qui changerait : `[champ => [avant, après]]`, valeurs normalisées. Sert au récapitulatif du formulaire.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public function changements(Candidature $c, array $donnees): array
    {
        $nouveau = self::normaliser($donnees);
        $changements = [];
        foreach ($nouveau as $champ => $valeur) {
            $avant = self::valeurActuelle($c, $champ);
            if ($avant !== $valeur) {
                $changements[$champ] = [$avant, $valeur];
            }
        }

        return $changements;
    }

    /**
     * @param  array<string, mixed>  $donnees  champs du formulaire (les autres sont ignorés)
     * @return array{changements: array<string, array{0: ?string, 1: ?string}>, ecampus: ?string, ecampus_ok: bool}
     *
     * @throws InvalidArgumentException message destiné à l'agent
     */
    public function corriger(Candidature $c, array $donnees, string $motif, User $auteur): array
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif de la correction.');
        }
        $this->valider($donnees);
        $changements = $this->changements($c, $donnees);
        if ($changements === []) {
            throw new InvalidArgumentException('Aucune information n\'a changé.');
        }
        $ancienEmail = (string) $c->email;

        $this->db->transaction(function () use ($c, $changements, $motif, $auteur): void {
            $dossier = Candidature::query()->whereKey($c->id)->lockForUpdate()->firstOrFail();
            $dossier->forceFill(array_map(fn (array $paire): ?string => $paire[1], $changements))->save();
            $this->synchroniserCompteConnexion($dossier, $changements);

            activity('candidatures')->causedBy($auteur)->performedOn($dossier)->event(self::EVENEMENT)
                ->withProperties(['motif' => $motif, 'changements' => self::pourJournal($changements)])
                ->log('Informations du dossier corrigées par l\'administration');
        });
        $c->refresh();

        [$ecampus, $ok] = [null, true];
        if ($c->matricule !== null && array_intersect(array_keys($changements), self::CHAMPS_ECAMPUS) !== []) {
            try {
                $ecampus = $this->ecampus->synchroniser($c, $ancienEmail);
            } catch (EchecEcampus $e) {
                [$ecampus, $ok] = [$e->getMessage(), false];
            }
            activity('candidatures')->causedBy($auteur)->performedOn($c)->event('dossier_corrige_ecampus')
                ->withProperties(['reussi' => $ok, 'detail' => $ecampus])
                ->log($ok ? 'Correction du dossier reportée sur eCampus' : 'Correction du dossier non reportée sur eCampus');
        }

        return ['changements' => $changements, 'ecampus' => $ecampus, 'ecampus_ok' => $ok];
    }

    /** @param  array<string, mixed>  $donnees */
    private function valider(array $donnees): void
    {
        $majeur = CarbonImmutable::now('Africa/Douala')->subYears(18)->toDateString();
        $validateur = Validator::make($donnees, [
            'civilite' => ['sometimes', 'nullable', 'in:M.,Mme,Mlle'],
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'epouse' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_naissance' => ['sometimes', 'required', 'date', 'before:'.$majeur],
            'lieu_naissance' => ['sometimes', 'required', 'string', 'max:100'],
            'genre' => ['sometimes', 'nullable', 'in:M,F,autre'],
            'statut_matrimonial' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nationalite' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:pays,code_iso'],
            'email' => ['sometimes', 'required', 'email', 'max:150'],
            'indicatif1' => ['sometimes', 'nullable', 'string', 'max:10'],
            'telephone1' => ['sometimes', 'nullable', 'string', 'max:20'],
            'indicatif2' => ['sometimes', 'nullable', 'string', 'max:10'],
            'telephone2' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pays_origine' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:pays,code_iso'],
            'pays_residence' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:pays,code_iso'],
            'region' => ['sometimes', 'nullable', 'string', 'exists:regions_cameroun,code'],
            'departement' => ['sometimes', 'nullable', 'string', 'exists:departements_cameroun,code'],
            'adresse' => ['sometimes', 'nullable', 'string', 'max:200'],
            'ville_residence' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], [
            'required' => 'Le champ « :attribute » est obligatoire.',
            'email' => 'L\'adresse e-mail n\'est pas valide.',
            'date_naissance.before' => 'La date de naissance doit être celle d\'une personne majeure.',
            'date' => 'La date n\'est pas valide.',
            'max' => 'Le champ « :attribute » est trop long.',
            'in' => 'Choisissez une valeur de la liste pour « :attribute ».',
            'size' => 'Choisissez une valeur de la liste pour « :attribute ».',
            'exists' => 'Choisissez une valeur de la liste pour « :attribute ».',
        ], array_map('mb_strtolower', self::CHAMPS));

        if ($validateur->fails()) {
            throw new InvalidArgumentException((string) $validateur->errors()->first());
        }
    }

    /**
     * Champs connus seulement, chaînes rognées, vide devenu null, date au format AAAA-MM-JJ. Hors du Cameroun, la
     * région et le département n'ont plus de sens : ils sont vidés.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, ?string>
     */
    private static function normaliser(array $donnees): array
    {
        $valeurs = [];
        foreach (array_intersect_key($donnees, self::CHAMPS) as $champ => $valeur) {
            $texte = is_scalar($valeur) ? trim((string) $valeur) : '';
            $valeurs[$champ] = $texte === '' ? null : $texte;
        }
        if (isset($valeurs['date_naissance'])) {
            $valeurs['date_naissance'] = CarbonImmutable::parse($valeurs['date_naissance'])->toDateString();
        }
        if (isset($valeurs['email'])) {
            $valeurs['email'] = mb_strtolower($valeurs['email']);
        }
        if (array_key_exists('pays_residence', $valeurs) && $valeurs['pays_residence'] !== 'CM') {
            $valeurs['region'] = null;
            $valeurs['departement'] = null;
        }

        return $valeurs;
    }

    private static function valeurActuelle(Candidature $c, string $champ): ?string
    {
        if ($champ === 'date_naissance') {
            return $c->date_naissance?->toDateString();
        }
        $valeur = $c->getAttribute($champ);
        if ($champ === 'email' && is_string($valeur)) {
            $valeur = mb_strtolower(trim($valeur));
        }

        return $valeur === null || $valeur === '' ? null : (string) $valeur;
    }

    /**
     * Le compte de connexion garde une copie du nom affiché et de la date de naissance (règle du code PIN). Il suit,
     * sauf s'il porte d'autres rôles que celui de candidat.
     *
     * @param  array<string, array{0: ?string, 1: ?string}>  $changements
     */
    private function synchroniserCompteConnexion(Candidature $dossier, array $changements): void
    {
        if (array_intersect(array_keys($changements), ['nom', 'prenom', 'date_naissance']) === []) {
            return;
        }
        $compte = $dossier->user()->with('roles')->first();
        if ($compte === null || $compte->roles->pluck('name')->all() !== ['candidat']) {
            return;
        }
        $compte->forceFill([
            'name' => trim($dossier->prenom.' '.$dossier->nom),
            'date_naissance' => $dossier->date_naissance?->toDateString(),
        ])->save();
    }

    /**
     * @param  array<string, array{0: ?string, 1: ?string}>  $changements
     * @return array<string, array{avant: ?string, apres: ?string}>
     */
    private static function pourJournal(array $changements): array
    {
        $journal = [];
        foreach ($changements as $champ => [$avant, $apres]) {
            $masquer = fn (?string $v): ?string => in_array($champ, self::TELEPHONES, true) && $v !== null ? PhoneMasker::mask($v) : $v;
            $journal[$champ] = ['avant' => $masquer($avant), 'apres' => $masquer($apres)];
        }

        return $journal;
    }
}
