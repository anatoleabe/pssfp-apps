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

/**
 * Mise en page des lettres aux auditeurs : sobre et aérée, dans l'esprit des
 * correspondances des grandes écoles.
 *
 * - Garamond pour le texte, Calibri pour les libellés, Consolas pour les
 *   identifiants : polices livrées avec Microsoft Office sur Windows et Mac,
 *   le document restant modifiable avant impression.
 * - Prune PSSFP pour le nom du destinataire et les titres, filets or
 *   champagne, le reste en noir et gris.
 * - Une lettre par section : chacune commence sur une nouvelle page, et un
 *   seul fichier peut réunir toute la promotion.
 */
final class GabaritLettre
{
    public const TEXTE = 'Garamond';

    public const LIBELLE = 'Calibri';

    public const CODE = 'Consolas';

    public const PRUNE = '4A2E67';

    public const OR = 'B8913F';

    public const GRIS = '5F5F5F';

    public const ENCRE = '1F1F1F';

    public const IVOIRE = 'FAF7F2';

    /** Largeur utile : 21 cm − 2 × 2,2 cm de marge. */
    public const LARGEUR = 9412;

    public const CORPS = ['alignment' => Jc::BOTH, 'spaceAfter' => 100, 'lineHeight' => 1.15];

    private const EN_TETE_FR = ['RÉPUBLIQUE DU CAMEROUN', 'Paix – Travail – Patrie', 'MINISTÈRE DES FINANCES',
        'SECRÉTARIAT GÉNÉRAL', 'PROGRAMME SUPÉRIEUR DE SPÉCIALISATION EN FINANCES PUBLIQUES'];

    private const EN_TETE_EN = ['REPUBLIC OF CAMEROON', 'Peace – Work – Fatherland', 'MINISTRY OF FINANCE',
        'GENERAL SECRETARIAT', 'ADVANCED PROGRAM OF SPECIALISATION IN PUBLIC FINANCE'];

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
            self::police(self::LIBELLE, 7.5, color: self::GRIS),
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'borderTopSize' => 4, 'borderTopColor' => self::OR]);

        self::enTete($section);

        return $section;
    }

    /** En-tête bilingue de l'administration, logo au centre, filet or dessous. */
    private static function enTete(Section $section): void
    {
        $table = self::tableau($section);
        $table->addRow();
        self::colonneEnTete($table->addCell(3900, ['valign' => 'center']), self::EN_TETE_FR);
        $table->addCell(1612, ['valign' => 'center'])->addImage(WordGabarit::image('logo.png'),
            ['width' => Converter::cmToPoint(2.1), 'alignment' => Jc::CENTER]);
        self::colonneEnTete($table->addCell(3900, ['valign' => 'center']), self::EN_TETE_EN);

        $section->addText('', ['size' => 4], ['spaceAfter' => 160, 'borderBottomSize' => 6, 'borderBottomColor' => self::OR]);
    }

    /** @param  list<string>  $lignes */
    private static function colonneEnTete(Cell $cellule, array $lignes): void
    {
        $centre = ['alignment' => Jc::CENTER, 'spaceAfter' => 0, 'lineHeight' => 1.0];
        foreach ($lignes as $i => $ligne) {
            $devise = $i === 1;
            $cellule->addText($ligne, self::police(self::LIBELLE, $devise ? 7 : 7.5, bold: ! $devise && $i !== 4,
                italic: $devise, color: self::ENCRE), $centre + ['spaceAfter' => in_array($i, [1, 2, 3], true) ? 40 : 0]);
        }
    }

    /** Référence à gauche, lieu et date à droite. */
    public static function reference(Section $section, string $reference, string $date): void
    {
        $table = self::tableau($section);
        $table->addRow();
        $table->addCell(5200)->addText($reference, self::police(self::LIBELLE, 9, color: self::GRIS), ['spaceAfter' => 0]);
        $table->addCell(4212)->addText('Yaoundé, le '.$date, self::police(self::TEXTE, 11.5, italic: true),
            ['alignment' => Jc::END, 'spaceAfter' => 0]);
    }

    /** Expéditeur et destinataire, alignés à droite. */
    public static function destinataire(Section $section, Candidature $c): void
    {
        $droite = ['alignment' => Jc::END, 'spaceAfter' => 0];
        $section->addText('', ['size' => 6], ['spaceAfter' => 60]);
        $section->addText(mb_strtoupper((string) config('documents_auditeurs.signataire')),
            self::police(self::LIBELLE, 8.5, bold: true, color: self::GRIS, espacement: 20), $droite);
        $section->addText('à', self::police(self::TEXTE, 11, italic: true, color: self::GRIS), $droite + ['spaceBefore' => 40, 'spaceAfter' => 40]);
        $section->addText(self::appellation($c), self::police(self::TEXTE, 14, bold: true, color: self::PRUNE), $droite);
        $section->addText('Matricule '.$c->matricule, self::police(self::LIBELLE, 9.5, color: self::GRIS), $droite + ['spaceAfter' => 140]);
    }

    public static function objet(Section $section, string $objet): void
    {
        $run = $section->addTextRun(['spaceAfter' => 240, 'indentation' => ['left' => 1000, 'hanging' => 1000]]);
        $run->addText('Objet :', self::police(self::TEXTE, 11.5, bold: true));
        $run->addText("\t".$objet, self::police(self::TEXTE, 11.5, italic: true));
    }

    /** Copies à gauche ; titre du signataire et signature scannée à droite. */
    public static function signature(Section $section, ParametresLettres $p, ?string $image, bool $copies = true): void
    {
        $table = self::tableau($section);
        $table->addRow();
        $gauche = $table->addCell(intdiv(self::LARGEUR, 2), ['valign' => 'bottom']);
        if ($copies && $p->copies() !== []) {
            $gauche->addText('Copies :', self::police(self::LIBELLE, 8, bold: true, color: self::GRIS), ['spaceAfter' => 0]);
            foreach ($p->copies() as $copie) {
                $gauche->addText('– '.$copie, self::police(self::LIBELLE, 8, color: self::GRIS), ['spaceAfter' => 0]);
            }
        }
        $droite = $table->addCell(intdiv(self::LARGEUR, 2));
        $droite->addText((string) config('documents_auditeurs.signataire'), self::police(self::TEXTE, 11, bold: true),
            ['alignment' => Jc::CENTER, 'spaceBefore' => 80, 'spaceAfter' => 20]);
        if ($image !== null) {
            $droite->addImage($image, ['height' => Converter::cmToPoint(2.3), 'alignment' => Jc::CENTER]);
        }
    }

    /** Titre de rubrique : petites capitales prune, filet or fin. */
    public static function rubrique(Section $section, string $titre): void
    {
        $section->addText(mb_strtoupper($titre), self::police(self::LIBELLE, 9, bold: true, color: self::PRUNE, espacement: 30),
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
    public static function police(string $nom, float $taille, bool $bold = false, bool $italic = false,
        string $color = self::ENCRE, int $espacement = 0): array
    {
        return array_filter(['name' => $nom, 'size' => $taille, 'bold' => $bold, 'italic' => $italic,
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

    /** « MENGUE Alice » : nom en capitales, prénoms tels que saisis. */
    public static function nomComplet(Candidature $c): string
    {
        return trim(mb_strtoupper((string) $c->nom).' '.(string) $c->prenom);
    }
}
