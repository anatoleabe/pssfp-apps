<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Rapports de la reprise, dans le stockage privé (`local`, dossier `acma/`) :
 * - `controles-AAAAMMJJ.md` : contrôles et comptes, sans donnée nominative (joint aux PR et à la décision de gel) ;
 * - `rapprochement-AAAAMMJJ.xlsx` : listes nominatives à arbitrer (§6.4), jamais affichées ni journalisées.
 */
final class RapportsReprise
{
    public const DISQUE = 'local';

    public const DOSSIER = 'acma';

    /** Feuilles du classeur des cas à arbitrer : liste du contexte → titre. */
    private const FEUILLES = [
        'a_arbitrer' => 'À arbitrer (R3, R4, R6)',
        'absents_acma' => 'Absents d\'ACMA (R5)',
        'exclusions' => 'Fiches exclues',
        'fusions' => 'Fusions',
        'collisions' => 'Collisions sans zéros',
        'actifs_hors_plateforme' => 'Actifs 2026-27 hors plateforme',
    ];

    /**
     * @param  list<ResultatControle>  $controles
     * @param  array<string, mixed>  $comptes
     * @param  array<string, string|null>  $entete
     */
    public function ecrireControles(array $controles, array $comptes, array $entete, CarbonInterface $quand): string
    {
        $lignes = ['# Contrôles de la reprise d\'ACMA', ''];
        foreach ($entete as $cle => $valeur) {
            $lignes[] = "- **{$cle}** : ".($valeur ?? '—');
        }
        $lignes[] = '';
        $lignes[] = 'Aucune donnée nominative : comptes et codes de référentiel seulement.';
        $lignes[] = '';
        $lignes[] = '| Contrôle | Libellé | Nature | Attendu | Obtenu | Statut |';
        $lignes[] = '|---|---|---|---:|---:|---|';
        foreach ($controles as $c) {
            $lignes[] = "| {$c->code} | {$c->libelle} | ".($c->bloquant ? 'bloquant' : 'informatif')." | {$c->attendu} | {$c->obtenu} | {$c->statut()} |";
        }
        foreach ($controles as $c) {
            if ($c->details !== []) {
                $lignes[] = '';
                $lignes[] = "## {$c->code} — {$c->libelle}";
                $lignes[] = '';
                foreach ($c->details as $d) {
                    $lignes[] = "- {$d}";
                }
            }
        }
        $lignes[] = '';
        $lignes[] = '## Comptes par étape';
        $lignes[] = '';
        $lignes[] = '```json';
        $lignes[] = json_encode($comptes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lignes[] = '```';

        $chemin = self::DOSSIER.'/controles-'.$quand->format('Ymd').'.md';
        Storage::disk(self::DISQUE)->put($chemin, implode("\n", $lignes)."\n", 'private');

        return $chemin;
    }

    /** @param  array<string, list<array<string, mixed>>>  $listes */
    public function ecrireClasseur(array $listes, CarbonInterface $quand): string
    {
        $classeur = new Spreadsheet;
        $classeur->removeSheetByIndex(0);
        foreach (self::FEUILLES as $cle => $titre) {
            $feuille = $classeur->createSheet();
            $feuille->setTitle(mb_substr($titre, 0, 31));
            $lignes = $listes[$cle] ?? [];
            $entetes = $lignes === [] ? ['Aucune ligne'] : array_keys($lignes[0]);
            foreach ($entetes as $c => $entete) {
                $feuille->setCellValueExplicit([$c + 1, 1], (string) $entete, DataType::TYPE_STRING);
            }
            foreach ($lignes as $r => $ligne) {
                foreach (array_values($ligne) as $c => $valeur) {
                    // Texte écrit comme du texte : jamais interprété comme une formule.
                    $feuille->setCellValueExplicit([$c + 1, $r + 2], $valeur === null ? '' : (string) $valeur, DataType::TYPE_STRING);
                }
            }
            $feuille->getStyle('A1:Z1')->getFont()->setBold(true);
        }
        $classeur->setActiveSheetIndex(0);
        ob_start();
        (new Xlsx($classeur))->save('php://output');
        $contenu = (string) ob_get_clean();

        $chemin = self::DOSSIER.'/rapprochement-'.$quand->format('Ymd').'.xlsx';
        Storage::disk(self::DISQUE)->put($chemin, $contenu, 'private');

        return $chemin;
    }
}
