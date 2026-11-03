<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\GabaritLettre as G;
use RuntimeException;
use ZipArchive;

/**
 * Lettre d'admission en première année du Master Professionnel en Finances
 * Publiques : une page par auditeur.
 *
 * La mise en page (en-tête, polices, marges, tableau des frais) est celle du
 * modèle officiel resources/lettres/lettre-admission.docx, enregistré depuis
 * Word : pour la changer, modifier ce fichier en gardant ses repères ${…}. Seuls
 * ces repères sont remplacés, par les paramètres de la campagne. Après un
 * enregistrement dans Word, relancer les tests : Word peut découper un repère.
 */
final class LettreAdmissionWord
{
    private const MODELE = 'lettres/lettre-admission.docx';

    private const CORPS = '~^(.*<w:body>)(.*)(<w:sectPr.*</w:sectPr>)(</w:body></w:document>\s*)$~s';

    private const COPIE = '~<w:p(?: [^>]*)?>(?:(?!</w:p>).)*?\$\{copie\}.*?</w:p>~s';

    private const REPERE_SIGNATURE = '</w:pPr><w:r><w:t>${signature}</w:t></w:r>';

    /** 2,3 cm, en unités Word (EMU). */
    private const HAUTEUR_SIGNATURE = 828000;

    private const ID_SIGNATURE = 'rIdSignature';

    /** @param  iterable<Candidature>  $auditeurs  retenus dotés d'un matricule */
    public function generer(ParametresLettres $p, iterable $auditeurs, ?string $signature): string
    {
        $fichier = (string) tempnam(sys_get_temp_dir(), 'lettre-admission-');
        try {
            $zip = new ZipArchive;
            if (! copy(resource_path(self::MODELE), $fichier) || $zip->open($fichier) !== true) {
                throw new RuntimeException('Modèle de lettre d\'admission illisible.');
            }
            $this->remplir($zip, $p, $auditeurs, $signature);
            // Sans ce contrôle, un échec à l'écriture renverrait le modèle non rempli.
            if (! $zip->close()) {
                throw new RuntimeException('Écriture du fichier de lettres impossible.');
            }

            return (string) file_get_contents($fichier);
        } finally {
            @unlink($fichier);
        }
    }

    /** @param  iterable<Candidature>  $auditeurs */
    private function remplir(ZipArchive $zip, ParametresLettres $p, iterable $auditeurs, ?string $signature): void
    {
        if (preg_match(self::CORPS, (string) $zip->getFromName('word/document.xml'), $document) !== 1) {
            throw new RuntimeException('Modèle de lettre d\'admission : corps introuvable.');
        }
        [, $debut, $modele, $section, $fin] = $document;
        $image = $signature === null ? '' : $this->integrerSignature($zip, $signature);
        if (preg_match(self::COPIE, $modele, $ligneCopie) !== 1) {
            throw new RuntimeException('Modèle de lettre d\'admission : ligne de copie introuvable.');
        }
        // Dans le même strtr que les repères : une valeur saisie n'est jamais relue comme un repère.
        $copies = [$ligneCopie[0] => $this->copies($ligneCopie[0], $p)];

        $lettres = [];
        foreach ($auditeurs as $c) {
            $lettres[] = strtr($modele, $copies + $this->variables($p, $c));
        }
        $dernier = array_key_last($lettres);
        $corps = '';
        // Une section par lettre : chacune commence sur sa page, sans saut de page qui pourrait en créer une vide.
        foreach ($lettres as $i => $lettre) {
            $corps .= str_replace(self::REPERE_SIGNATURE, ($i === $dernier ? '' : $section).'</w:pPr>'.$image, $lettre);
        }
        $numero = 0;
        $corps = (string) preg_replace_callback('~<wp:docPr id="\d+"~', function () use (&$numero): string {
            return '<wp:docPr id="'.++$numero.'"';
        }, $corps);

        $zip->addFromString('word/document.xml', $debut.$corps.$section.$fin);
        $zip->addFromString('word/footer1.xml', str_replace('${pied_de_page}',
            self::xml((string) config('documents_auditeurs.pied_de_page')), (string) $zip->getFromName('word/footer1.xml')));
        $zip->addFromString('docProps/core.xml', (string) preg_replace('~<dc:title>[^<]*</dc:title>~',
            '<dc:title>'.self::xml('Lettres d\'admission — '.$p->promotionCourte().' promotion').'</dc:title>',
            (string) $zip->getFromName('docProps/core.xml')));
    }

