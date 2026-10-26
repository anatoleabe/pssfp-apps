<?php

declare(strict_types=1);

namespace App\Services\ExportsRetenus;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use App\Services\ResultatsSelection\SpecialitesOfficielles;
use App\Support\TexteImprimable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Les trois fichiers « prêts à importer » des retenus, au format exact des
 * onglets du classeur de la 13e promotion : en-têtes en ligne 1, une ligne par
 * retenu, ni titre ni logo. Ils alimentent d'autres logiciels (ACMA, Moodle),
 * l'intitulé et l'ordre des colonnes font donc foi.
 *
 * Le texte saisi par les candidats est toujours écrit en chaîne explicite :
 * jamais interprété comme une formule (« =… »), et un téléphone garde tous
 * ses chiffres.
 */
final class ExportsRetenusExcel
{
    public const FULL_DATA = 'full_data';

    public const ACMA = 'acma';

    public const MOODLE = 'moodle';

    public const TYPES = [self::FULL_DATA, self::ACMA, self::MOODLE];

    private const ENTETES = [
        self::FULL_DATA => [
            'id', 'LETTRENUMERO', 'Matricule (Généré)', 'Nom', 'Prenom', 'Nom & Prenom', 'civilite',
            'Date de Naissance', 'nationalite', 'lieu_de_naissce', 'region_dorigine', 'sexe', 'statu_matrimonial',
            'adresse_candidat', 'Colonne 1', 'Colonne 2', 'email', 'ville_residence', 'dernier_diplome',
            'annee_optention_diplome', 'statut_prof', 'structure', 'adresse_structure', 'email_structure',
            'ordre_candidature', 'date_enregistrement', 'langue', 'Type Etude', 'dept_dorigine', 'Diplome Requis',
            'Specialité Requise', 'Spécialité Choisie', 'Pays',
        ],
        self::ACMA => [
            'Num', 'Matricule', 'NomComplet', 'Datenais', 'LieuNais', 'Sex', 'Exp', 'Parcours', 'Categorie',
            'Region', 'Session', 'Pays', 'Telephone', 'departement', 'email',
        ],
        self::MOODLE => [
            'username', 'lastname', 'firstname', 'password', 'phone1', 'phone2', 'email', 'cohort1', 'department', 'city',
        ],
    ];

    /** Formats des colonnes de date, par fichier (lettre de colonne → format Excel). */
    private const FORMATS_DATE = [
        self::FULL_DATA => ['H' => 'yyyy-mm-dd', 'Z' => 'yyyy-mm-dd hh:mm:ss'],
        self::ACMA => ['D' => 'yyyymmdd'],
        self::MOODLE => [],
    ];

    /** @var array<string, array<string, string>> */
    private array $libelles = [];

    public static function nomFeuille(string $type, CampagneCandidature $campagne): string
    {
        $promo = (int) $campagne->promotion_numero;

        return match ($type) {
            self::FULL_DATA => "PROMOTION {$promo} - Full Data",
            self::ACMA => "IMPORTATION ACMA P{$promo}",
            self::MOODLE => 'Access Plateforme Moodle',
        };
    }

    /**
     * @param  Collection<int, Candidature>  $retenus  avec matricule, triés par rang
     */
    public function generer(string $type, CampagneCandidature $campagne, Collection $retenus, string $cohorteMoodle = ''): string
    {
        $this->chargerLibelles($retenus);

        $ligne = match ($type) {
            self::FULL_DATA => fn (Candidature $c): array => $this->ligneFullData($c, $campagne),
            self::ACMA => fn (Candidature $c): array => $this->ligneAcma($c),
            self::MOODLE => fn (Candidature $c): array => $this->ligneMoodle($c, $cohorteMoodle),
        };

        return $this->classeur(self::nomFeuille($type, $campagne), self::ENTETES[$type], $retenus, $ligne, self::FORMATS_DATE[$type]);
    }

    /** @return list<mixed> */
    private function ligneFullData(Candidature $c, CampagneCandidature $campagne): array
    {
        $nom = TexteImprimable::majuscules($c->nom);
        $prenom = TexteImprimable::nettoyer($c->prenom);

        return [
            (int) $c->rang_retenu,
            'RS'.(int) $campagne->promotion_numero.str_pad((string) $c->rang_retenu, 3, '0', STR_PAD_LEFT),
            (string) $c->matricule,
            $nom,
            $prenom,
            trim($nom.' '.$prenom),
            match ($c->civilite) {
                'M.' => 'Monsieur', 'Mme' => 'Madame', 'Mlle' => 'Mademoiselle', default => ''
            },
            $this->date($c->date_naissance),
            $this->libelle('pays', $c->nationalite),
            TexteImprimable::nettoyer($c->lieu_naissance),
            $this->libelle('regions', $c->region),
            $this->sexe($c),
            TexteImprimable::nettoyer($c->statut_matrimonial),
            TexteImprimable::nettoyer($c->adresse),
            $this->telephone($c->phone_e164),
            $this->telephone(($c->indicatif2 ?? '').($c->telephone2 ?? '')),
            (string) $c->email,
            TexteImprimable::nettoyer($c->ville_residence),
            $this->libelle('diplomes', $c->diplome_obtenu, brut: true),
            $c->annee_diplome === null ? null : (int) $c->annee_diplome,
            $this->libelle('statuts', $c->statut_actuel),
            TexteImprimable::nettoyer($c->employeur),
            TexteImprimable::nettoyer($c->adresse_employeur),
            '',
            (string) $c->numero_dossier,
            $this->date($c->submitted_at),
            match ($c->premiere_langue) {
                'fr' => 'Français', 'en' => 'Anglais', default => ''
            },
            match ($c->type_etude) {
                'presentiel' => 'Presentiel', 'distanciel' => 'Distanciel', default => ''
            },
            $this->libelle('departements', $c->departement),
            $this->libelle('diplomesRequis', $c->diplome_requis),
            TexteImprimable::nettoyer($c->specialite_diplome_requis ?: $this->libelle('domaines', $c->domaine_diplome_requis)),
            $this->specialite($c),
            $this->libelle('pays', $c->pays_origine ?: $c->nationalite),
        ];
    }

