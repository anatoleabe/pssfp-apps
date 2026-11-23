<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\EtapeSaisie;
use App\Models\NoteEcue;
use App\Models\User;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Modèle Excel de saisie d'une étape et import du modèle rempli (spec lot 5, § 3.10).
 * Clé : le matricule. Colonne « Nouvelle note » vide = note inchangée.
 * L'import passe par SaisieNotes : mêmes contrôles qu'à l'écran.
 */
final class NotesExcel
{
    private const LIGNE_ENTETE = 4;

    private const ENTETES = ['Matricule', 'Nom et prénom', 'Note actuelle', 'Nouvelle note', 'Présence (examen)', 'Motif (si la note change)'];

    public function __construct(private readonly SaisieNotes $saisie) {}

    public function modele(EtapeSaisie $etape): string
    {
        $etape->loadMissing('ecue.maquetteUe.maquette.parcours');
        $ecue = $etape->ecue;
        $notes = NoteEcue::query()->where('maquette_ecue_id', $ecue->id)->where('session', $etape->session)->get()->keyBy('inscription_pedagogique_id');

        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Notes');
        $feuille->setCellValue('A1', $ecue->code.' — '.$ecue->intitule);
        $feuille->setCellValue('A2', sprintf('%s · %s · %s · %s', $ecue->maquetteUe->maquette->libelle(),
            EtapeSaisie::SESSIONS[$etape->session], EtapeSaisie::ETAPES[$etape->etape], 'notes sur 20, virgule ou point'));
        $feuille->fromArray(self::ENTETES, null, 'A'.self::LIGNE_ENTETE);
        $feuille->getStyle('A'.self::LIGNE_ENTETE.':F'.self::LIGNE_ENTETE)->getFont()->setBold(true);

        $ligne = self::LIGNE_ENTETE + 1;
        foreach ($this->saisie->inscrits($ecue) as $inscription) {
            $dossier = $inscription->inscriptionAnnuelle?->auditeur?->candidature;
            $note = $notes->get($inscription->id);
            $feuille->setCellValueExplicit('A'.$ligne, (string) $dossier?->matricule, DataType::TYPE_STRING);
            $feuille->setCellValueExplicit('B'.$ligne, trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom), DataType::TYPE_STRING);
            $actuelle = $note?->getAttribute($etape->etape);
            if ($actuelle !== null) {
                $feuille->setCellValue('C'.$ligne, (float) $actuelle);
            } elseif ($etape->etape === EtapeSaisie::EXAMEN && $note?->presence_examen !== null) {
                $feuille->setCellValue('C'.$ligne, NoteEcue::PRESENCES[$note->presence_examen] ?? '');
            }
            $ligne++;
        }
        foreach (range('A', 'F') as $col) {
            $feuille->getColumnDimension($col)->setAutoSize(true);
        }

