<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\RecuVersement;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exports Excel de l'espace Finances (spec §6.2 et §6.3) : liste des versements et registre des reçus. Les montants
 * sont des nombres (les financiers refont leurs calculs) ; les textes sont écrits comme du texte, jamais interprétés
 * comme une formule. Les dates sont à l'heure de Yaoundé.
 */
final class VersementsExcel
{
    public const ENTETES_VERSEMENTS = ['Matricule', 'Nom', 'Prénom', 'Année', 'Niveau', 'Date du versement', 'Enregistré le', 'Montant', 'Mode',
        'Référence', 'Payeur', 'Origine', 'Saisi par', 'Reçu', 'Statut', 'Confirmé le', 'Annulé le', 'Motif de l\'annulation'];

    public const ENTETES_RECUS = ['Numéro', 'Émis le', 'Matricule', 'Auditeur', 'Année académique', 'Niveau', 'Montant', 'Mode', 'Référence',
        'Date du versement', 'Payeur', 'Objet', 'Émis par', 'État', 'Écriture d\'annulation', 'Annulé le', 'Motif de l\'annulation'];

    private const FUSEAU = 'Africa/Douala';

    /** Lignes exportées au plus en une fois (plusieurs années de versements) : au-delà, l'agent affine ses filtres. */
    public const EXPORT_MAX = 20_000;

    /** Lecture par morceaux : la mémoire ne dépend pas du nombre de lignes. */
    public const MORCEAU = 500;

    public const RELATIONS_VERSEMENTS = ['auditeur.candidature:id,nom,prenom,matricule', 'inscription:id,annee_academique,niveau', 'saisiPar:id,name', 'recus.annulation', 'confirmation'];

    /**
     * Versements d'une requête, lus par morceaux, ou null si elle dépasse EXPORT_MAX.
     *
     * @param  Builder<Versement>  $requete
     * @return iterable<Versement>|null
     */
    public static function lireVersements(Builder $requete): ?iterable
    {
        return (clone $requete)->count() > self::EXPORT_MAX ? null : $requete->with(self::RELATIONS_VERSEMENTS)->lazy(self::MORCEAU);
    }

    /**
     * Reçus d'une requête, lus par morceaux dans l'ordre des numéros, ou null si elle dépasse EXPORT_MAX.
     *
     * @param  Builder<RecuVersement>  $requete
     * @return iterable<RecuVersement>|null
     */
    public static function lireRecus(Builder $requete): ?iterable
    {
        return (clone $requete)->count() > self::EXPORT_MAX ? null
            : $requete->reorder()->orderBy('annee_numero')->orderBy('sequence')->with('annulation')->lazy(self::MORCEAU);
    }

    /** @param  iterable<Versement>  $versements  une collection, ou une lecture par morceaux (`lireVersements`) */
    public function generer(iterable $versements): string
    {
        if ($versements instanceof Collection) {
            $versements->loadMissing(self::RELATIONS_VERSEMENTS);
        }

        return $this->classeur('Versements', self::ENTETES_VERSEMENTS, ['Montant'], self::transformer($versements, fn (Versement $v): array => [
            $v->auditeur?->candidature?->matricule,
            $v->auditeur?->candidature?->nom,
            $v->auditeur?->candidature?->prenom,
            $v->inscription?->annee_academique,
            $v->inscription?->niveau,
            $v->date_versement?->format('d/m/Y'),
            $v->created_at?->setTimezone(self::FUSEAU)->format('d/m/Y H:i'),
            (int) $v->montant,
            Versement::libelleMode($v->mode),
            $v->reference,
            Versement::PAYEURS[$v->payeur] ?? $v->payeur,
            self::origine($v),
            $v->saisiPar?->name,
            $v->recuEnVigueur()?->numero,
            Versement::STATUTS[self::statut($v)],
            $v->confirmation?->confirme_at?->setTimezone(self::FUSEAU)->format('d/m/Y'),
            $v->annule_at?->setTimezone(self::FUSEAU)->format('d/m/Y H:i'),
            $v->annule_motif,
        ]));
    }

    /** @param  iterable<RecuVersement>  $recus  une collection, ou une lecture par morceaux (`lireRecus`) */
    public function registre(iterable $recus): string
    {
        if ($recus instanceof Collection) {
            $recus->loadMissing('annulation');
        }

        return $this->classeur('Registre des reçus', self::ENTETES_RECUS, ['Montant'], self::transformer($recus, fn (RecuVersement $r): array => [
            $r->numero,
            $r->emis_at?->setTimezone(self::FUSEAU)->format('d/m/Y H:i'),
            $r->matricule,
            $r->auditeur_nom,
            $r->annee_academique,
            $r->niveau,
            (int) $r->montant,
            Versement::libelleMode($r->mode),
            $r->reference,
            $r->date_versement?->format('d/m/Y'),
            Versement::PAYEURS[$r->payeur] ?? $r->payeur,
            $r->objet,
            $r->emetteur_nom,
            $r->annulation === null ? 'En vigueur' : 'Annulé',
            $r->annulation?->numero,
            $r->annulation?->annule_at?->setTimezone(self::FUSEAU)->format('d/m/Y H:i'),
            $r->annulation?->motif,
        ]));
    }

    /**
     * @param  iterable<mixed>  $source
     * @return \Generator<int, list<mixed>>
     */
    private static function transformer(iterable $source, \Closure $ligne): \Generator
    {
        foreach ($source as $element) {
            yield $ligne($element);
        }
    }

    public static function origine(Versement $v): string
    {
        return match (true) {
            $v->reglement_organisme_id !== null => 'Règlement d\'un organisme',
            $v->mode === Versement::MODE_REPRISE => 'Reprise du pointage',
            default => 'Saisie',
        };
    }

    /** Statut lu sur les relations déjà chargées (pas de requête par ligne). */
    public static function statut(Versement $v): string
    {
        return match (true) {
            $v->estAnnule() => Versement::STATUT_ANNULE,
            $v->confirmation !== null => Versement::STATUT_CONFIRME,
            default => Versement::STATUT_ENREGISTRE,
        };
    }

    /**
     * @param  list<string>  $entetes
     * @param  list<string>  $montants  colonnes au format monétaire
     * @param  iterable<list<mixed>>  $lignes
     */
    private function classeur(string $titre, array $entetes, array $montants, iterable $lignes): string
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle($titre);
        $derniere = Coordinate::stringFromColumnIndex(count($entetes));
        $feuille->fromArray($entetes, null, 'A1');
        $feuille->getStyle('A1:'.$derniere.'1')->getFont()->setBold(true);

        $numero = 2;
        foreach ($lignes as $ligne) {
            foreach (array_values($ligne) as $i => $valeur) {
                $cellule = Coordinate::stringFromColumnIndex($i + 1).$numero;
                if (is_int($valeur)) {
                    $feuille->setCellValue($cellule, $valeur);
                } elseif ($valeur !== null) {
                    $feuille->setCellValueExplicit($cellule, (string) $valeur, DataType::TYPE_STRING);
                }
            }
            $numero++;
        }
        foreach ($montants as $entete) {
            $colonne = Coordinate::stringFromColumnIndex((int) array_search($entete, $entetes, true) + 1);
            $feuille->getStyle($colonne.'2:'.$colonne.max(2, $numero - 1))->getNumberFormat()->setFormatCode('#,##0');
        }
        foreach (range(1, count($entetes)) as $i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        ob_start();
        (new Xlsx($classeur))->save('php://output');

        return (string) ob_get_clean();
    }
}