    /** @return list<mixed> */
    private function ligneAcma(Candidature $c): array
    {
        $acma = (array) config('exports_retenus.acma');

        return [
            (int) $c->rang_retenu,
            (string) $c->matricule,
            trim(TexteImprimable::majuscules($c->nom).' '.TexteImprimable::nettoyer($c->prenom)),
            $this->date($c->date_naissance),
            TexteImprimable::nettoyer($c->lieu_naissance),
            $this->sexe($c),
            (string) ($acma['exp'] ?? ''),
            (string) ($acma['parcours'] ?? ''),
            (string) ($acma['categorie'] ?? ''),
            $this->libelle('regions', $c->region),
            (string) ($acma['session'] ?? ''),
            $this->libelle('pays', $c->pays_origine ?: $c->nationalite),
            $this->telephone($c->phone_e164),
            $this->specialite($c),
            (string) $c->email,
        ];
    }

    /** @return list<mixed> */
    private function ligneMoodle(Candidature $c, string $cohorte): array
    {
        return [
            mb_strtolower((string) $c->matricule),
            TexteImprimable::majuscules($c->nom),
            TexteImprimable::nettoyer($c->prenom),
            (string) $c->moodle_password,
            $this->telephone($c->phone_e164),
            $this->telephone(($c->indicatif2 ?? '').($c->telephone2 ?? '')),
            (string) $c->email,
            $cohorte,
            (string) config('exports_retenus.moodle.department', 'PSSFP'),
            TexteImprimable::nettoyer($c->ville_residence),
        ];
    }

    /**
     * @param  list<string>  $entetes
     * @param  Collection<int, Candidature>  $retenus
     * @param  Closure(Candidature): list<mixed>  $ligne
     * @param  array<string, string>  $formatsDate
     */
    private function classeur(string $titre, array $entetes, Collection $retenus, Closure $ligne, array $formatsDate): string
    {
        $classeur = new Spreadsheet;
        $classeur->getProperties()->setCreator('PSSFP')->setTitle($titre);
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(mb_substr($titre, 0, 31));

        foreach ($entetes as $i => $entete) {
            $feuille->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).'1', $entete, DataType::TYPE_STRING);
        }

        foreach ($retenus->values() as $r => $candidature) {
            foreach ($ligne($candidature) as $i => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($i + 1).($r + 2);
                match (true) {
                    $valeur === null => null,
                    is_string($valeur) => $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING),
                    default => $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_NUMERIC),
                };
            }
        }

        $derniere = max(2, $retenus->count() + 1);
        foreach ($formatsDate as $colonne => $format) {
            $feuille->getStyle("{$colonne}2:{$colonne}{$derniere}")->getNumberFormat()->setFormatCode($format);
        }
        foreach (range(1, count($entetes)) as $i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $feuille->freezePane('A2');

        $flux = fopen('php://temp', 'r+');
        (new Xlsx($classeur))->save($flux);
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);
        $classeur->disconnectWorksheets();

        return $contenu;
    }

    /** @param  Collection<int, Candidature>  $retenus */
    private function chargerLibelles(Collection $retenus): void
    {
        $codesPays = $retenus->flatMap(fn (Candidature $c): array => [$c->nationalite, $c->pays_origine])->filter()->unique()->all();

        $this->libelles = [
            'pays' => Pays::query()->whereIn('code_iso', $codesPays)->pluck('nom', 'code_iso')->all(),
            'regions' => RegionCameroun::query()->pluck('nom', 'code')->all(),
            'departements' => DepartementCameroun::query()->pluck('nom', 'code')->all(),
            'diplomes' => (array) config('diplomes', []),
            'diplomesRequis' => (array) config('diplome_requis', []),
            'domaines' => (array) config('domaines_diplome', []),
            'statuts' => (array) config('statuts_actuels', []),
        ];
    }

    /** Libellé d'un code ; `brut` renvoie la saisie libre quand le code est inconnu. */
    private function libelle(string $table, ?string $code, bool $brut = false): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $libelle = $this->libelles[$table][$code] ?? null;

        return TexteImprimable::nettoyer(is_string($libelle) ? $libelle : ($brut ? $code : ''));
    }

    private function sexe(Candidature $c): string
    {
        return match ($c->genre) {
            'M' => 'H', 'F' => 'F', default => ''
        };
    }

    private function specialite(Candidature $c): string
    {
        return $c->specialite ? SpecialitesOfficielles::libelle($c->specialite) : '';
    }

    /** « +237 699 00 00 10 » → « 237699000010 » : chiffres seuls, en texte. */
    private function telephone(?string $numero): string
    {
        return (string) preg_replace('/\D+/', '', (string) $numero);
    }

    private function date(?CarbonInterface $date): ?float
    {
        return $date === null ? null : (float) Date::PHPToExcel($date->copy()->setTimezone(config('app.timezone'))->toDateTime());
    }
}