        ob_start();
        (new Xlsx($classeur))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Lit un modèle rempli et le saisit (ou le contrôle seulement). Tout le fichier est contrôlé
     * avant d'écrire : une seule ligne en erreur et rien n'est enregistré (spec lot 20).
     *
     * @return int notes modifiées (à modifier en essai à blanc)
     *
     * @throws ImportRefuse lignes en erreur (matricule non inscrit ou en double, note invalide, motif manquant), avec leur numéro
     * @throws InvalidArgumentException fichier illisible, modèle modifié, étape non ouverte
     */
    public function importer(EtapeSaisie $etape, string $chemin, User $auteur, bool $appliquer): int
    {
        try {
            $feuille = IOFactory::load($chemin)->getSheet(0);
        } catch (Throwable) {
            throw new InvalidArgumentException('Fichier illisible : déposez le modèle Excel téléchargé depuis la plateforme.');
        }
        $entetes = array_map(fn ($v): string => trim((string) $v), $feuille->rangeToArray('A'.self::LIGNE_ENTETE.':F'.self::LIGNE_ENTETE)[0]);
        if ($entetes !== self::ENTETES) {
            throw new InvalidArgumentException('Ce fichier n\'est pas le modèle de saisie de la plateforme (colonnes modifiées).');
        }

        $parMatricule = $this->saisie->inscrits($etape->ecue)
            ->mapWithKeys(fn ($i): array => [mb_strtoupper((string) $i->inscriptionAnnuelle?->auditeur?->candidature?->matricule) => $i->id]);
        $presences = array_flip(array_map(fn (string $l): string => mb_strtolower($l), NoteEcue::PRESENCES));

        $lignes = [];
        $numeros = [];
        $refus = [];
        $max = $feuille->getHighestDataRow();
        for ($n = self::LIGNE_ENTETE + 1; $n <= $max; $n++) {
            [$matricule, , , $note, $presence, $motif] = $feuille->rangeToArray("A{$n}:F{$n}", null, true, false)[0];
            $matricule = mb_strtoupper(trim((string) $matricule));
            if ($matricule === '' || (trim((string) $note) === '' && trim((string) $presence) === '')) {
                continue;
            }
            $id = $parMatricule[$matricule] ?? null;
            if ($id === null) {
                $refus[] = ['ligne' => $n, 'matricule' => $matricule, 'message' => 'matricule non inscrit à cette UE'];

                continue;
            }
            if (isset($numeros[$id])) {
                $refus[] = ['ligne' => $n, 'matricule' => $matricule, 'message' => 'matricule déjà présent à la ligne '.$numeros[$id]];

                continue;
            }
            $numeros[$id] = $n;
            $libellePresence = mb_strtolower(trim((string) $presence));
            $lignes[$id] = [
                'note' => is_string($note) ? trim($note) : $note,
                'presence' => $libellePresence === '' ? null : ($presences[$libellePresence] ?? $libellePresence),
                'motif' => trim((string) $motif),
            ];
        }

        try {
            // Avec des lignes déjà refusées, les autres sont seulement contrôlées, pour un rapport complet.
            $modifiees = $this->saisie->saisir($etape, $lignes, $auteur, $appliquer && $refus === []);
        } catch (SaisieRefusee $e) {
            foreach ($e->erreurs as $erreur) {
                $refus[] = ['ligne' => $numeros[$erreur['id']] ?? 0, 'matricule' => $erreur['matricule'], 'message' => $erreur['message']];
            }
        }
        if ($refus !== []) {
            usort($refus, fn (array $a, array $b): int => $a['ligne'] <=> $b['ligne']);

            throw new ImportRefuse($refus);
        }

        return $modifiees;
    }

    /**
     * Rapport d'un import refusé : une ligne par problème, avec le numéro de ligne du fichier déposé.
     *
     * @param  list<array{ligne: int, matricule: string, message: string}>  $lignes
     */
    public function rapport(EtapeSaisie $etape, array $lignes): string
    {
        $etape->loadMissing('ecue');
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle('Lignes en erreur');
        $feuille->setCellValue('A1', 'Import refusé — '.$etape->ecue->code.' · '.EtapeSaisie::SESSIONS[$etape->session].' · '.EtapeSaisie::ETAPES[$etape->etape]);
        $feuille->setCellValue('A2', count($lignes).' ligne(s) en erreur. Rien n\'a été enregistré : corrigez le fichier puis déposez-le de nouveau.');
        $feuille->fromArray(['Ligne du fichier', 'Matricule', 'Problème'], null, 'A4');
        $feuille->getStyle('A4:C4')->getFont()->setBold(true);
        $n = 5;
        foreach ($lignes as $ligne) {
            $feuille->setCellValue('A'.$n, $ligne['ligne']);
            $feuille->setCellValueExplicit('B'.$n, $ligne['matricule'], DataType::TYPE_STRING);
            $feuille->setCellValueExplicit('C'.$n, $ligne['message'], DataType::TYPE_STRING);
            $n++;
        }
        foreach (['A', 'B', 'C'] as $col) {
            $feuille->getColumnDimension($col)->setAutoSize(true);
        }

        ob_start();
        (new Xlsx($classeur))->save('php://output');

        return (string) ob_get_clean();
    }
}
