<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\ResultatsSelection\WordGabarit;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Image;

/**
 * Mise en page des lettres aux auditeurs : une correspondance administrative
 * aux caractéristiques constantes, comme le modèle de la lettre d'admission.
 *
 * - Garamond partout, sans italique ; Tahoma pour le seul en-tête administratif.
 * - Consolas pour le seul identifiant et le mot de passe, où il faut distinguer
 *   le l du 1 et le O du 0. Polices livrées avec Microsoft Office sur Windows et
 *   Mac, le document restant modifiable avant impression.
 * - Prune PSSFP pour le nom du destinataire et les titres, filets or
 *   champagne, le reste en noir et gris.
 * - Une lettre par section : chacune commence sur une nouvelle page, et un
 *   seul fichier peut réunir toute la promotion.
 */
final class GabaritLettre
{
    public const TEXTE = 'Garamond';

    public const CODE = 'Consolas';

    public const PRUNE = '4A2E67';

    public const OR = 'B8913F';

    public const GRIS = '5F5F5F';

    public const ENCRE = '1F1F1F';

    public const IVOIRE = 'FAF7F2';

    /** Largeur utile : 21 cm − 2 × 2,2 cm de marge. */
    public const LARGEUR = 9412;

    public const CORPS = ['alignment' => Jc::BOTH, 'spaceAfter' => 100, 'lineHeight' => 1.15];

    /** Police de l'en-tête administratif, celle du modèle officiel de lettre d'admission. */
    public const EN_TETE = 'Tahoma';

    private const SEPARATEUR = '--------------';

    /** [texte, taille, gras], dans l'ordre et les tailles du modèle officiel. */
    private const EN_TETE_FR = [['REPUBLIQUE DU CAMEROUN', 10, false], ['Paix- Travail- Patrie', 7, true], [self::SEPARATEUR, 10, false],
        ['MINISTERE DES FINANCES', 10, false], [self::SEPARATEUR, 10, false], ['SECRETARIAT GENERAL', 10, false], [self::SEPARATEUR, 10, false],
        ['PROGRAMME SUPERIEUR DE SPECIALISATION EN FINANCES PUBLIQUES', 9, false], [self::SEPARATEUR, 10, false]];

    private const EN_TETE_EN = [['REPUBLIC OF CAMEROON', 10, false], ['Peace- Work- Fatherland', 7, true], [self::SEPARATEUR, 10, false],
        ['MINISTRY OF FINANCE', 10, false], ['----------------', 10, false], ['GENERAL SECRETARIAT', 10, false], [self::SEPARATEUR, 10, false],
        ['ADVANCED PROGRAM OF SPECIALISATION IN PUBLIC FINANCE', 9, false], [self::SEPARATEUR, 10, false]];

    public static function document(string $titre): PhpWord
    {
        $word = WordGabarit::document();
        $word->setDefaultFontName(self::TEXTE);
        $word->setDefaultFontSize(11);
        $word->getDocInfo()->setTitle($titre);

        return $word;
    }

    /** Nouvelle lettre : nouvelle page, en-tête officiel et pied de page. */
    public static function lettre(PhpWord $word): Section
    {
        $section = $word->addSection([
            'paperSize' => 'A4',
            'marginTop' => Converter::cmToTwip(1.0),
            'marginBottom' => Converter::cmToTwip(1.6),
            'marginLeft' => Converter::cmToTwip(2.2),
            'marginRight' => Converter::cmToTwip(2.2),
            'footerHeight' => Converter::cmToTwip(0.7),
        ]);
        $section->addFooter()->addText((string) config('documents_auditeurs.pied_de_page'),
            self::police(self::TEXTE, 7.5, color: self::GRIS),
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'borderTopSize' => 4, 'borderTopColor' => self::OR]);

        self::enTete($section);