    /** @return array<string, string> repères du modèle et leur valeur, échappée pour le XML */
    private function variables(ParametresLettres $p, Candidature $c): array
    {
        [$article, $periode] = $p->periodeRentree();
        $feminin = G::feminin($c);
        [$refDebut, $refNumero, $refFin] = $this->reference($p->reference('reference_admission', (int) $c->rang_retenu));

        return array_map(self::xml(...), [
            '${ref_debut}' => $refDebut,
            '${ref_numero}' => $refNumero,
            '${ref_fin}' => $refFin,
            '${date_lettres}' => $p->date('date_lettres'),
            '${destinataire}' => G::appellation($c),
            '${matricule}' => (string) $c->matricule,
            '${annee_objet}' => str_replace('-', ' - ', $p->anneeAcademique()),
            '${annee}' => $p->anneeAcademique(),
            '${civilite}' => G::civilite($c),
            '${admis}' => $feminin ? 'admise' : 'admis',
            '${invite}' => $feminin ? 'invitée' : 'invité',
            '${rentree_article}' => $article,
            '${rentree_periode}' => $periode,
            '${rentree_heure}' => $p->texte('rentree_heure'),
            '${rentree_lieu}' => rtrim($p->texte('rentree_lieu'), ' .'),
            '${inscription_echeance}' => $p->texte('frais_inscription_echeance'),
            '${inscription_montant}' => $p->montant('frais_inscription_montant'),
            '${tranche1_date}' => $p->date('frais_tranche1_date'),
            '${tranche1_montant}' => $p->montant('frais_tranche1_montant'),
            '${tranche2_date}' => $p->date('frais_tranche2_date'),
            '${tranche2_montant}' => $p->montant('frais_tranche2_montant'),
            '${total}' => $p->totalFrais(),
            '${copie_derniere}' => (string) (array_slice($p->copies(), -1)[0] ?? ''),
        ]);
    }

    /**
     * « N° », le numéro de la lettre (en rouge dans le modèle), puis le service émetteur.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function reference(string $reference): array
    {
        return preg_match('~^(N°\s*)(.*?L)(/.*)$~u', $reference, $m) === 1
            ? [$m[1], $m[2], $m[3]]
            : [$reference, '', ''];
    }

    /** Une ligne par copie, sauf la dernière que le modèle porte déjà avec son propre espacement. */
    private function copies(string $ligne, ParametresLettres $p): string
    {
        return implode('', array_map(fn (string $copie): string => str_replace('${copie}', self::xml($copie), $ligne),
            array_slice($p->copies(), 0, -1)));
    }

    /** Ajoute l'image au fichier et renvoie le dessin à placer sous le titre du signataire. */
    private function integrerSignature(ZipArchive $zip, string $chemin): string
    {
        $taille = @getimagesize($chemin);
        if ($taille === false || $taille[1] === 0) {
            throw new DocumentsIncomplets(['Signature et cachet (image illisible : déposez-la à nouveau)']);
        }
        $extension = (string) image_type_to_extension($taille[2], false);
        $zip->addFile($chemin, 'word/media/signature.'.$extension);
        $zip->addFromString('word/_rels/document.xml.rels', str_replace('</Relationships>',
            '<Relationship Id="'.self::ID_SIGNATURE.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/signature.'.$extension.'"/></Relationships>',
            (string) $zip->getFromName('word/_rels/document.xml.rels')));
        $types = (string) $zip->getFromName('[Content_Types].xml');
        if (! str_contains($types, 'Extension="'.$extension.'"')) {
            $zip->addFromString('[Content_Types].xml', str_replace('</Types>',
                '<Default Extension="'.$extension.'" ContentType="'.$taille['mime'].'"/></Types>', $types));
        }

        return $this->dessin((int) round(self::HAUTEUR_SIGNATURE * $taille[0] / $taille[1]));
    }

    /** Image flottante, derrière le texte : le scan ne décale rien et la lettre tient toujours sur sa page. */
    private function dessin(int $largeur): string
    {
        $hauteur = self::HAUTEUR_SIGNATURE;

        return '<w:r><w:drawing><wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240" behindDoc="1" locked="0" layoutInCell="1" allowOverlap="1">'
            .'<wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="margin"><wp:align>right</wp:align></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>0</wp:posOffset></wp:positionV>'
            .'<wp:extent cx="'.$largeur.'" cy="'.$hauteur.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>'
            .'<wp:docPr id="1" name="Signature" descr="Signature et cachet du Président du Comité de Pilotage"/><wp:cNvGraphicFramePr/>'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="Signature"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="'.self::ID_SIGNATURE.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$largeur.'" cy="'.$hauteur.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            .'</pic:pic></a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>';
    }

    /** Échappe pour le XML et retire ce que XML 1.0 interdit : un seul caractère de contrôle rendrait tout le fichier illisible. */
    private static function xml(string $texte): string
    {
        $propre = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($texte, 'UTF-8'));

        return htmlspecialchars($propre, ENT_XML1 | ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
