<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Journaux d'activité (spatie/laravel-activitylog) : un journal par domaine
 * métier, source unique des noms, des libellés et des domaines du filtre
 * du Journal d'activité (chantier « Socle », étape 5).
 *
 * Convention (cf. backend/docs/journaux.md) :
 * - un modèle métier journalisé déclare son journal :
 *   `LogOptions::defaults()->useLogName(Journaux::PAIE)` ;
 * - une action de service écrit `activity(Journaux::PAIE)->event('…')`, avec
 *   un événement en snake_case français et l'avant/après en propriétés ;
 * - aucun nouvel écrit dans `default`.
 *
 * L'historique n'est jamais réécrit : les anciens noms (`documents`,
 * `default`, la paie dans `lmd` avant le 10/11/2026) restent rattachés à
 * leur domaine par DOMAINES.
 */
final class Journaux
{
    public const CANDIDATURES = 'candidatures';

    public const CONNEXIONS_CANDIDATS = 'auth.candidat';

    public const ENVOIS = 'envois';

    public const SCOLARITE = 'scolarite';

    public const LMD = 'lmd';

    public const PAIE = 'paie';

    public const BIBLIOTHEQUE = 'bibliotheque';

    public const COMPTES = 'comptes';

    public const ROLES = 'roles';

    public const CONNEXIONS = 'connexions';

    public const ADMIN = 'admin';

    public const PARAMETRES = 'parametres';

    public const SUPERVISION = 'supervision';

    /** Nom de journal → libellé affiché dans la colonne « Journal ». */
    public const LIBELLES = [
        self::CANDIDATURES => 'Candidatures',
        self::CONNEXIONS_CANDIDATS => 'Connexions des candidats',
        self::ENVOIS => 'Envois SMS / e-mail',
        self::SCOLARITE => 'Scolarité',
        self::LMD => 'Parcours LMD',
        self::PAIE => 'Enseignants et paie',
        self::BIBLIOTHEQUE => 'Bibliothèque',
        'documents' => 'Bibliothèque',
        self::COMPTES => 'Comptes du personnel',
        self::ROLES => 'Rôles',
        self::CONNEXIONS => 'Connexions du personnel',
        self::ADMIN => 'Modifications back-office',
        self::PARAMETRES => 'Paramètres',
        self::SUPERVISION => 'Contrôle interne',
        'default' => 'Comptes et bibliothèque (avant le 10/11/2026)',
    ];

    /** Domaine du filtre → journaux qui le composent, anciens noms compris. */
    public const DOMAINES = [
        'Admissions' => [self::CANDIDATURES, self::CONNEXIONS_CANDIDATS, self::ENVOIS],
        'Scolarité' => [self::SCOLARITE],
        'Parcours LMD' => [self::LMD],
        'Enseignants et paie' => [self::PAIE],
        'Bibliothèque' => [self::BIBLIOTHEQUE, 'documents'],
        'Comptes, rôles et connexions' => [self::COMPTES, self::ROLES, self::CONNEXIONS],
        'Administration' => [self::ADMIN, self::PARAMETRES, self::SUPERVISION],
        'Historique non classé (avant le 10/11/2026)' => ['default'],
    ];

    public static function libelle(?string $journal): string
    {
        return self::LIBELLES[$journal ?? 'default'] ?? (string) $journal;
    }
}
