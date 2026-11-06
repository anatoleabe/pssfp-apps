<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Models\User;
use App\Services\CandidatUserService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Import des auditeurs des promotions antérieures au portail (P13 et
 * réinscrits), depuis les fichiers « Full data » de la scolarité.
 *
 * - Chaque ligne dotée d'un matricule devient un dossier retenu, hors PV et hors
 *   statistiques (`submitted_at` nul), rattaché à la campagne de sa promotion
 *   (créée close si besoin), avec un compte candidat au téléphone du fichier.
 * - Les matricules de la liste des admis reçoivent l'inscription de l'année
 *   (niveau et barème donnés) ; les autres sont importés sans inscription.
 * - Le mot de passe eCampus n'est pas connu : l'accès s'affiche sans lui.
 * - Aucun message ne part ; l'import est idempotent (un matricule déjà présent
 *   est ignoré) et un essai à blanc ne touche à rien.
 */
final class ImportAuditeurs
{
    /** Colonnes reconnues dans les fichiers « Full data » (casse et accents ignorés). */
    private const COLONNES = [
        'matricule' => ['matricule (genere)', 'matricule'],
        'nom' => ['nom'],
        'prenom' => ['prenom'],
        'civilite' => ['civilite'],
        'date_naissance' => ['date de naissance', 'date_naissance'],
        'lieu_naissance' => ['lieu_de_naissce', 'lieu de naissance'],
        'nationalite' => ['nationalite'],
        'region' => ['region_dorigine'],
        'sexe' => ['sexe'],
        'statut_matrimonial' => ['statu_matrimonial'],
        'adresse' => ['adresse_candidat'],
        'telephone1' => ['colonne 1', 'telephone'],
        'telephone2' => ['colonne 2', 'telephone2'],
        'email' => ['email'],
        'ville_residence' => ['ville_residence'],
        'diplome_obtenu' => ['dernier_diplome'],
        'annee_diplome' => ['annee_optention_diplome'],
        'statut_actuel' => ['statut_prof'],
        'employeur' => ['structure'],
        'adresse_employeur' => ['adresse_structure'],
        'langue' => ['langue'],
        'type_etude' => ['type etude'],
        'diplome_requis' => ['diplome requis'],
        'specialite_diplome_requis' => ['specialite requise'],
        'specialite' => ['specialite choisie'],
        'pays' => ['pays'],
    ];

    /** @var array<string, string> nom de pays normalisé => code ISO */
    private array $pays = [];

