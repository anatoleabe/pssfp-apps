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
     * Lit un modèle rempli et le saisit (ou le contrôle seulement).
     *
     * @return int notes modifiées (à modifier en essai à blanc)
     *
     * @throws InvalidArgumentException fichier illisible, matricule inconnu, note invalide, motif manquant
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
        $inconnus = [];
        $max = $feuille->getHighestDataRow();
        for ($n = self::LIGNE_ENTETE + 1; $n <= $max; $n++) {
            [$matricule, , , $note, $presence, $motif] = $feuille->rangeToArray("A{$n}:F{$n}", null, true, false)[0];
            $matricule = mb_strtoupper(trim((string) $matricule));
            if ($matricule === '' || (trim((string) $note) === '' && trim((string) $presence) === '')) {
                continue;
            }
            $id = $parMatricule[$matricule] ?? null;
            if ($id === null) {
                $inconnus[] = $matricule;

                continue;
            }
            $libellePresence = mb_strtolower(trim((string) $presence));
            $lignes[$id] = [
                'note' => is_string($note) ? trim($note) : $note,
                'presence' => $libellePresence === '' ? null : ($presences[$libellePresence] ?? $libellePresence),
                'motif' => trim((string) $motif),
            ];
        }
        if ($inconnus !== []) {
            throw new InvalidArgumentException('Matricules non inscrits à cette UE : '.implode(', ', array_slice($inconnus, 0, 10)).(count($inconnus) > 10 ? '…' : '').'. Rien n\'a été enregistré.');
        }

        return $this->saisie->saisir($etape, $lignes, $auteur, $appliquer);
    }
}