        return $section;
    }

    /** En-tête bilingue du modèle officiel : tableau flottant centré sur la page, plus large que le texte, logo au centre. */
    private static function enTete(Section $section): void
    {
        $table = $section->addTable(['width' => 10740, 'unit' => 'dxa', 'layout' => 'fixed', 'cellMarginTop' => 142, 'cellMarginLeft' => 108, 'cellMarginRight' => 108, 'position' => [
            'vertAnchor' => 'page', 'horzAnchor' => 'margin', 'tblpXSpec' => 'center', 'tblpY' => 205,
            'leftFromText' => 141, 'rightFromText' => 141, 'bottomFromText' => 200,
        ]]);
        $table->addRow(2010);
        self::colonneEnTete($table->addCell(4139), self::EN_TETE_FR);
        $table->addCell(2835)->addImage(WordGabarit::image('logo.png'), ['width' => 129.75, 'height' => 102, 'alignment' => Jc::CENTER]);
        self::colonneEnTete($table->addCell(3766), self::EN_TETE_EN);
    }

    /** @param  list<array{0: string, 1: int, 2: bool}>  $lignes */
    private static function colonneEnTete(Cell $cellule, array $lignes): void
    {
        foreach ($lignes as [$ligne, $taille, $gras]) {
            $cellule->addText($ligne, self::police(self::EN_TETE, $taille, bold: $gras, color: '000000'),
                ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'lineHeight' => 1.0]);
        }
    }

    /** Référence à gauche, lieu et date à droite. */
    public static function reference(Section $section, string $reference, string $date): void
    {
        $table = self::tableau($section);
        $table->addRow();
        $table->addCell(5200)->addText($reference, self::police(self::TEXTE, 9, color: self::GRIS), ['spaceAfter' => 0]);
        $table->addCell(4212)->addText('Yaoundé, le '.$date, self::police(self::TEXTE, 11.5),
            ['alignment' => Jc::END, 'spaceAfter' => 0]);
    }

    /** Expéditeur et destinataire, alignés à droite. */
    public static function destinataire(Section $section, Candidature $c): void
    {
        $droite = ['alignment' => Jc::END, 'spaceAfter' => 0];
        $section->addText('', ['size' => 6], ['spaceAfter' => 60]);
        $section->addText(mb_strtoupper((string) config('documents_auditeurs.signataire')),
            self::police(self::TEXTE, 13, bold: true), $droite);
        $section->addText('à', self::police(self::TEXTE, 11, bold: true, color: self::GRIS), $droite + ['spaceBefore' => 40, 'spaceAfter' => 40]);
        $section->addText(self::appellation($c), self::police(self::TEXTE, 12, bold: true, color: self::PRUNE), $droite);
        $section->addText("Matricule\u{00A0}: ".$c->matricule, self::police(self::TEXTE, 10, bold: true, color: self::GRIS), $droite + ['spaceAfter' => 140]);
    }

    public static function objet(Section $section, string $objet): void
    {
        $run = $section->addTextRun(['spaceAfter' => 240, 'indentation' => ['left' => 1000, 'hanging' => 1000]]);
        $run->addText('Objet', self::police(self::TEXTE, 11.5, bold: true) + ['underline' => 'single']);
        $run->addText("\u{00A0}:", self::police(self::TEXTE, 11.5, bold: true));
        $run->addText("\t".$objet, self::police(self::TEXTE, 11));
    }

    /** Titre du signataire et signature scannée, s'il y en a une, dans la moitié droite. */
    public static function signature(Section $section, ?string $image): void
    {
        $table = self::tableau($section);
        $table->addRow();
        $table->addCell(intdiv(self::LARGEUR, 2));
        $droite = $table->addCell(intdiv(self::LARGEUR, 2))->addTextRun(['alignment' => Jc::END, 'spaceBefore' => 80, 'spaceAfter' => 20]);
        $droite->addText((string) config('documents_auditeurs.signataire'), self::police(self::TEXTE, 11, bold: true));
        if ($image !== null) {
            // Flottante, derrière le texte : l'en-tête officiel est haut et le scan ne doit pas créer une seconde page.
            $droite->addImage($image, ['height' => Converter::cmToPoint(2.0), 'marginTop' => 16, 'positioning' => Image::POSITION_RELATIVE,
                'posHorizontal' => Image::POSITION_HORIZONTAL_RIGHT, 'posHorizontalRel' => Image::POSITION_RELATIVE_TO_COLUMN,
                'posVerticalRel' => Image::POSITION_RELATIVE_TO_LINE, 'wrappingStyle' => Image::WRAPPING_STYLE_BEHIND]);
        }
    }

    /** Titre de rubrique : petites capitales prune, filet or fin. */
    public static function rubrique(Section $section, string $titre): void
    {
        $section->addText(mb_strtoupper($titre), self::police(self::TEXTE, 9, bold: true, color: self::PRUNE, espacement: 30),
            ['spaceBefore' => 160, 'spaceAfter' => 80, 'keepNext' => true, 'borderBottomSize' => 4, 'borderBottomColor' => self::OR]);
    }

    public static function paragraphe(Section|Cell $conteneur, string $texte): void
    {
        $conteneur->addText($texte, self::police(self::TEXTE, 11), self::CORPS);
    }

    /** Tableau sans bordure à la largeur utile. */
    public static function tableau(Section $section, array $style = []): Table
    {
        return $section->addTable(array_merge(['borderSize' => 0, 'borderColor' => 'FFFFFF', 'cellMargin' => 0,
            'width' => self::LARGEUR, 'unit' => 'dxa', 'layout' => 'fixed'], $style));
    }

    /** @return array<string, mixed> */
    public static function police(string $nom, float $taille, bool $bold = false,
        string $color = self::ENCRE, int $espacement = 0): array
    {
        return array_filter(['name' => $nom, 'size' => $taille, 'bold' => $bold,
            'color' => $color, 'spacing' => $espacement], fn ($v): bool => $v !== 0);
    }

    /** « Monsieur », « Madame » ou « Mademoiselle » ; à défaut, d'après le genre ; sinon « Madame, Monsieur ». */
    public static function civilite(Candidature $c): string
    {
        return self::civiliteConnue($c) ?? 'Madame, Monsieur';
    }

    /** « Madame MENGUE Alice » : le destinataire, avec sa civilité si elle est connue. */
    public static function appellation(Candidature $c): string
    {
        return trim((self::civiliteConnue($c) ?? '').' '.self::nomComplet($c));
    }

    private static function civiliteConnue(Candidature $c): ?string
    {
        return match (true) {
            $c->civilite === 'M.' => 'Monsieur',
            $c->civilite === 'Mme' => 'Madame',
            $c->civilite === 'Mlle' => 'Mademoiselle',
            $c->genre === 'M' => 'Monsieur',
            $c->genre === 'F' => 'Madame',
            default => null,
        };
    }

    public static function feminin(Candidature $c): bool
    {
        return in_array($c->civilite, ['Mme', 'Mlle'], true) || ($c->civilite === null && $c->genre === 'F');
    }

    /**
     * Téléphones enregistrés, « +237 699… », ceux du formulaire d'abord, sinon celui du compte.
     *
     * @return list<string>
     */
    public static function telephones(Candidature $c): array
    {
        return array_values(array_filter(array_map(
            fn (?string $indicatif, ?string $numero): string => blank($numero) ? ''
                : trim((filled($indicatif) ? '+'.ltrim((string) $indicatif, '+').' ' : '').$numero),
            [$c->indicatif1, $c->indicatif2],
            [$c->telephone1, $c->telephone2],
        ))) ?: array_values(array_filter([(string) $c->phone_e164]));
    }

    /** « MENGUE Alice » : nom en capitales, prénoms tels que saisis. */
    public static function nomComplet(Candidature $c): string
    {
        return trim(mb_strtoupper((string) $c->nom).' '.(string) $c->prenom);
    }
}
