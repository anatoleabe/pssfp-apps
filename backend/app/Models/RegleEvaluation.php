<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Règles d'évaluation d'une année académique (D1, D2, D10, D14 à D16, V-02 à V-09).
 * Chaque modification est journalisée avec l'ancienne et la nouvelle valeur (ENF-15).
 */
class RegleEvaluation extends Model
{
    use HasFactory;
    use LogsActivity;

    public const RATTRAPAGE_REMPLACE_EXAMEN = 'remplace_examen';

    public const RATTRAPAGE_REMPLACE_MOYENNE = 'remplace_moyenne';

    public const RATTRAPAGE_MEILLEURE_NOTE = 'meilleure_note';

    public const MODES_RATTRAPAGE = [
        self::RATTRAPAGE_REMPLACE_MOYENNE => 'La note de rattrapage remplace la moyenne de l\'UE (CC, assiduité et examen)',
        self::RATTRAPAGE_REMPLACE_EXAMEN => 'La note de rattrapage remplace l\'examen (CC et assiduité conservés)',
        self::RATTRAPAGE_MEILLEURE_NOTE => 'La meilleure des deux notes d\'examen est retenue',
    ];

    public const PLANCHER_EXAMEN_UE = 'examen_ue';

    public const PLANCHER_EXAMEN_ECUE = 'examen_ecue';

    public const PORTEES_PLANCHER = [
        self::PLANCHER_EXAMEN_UE => 'Examen de l\'UE (moyenne des examens de ses ECUE)',
        self::PLANCHER_EXAMEN_ECUE => 'Examen de chaque ECUE',
    ];

    public const NOTE_UE_MOYENNE_ECUE_CREDITS = 'moyenne_ecue_credits';

    public const CALCULS_NOTE_UE = [
        self::NOTE_UE_MOYENNE_ECUE_CREDITS => 'Moyenne des ECUE pondérée par leurs crédits',
    ];

    /** Grille nationale des grades et des points (D1) ; `validee` porte la règle PSSFP (au moins 12/20). */
    public const GRILLE_GRADES = [
        ['min' => 0, 'max' => 6, 'grade' => 'F', 'appreciation' => 'Échec', 'points' => 0],
        ['min' => 6, 'max' => 7, 'grade' => 'E', 'appreciation' => 'Échec', 'points' => 0],
        ['min' => 7, 'max' => 8, 'grade' => 'D', 'appreciation' => 'Insuffisant', 'points' => 1.0],
        ['min' => 8, 'max' => 9, 'grade' => 'D+', 'appreciation' => 'Insuffisant', 'points' => 1.3],
        ['min' => 9, 'max' => 10, 'grade' => 'C-', 'appreciation' => 'Insuffisant', 'points' => 1.7],
        ['min' => 10, 'max' => 11, 'grade' => 'C', 'appreciation' => 'Passable', 'points' => 2.0],
        ['min' => 11, 'max' => 12, 'grade' => 'C+', 'appreciation' => 'Passable', 'points' => 2.3],
        ['min' => 12, 'max' => 13, 'grade' => 'B-', 'appreciation' => 'Assez bien', 'points' => 2.7],
        ['min' => 13, 'max' => 14, 'grade' => 'B', 'appreciation' => 'Assez bien', 'points' => 3.0],
        ['min' => 14, 'max' => 15, 'grade' => 'B+', 'appreciation' => 'Bien', 'points' => 3.3],
        ['min' => 15, 'max' => 16, 'grade' => 'A-', 'appreciation' => 'Bien', 'points' => 3.7],
        ['min' => 16, 'max' => 20.01, 'grade' => 'A', 'appreciation' => 'Très bien', 'points' => 4.0],
    ];

    /** Mentions du diplôme par la moyenne du cycle (D15) ; félicitations proposées, confirmées par le jury. */
    public const GRILLE_MENTIONS = [
        ['min' => 12, 'mention' => 'Assez bien'],
        ['min' => 14, 'mention' => 'Bien'],
        ['min' => 16, 'mention' => 'Très bien'],
        ['min' => 18, 'mention' => 'Très bien avec félicitations du jury'],
    ];

    /** Paramètres du jury proposés par le registre V-02 à V-09, à valider par la direction. */
    public const JURY = [
        'quorum_mode' => 'pourcentage',
        'quorum_pct' => 50,
        'signataires' => 'tous_presents',
        'relevement_ecart_max' => 0.5,
        'repechage_ecart_max' => 1,
        'note_eliminatoire_opposable' => true,
        'rectification_defavorable' => 'dans_delai',
        'rectification_delai_jours' => 120,
        'delai_requetes_jours' => 10,
        'delai_publication_max_jours' => 21,
    ];

    protected $table = 'regles_evaluation';

    protected $fillable = [
        'annee_academique',
        'seuil_validation',
        'note_plancher',
        'note_plancher_porte',
        'mode_rattrapage',
        'note_ue',
        'pcc_defaut',
        'ptp_defaut',
        'pexam_defaut',
        'credits_niveau',
        'credits_ecrits_terminal',
        'grille_grades',
        'grille_mentions',
        'jury',
    ];

    protected $casts = [
        'seuil_validation' => 'decimal:2',
        'note_plancher' => 'decimal:2',
        'pcc_defaut' => 'decimal:4',
        'ptp_defaut' => 'decimal:4',
        'pexam_defaut' => 'decimal:4',
        'credits_niveau' => 'integer',
        'credits_ecrits_terminal' => 'integer',
        'grille_grades' => 'array',
        'grille_mentions' => 'array',
        'jury' => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * Valeurs par défaut d'une année. Rattrapage : instruction du Chef USI du
     * 06/11/2026 (remplace la moyenne) ; D10 retenait le remplacement de
     * l'examen seul. C'est un paramètre de l'année, à confirmer.
     *
     * @return array<string, mixed>
     */
    public static function valeursParDefaut(string $annee): array
    {
        return [
            'annee_academique' => $annee,
            'seuil_validation' => '12.00',
            'note_plancher' => '7.00',
            'note_plancher_porte' => self::PLANCHER_EXAMEN_UE,
            'mode_rattrapage' => self::RATTRAPAGE_REMPLACE_MOYENNE,
            'note_ue' => self::NOTE_UE_MOYENNE_ECUE_CREDITS,
            'pcc_defaut' => '0.3000',
            'ptp_defaut' => '0.1500',
            'pexam_defaut' => '0.5500',
            'credits_niveau' => 60,
            'credits_ecrits_terminal' => 44,
            'grille_grades' => self::GRILLE_GRADES,
            'grille_mentions' => self::GRILLE_MENTIONS,
            'jury' => self::JURY,
        ];
    }

    /** Règles de l'année, créées avec les valeurs par défaut si elles n'existent pas. */
    public static function pourAnnee(string $annee): self
    {
        return self::query()->firstOrCreate(['annee_academique' => $annee], self::valeursParDefaut($annee));
    }

    /** @return array{grade: string, appreciation: string, points: float}|null */
    public function grade(float $note): ?array
    {
        foreach ($this->grille_grades as $ligne) {
            if ($note >= (float) $ligne['min'] && $note < (float) $ligne['max']) {
                return ['grade' => (string) $ligne['grade'], 'appreciation' => (string) $ligne['appreciation'], 'points' => (float) $ligne['points']];
            }
        }

        return null;
    }
}