    /** @var array<string, string> nom de région normalisé => code */
    private array $regions = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CandidatUserService $comptes,
    ) {}

    /**
     * @param  list<string>  $sources  fichiers « Full data » (xlsx/xls), lus dans l'ordre
     * @param  list<string>  $admis  matricules qui reçoivent l'inscription de l'année
     * @return array{lus: int, crees: int, inscrits: int, deja: list<string>, sans_telephone: list<string>, compte_occupe: list<string>, admis_introuvables: list<string>, erreurs: array<string, string>}
     */
    public function importer(array $sources, array $admis, string $annee, string $niveau, User $auteur, bool $appliquer): array
    {
        $lignes = $this->lire($sources);
        $admis = array_values(array_unique(array_map(fn (string $m): string => strtoupper(trim($m)), $admis)));
        $bilan = ['lus' => count($lignes), 'crees' => 0, 'inscrits' => 0, 'deja' => [], 'sans_telephone' => [], 'compte_occupe' => [], 'telephone_en_double' => [], 'autre_personne' => [], 'date_illisible' => [], 'admis_introuvables' => [], 'erreurs' => []];
        $telephones = [];
        $bilan['admis_introuvables'] = array_values(array_diff($admis, array_keys($lignes)));

        $bareme = BaremeScolarite::query()->where('annee_academique', $annee)->where('niveau', $niveau)->first();
        if ($bareme === null && $admis !== []) {
            throw new RuntimeException("Aucun barème {$niveau} pour {$annee} : saisissez-le avant l'import.");
        }

        foreach ($lignes as $matricule => $l) {
            if (Candidature::withTrashed()->where('matricule', $matricule)->exists()) {
                $bilan['deja'][] = $matricule;

                continue;
            }
            // Contrôlée dès l'essai à blanc : la date de naissance est obligatoire.
            if (self::date($l['date_naissance'] ?? '') === null) {
                $bilan['date_illisible'][] = $matricule;

                continue;
            }
            $telephone = self::telephone($l['telephone1'] ?? '', $l['pays'] ?? '') ?? self::telephone($l['telephone2'] ?? '', $l['pays'] ?? '');
            if ($telephone === null) {
                $bilan['sans_telephone'][] = $matricule;

                continue;
            }
            // Un téléphone par dossier dans une campagne : le second est écarté, à corriger à la main.
            if (isset($telephones[$telephone])) {
                $bilan['telephone_en_double'][] = $matricule;

                continue;
            }
            $telephones[$telephone] = true;
            $compte = User::query()->with('roles')->where('phone_e164', $telephone)->first();
            if ($compte !== null && $compte->roles->pluck('name')->all() !== ['candidat']) {
                $bilan['compte_occupe'][] = $matricule;

                continue;
            }
            // Rattacher le dossier au compte d'une autre personne lui ouvrirait un espace qui n'est pas le sien.
            if ($compte !== null && $compte->date_naissance !== null && $compte->date_naissance->toDateString() !== self::date($l['date_naissance'] ?? '')) {
                $bilan['autre_personne'][] = $matricule;

                continue;
            }
            if (! $appliquer) {
                $bilan['crees']++;
                $bilan['inscrits'] += in_array($matricule, $admis, true) ? 1 : 0;

                continue;
            }

            try {
                $inscrit = $this->db->transaction(fn (): bool => $this->creer($matricule, $l, $telephone, $compte, in_array($matricule, $admis, true) ? $bareme : null, $auteur));
                $bilan['crees']++;
                $bilan['inscrits'] += $inscrit ? 1 : 0;
            } catch (Throwable $e) {
                $bilan['erreurs'][$matricule] = Str::limit($e->getMessage(), 160);
            }
        }

        if ($appliquer) {
            activity('scolarite')->causedBy($auteur)->event('import_auditeurs')
                ->withProperties(['annee' => $annee, 'niveau' => $niveau] + array_map(fn ($v) => is_array($v) ? count($v) : $v, $bilan))
                ->log('Import des auditeurs des promotions antérieures');
        }

        return $bilan;
    }

    /** @param  array<string, string>  $l */
    private function creer(string $matricule, array $l, string $telephone, ?User $compte, ?BaremeScolarite $bareme, User $auteur): bool
    {
        $campagne = $this->campagne($matricule);
        $pays = $this->codePays($l['pays'] ?? '') ?? (str_starts_with($telephone, '+237') ? 'CM' : 'CM');
        $naissance = self::date($l['date_naissance'] ?? '');
        if ($naissance === null) {
            throw new RuntimeException('date de naissance illisible');
        }
        $compte ??= $this->comptes->createCandidat($telephone, Str::random(40), [
            'prenom' => trim($l['prenom'] ?? '') ?: '—',
            'nom' => trim($l['nom'] ?? ''),
            'phone_country' => $pays,
            'date_naissance' => $naissance,
        ]);

        $dossier = new Candidature(array_filter([
            'campagne_id' => $campagne->id,
            'user_id' => $compte->id,
            'phone_e164' => $telephone,
            'phone_country' => $pays,
            'email' => self::email($l['email'] ?? ''),
            'civilite' => self::civilite($l['civilite'] ?? ''),
            'genre' => match (mb_strtoupper(trim($l['sexe'] ?? ''))) {
                'H', 'M' => 'M', 'F' => 'F', default => null
            },
            'nom' => mb_strtoupper(trim($l['nom'] ?? '')),
            'prenom' => trim($l['prenom'] ?? '') ?: '—',
            'date_naissance' => $naissance,
            'lieu_naissance' => self::texte($l['lieu_naissance'] ?? '', 100),
            'statut_matrimonial' => self::texte($l['statut_matrimonial'] ?? '', 20),
            'nationalite' => $this->codeNationalite($l['nationalite'] ?? '') ?? $pays,
            'pays_origine' => $pays,
            'pays_residence' => $pays,
            'region' => $this->codeRegion($l['region'] ?? ''),
            'adresse' => self::texte($l['adresse'] ?? '', 200),
            'ville_residence' => self::texte($l['ville_residence'] ?? '', 100),
            'telephone2' => ($t2 = self::telephone($l['telephone2'] ?? '', $l['pays'] ?? '')) !== $telephone ? $t2 : null,
            'specialite' => self::specialite($l['specialite'] ?? ''),
            'type_etude' => str_contains(Str::ascii(mb_strtolower($l['type_etude'] ?? '')), 'distanc') ? 'distanciel' : 'presentiel',
            'premiere_langue' => str_contains(Str::ascii(mb_strtolower($l['langue'] ?? '')), 'angl') ? 'en' : 'fr',
            'diplome_obtenu' => self::texte($l['diplome_obtenu'] ?? '', 100),
            'annee_diplome' => ctype_digit(trim($l['annee_diplome'] ?? '')) ? (int) $l['annee_diplome'] : null,
            'diplome_requis' => self::diplomeRequis($l['diplome_requis'] ?? ''),
            'specialite_diplome_requis' => self::texte($l['specialite_diplome_requis'] ?? '', 100),
            'statut_actuel' => self::statutActuel($l['statut_actuel'] ?? ''),
            'employeur' => self::texte($l['employeur'] ?? '', 150),
            'adresse_employeur' => self::texte($l['adresse_employeur'] ?? '', 200),
            'statut' => Candidature::STATUT_ACCEPTE,
            'decided_at' => now(),
            'frais_paye' => false,
        ], fn ($v) => $v !== null && $v !== ''));

        preg_match('/^\d{2}(EFSC|EFP)(\d+)$/', $matricule, $m);
        $rang = isset($m[2]) ? (int) $m[2] : null;
        $rangLibre = $rang !== null && ! Candidature::withTrashed()->where('campagne_id', $campagne->id)->where('rang_retenu', $rang)->exists();
        $dossier->forceFill([
            'matricule' => $matricule,
            'categorie_matricule' => ($m[1] ?? 'EFP') === 'EFSC' ? Candidature::CATEGORIE_EFSC : Candidature::CATEGORIE_EFP,
            'rang_retenu' => $rangLibre ? $rang : null,
            'matricule_attribue_at' => now(),
            // Hors PV et hors statistiques, comme un retenu ajouté depuis l'admin.
            'ajoute_par' => $auteur->id,
        ])->save();

        $auditeur = Auditeur::query()->create(['candidature_id' => $dossier->id]);
        if ($bareme === null) {
            return false;
        }

        $inscription = InscriptionAnnuelle::query()->create([
            'auditeur_id' => $auditeur->id,
            'bareme_id' => $bareme->id,
            'annee_academique' => $bareme->annee_academique,
            'niveau' => $bareme->niveau,
            'montant_inscription' => $bareme->montant_inscription,
            'montant_tranche1' => $bareme->montant_tranche1,
            'montant_tranche2' => $bareme->montant_tranche2,
        ]);
        // Ils ont déjà leur compte eCampus : aucun SMS « accès disponibles ».
        $inscription->forceFill(['acces_notifie_at' => now()])->save();

        return true;
    }

    /** Campagne close de la promotion du matricule (25EFP… → 13e promotion, 2025-2026). */
    private function campagne(string $matricule): CampagneCandidature
    {
        $an = 2000 + (int) substr($matricule, 0, 2);
        $promotion = $an - 2012;

        return CampagneCandidature::query()->where('promotion_numero', $promotion)->orderBy('id')->first()
            ?? CampagneCandidature::query()->create([
                'slug' => "p{$promotion}-{$an}",
                'nom' => ['fr' => "Année académique {$an}-".($an + 1)],
                'promotion_numero' => $promotion,
                'prefix_numero' => sprintf('P%02d%03d-', $promotion, $an % 1000),
                'status' => 'closed',
                'opens_at' => CarbonImmutable::create($an, 6, 1),
                'closes_at' => CarbonImmutable::create($an, 9, 15),
                'max_voeux' => 1,
            ]);
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, array<string, string>> matricule => champs ; la première source l'emporte
     */
    private function lire(array $sources): array
    {
        $lignes = [];
        foreach ($sources as $fichier) {
            $classeur = IOFactory::load($fichier);
            foreach ($classeur->getWorksheetIterator() as $feuille) {
                $rows = $feuille->toArray(null, false, false, false);
                $entete = null;
                foreach ($rows as $i => $r) {
                    $noms = array_map(fn ($v) => self::cle((string) $v), $r);
                    if (in_array('matricule (genere)', $noms, true) || (in_array('matricule', $noms, true) && in_array('nom', $noms, true))) {
                        $entete = $i;
                        break;
                    }
                }
                if ($entete === null) {
                    continue;
                }
                $index = $this->index(array_map(fn ($v) => self::cle((string) $v), $rows[$entete]));
                foreach (array_slice($rows, $entete + 1) as $r) {
                    $matricule = strtoupper(trim((string) ($r[$index['matricule']] ?? '')));
                    if (preg_match('/^\d{2}(EFP|EFSC)\d+$/', $matricule) !== 1 || isset($lignes[$matricule])) {
                        continue;
                    }
                    $lignes[$matricule] = array_map(fn (int $c): string => trim((string) ($r[$c] ?? '')), $index);
                }
            }
        }

        return $lignes;
    }

    /**
     * @param  list<string>  $entete
     * @return array<string, int>
     */
    private function index(array $entete): array
    {
        $index = [];
        foreach (self::COLONNES as $champ => $noms) {
            foreach ($noms as $nom) {
                $i = array_search($nom, $entete, true);
                if ($i !== false) {
                    $index[$champ] = $i;
                    break;
                }
            }
        }

        return $index;
    }

    /**
     * Matricules d'une liste d'admis : texte (un par ligne) ou tableau Word
     * (colonne « Matricule »).
     *
     * @return list<string>
     */
    public static function listeAdmis(string $fichier): array
    {
        if (! str_ends_with(strtolower($fichier), '.docx')) {
            return array_values(array_filter(array_map(fn ($l) => strtoupper(trim(explode("\t", $l)[0])), file($fichier) ?: [])));
        }
        $zip = new ZipArchive;
        if ($zip->open($fichier) !== true) {
            throw new RuntimeException('Liste illisible : '.$fichier);
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $xml, $m);

        return array_values(array_unique(array_filter(
            array_map(fn ($t) => strtoupper(trim(html_entity_decode($t))), $m[1]),
            fn ($t) => preg_match('/^\d{2}(EFP|EFSC)\d+$/', $t) === 1,
        )));
    }

    public static function telephone(string $saisie, string $pays): ?string
    {
        $chiffres = (string) preg_replace('/\D+/', '', $saisie);
        if ($chiffres === '') {
            return null;
        }
        $estCameroun = in_array(self::cle($pays), ['', 'cameroun', 'cameroon'], true);
        if (strlen($chiffres) === 9 && $estCameroun && in_array($chiffres[0], ['6', '2'], true)) {
            return '+237'.$chiffres;
        }
        if (strlen($chiffres) >= 11 && strlen($chiffres) <= 13) {
            return '+'.ltrim($chiffres, '0');
        }

        return null;
    }

    public static function date(string $valeur): ?string
    {
        $valeur = trim($valeur);
        try {
            if (is_numeric($valeur) && (float) $valeur > 10000) {
                return DateExcel::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $valeur) === 1) {
                return substr($valeur, 0, 10);
            }
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $valeur, $m) === 1) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function specialite(string $valeur): ?string
    {
        $v = self::cle($valeur);
        foreach ((array) config('specialites', []) as $libelle) {
            if (self::cle((string) $libelle) === $v) {
                return (string) $libelle;
            }
        }
        $motsCles = ['fiscalit' => 'fiscalit', 'march' => 'march', 'audit' => 'audit', 'gouvernance' => 'gouvernance', 'economie' => 'economie'];
        foreach ($motsCles as $mot) {
            if (str_contains($v, $mot)) {
                foreach ((array) config('specialites', []) as $libelle) {
                    if (str_contains(self::cle((string) $libelle), $mot)) {
                        return (string) $libelle;
                    }
                }
            }
        }

        return null;
    }

    /** Valeurs admises par la base : `licence-bachelor` ou `master`. */
    public static function diplomeRequis(string $valeur): ?string
    {
        $v = self::cle($valeur);

        return match (true) {
            $v === '' => null,
            str_contains($v, 'master') => 'master',
            str_contains($v, 'licence'), str_contains($v, 'bachelor'), str_contains($v, 'ingenieur'), str_contains($v, 'dipet') => 'licence-bachelor',
            default => null,
        };
    }

    /** Seulement une valeur de la liste fermée (config/statuts_actuels.php), libellé ou clé. */
    public static function statutActuel(string $valeur): ?string
    {
        $v = self::cle($valeur);
        foreach ((array) config('statuts_actuels', []) as $cle => $libelle) {
            if ($v !== '' && ($v === self::cle((string) $cle) || $v === self::cle((string) $libelle) || str_starts_with(self::cle((string) $libelle), $v))) {
                return (string) $cle;
            }
        }

        return null;
    }

    private static function civilite(string $valeur): ?string
    {
        return match (true) {
            str_starts_with(self::cle($valeur), 'monsieur') => 'M.',
            str_starts_with(self::cle($valeur), 'mademoiselle'), str_starts_with(self::cle($valeur), 'mlle') => 'Mlle',
            str_starts_with(self::cle($valeur), 'madame') => 'Mme',
            default => null,
        };
    }

    private static function email(string $valeur): ?string
    {
        $v = mb_strtolower(trim($valeur));

        return filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($v, 0, 150) : null;
    }

    private static function texte(string $valeur, int $max): ?string
    {
        $v = trim($valeur);

        return $v === '' || strtoupper($v) === 'NULL' || $v === '0' ? null : mb_substr($v, 0, $max);
    }

    private function codePays(string $nom): ?string
    {
        if ($this->pays === []) {
            foreach (Pays::query()->get(['code_iso', 'nom']) as $p) {
                $this->pays[self::cle($p->nom)] = $p->code_iso;
            }
        }

        return $this->pays[self::cle($nom)] ?? null;
    }

    /** « Camerounaise », « Tchadienne »… : radical du nom de pays. */
    private function codeNationalite(string $nationalite): ?string
    {
        $v = self::cle($nationalite);
        if ($v === '') {
            return null;
        }
        $this->codePays('');
        foreach ($this->pays as $nom => $code) {
            if (strlen($nom) >= 4 && str_starts_with($v, substr($nom, 0, 4))) {
                return $code;
            }
        }

        return null;
    }

    private function codeRegion(string $nom): ?string
    {
        if ($this->regions === []) {
            foreach (RegionCameroun::query()->get(['code', 'nom']) as $r) {
                $this->regions[self::cle($r->nom)] = $r->code;
            }
        }

        return $this->regions[self::cle($nom)] ?? null;
    }

    private static function cle(string $v): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower(trim($v)))));
    }
}
