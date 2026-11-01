<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\GabaritLettre as G;
use App\Services\ResultatsSelection\WordGabarit;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Lettre d'admission en première année du Master Professionnel en Finances
 * Publiques : une page par auditeur. Texte repris du modèle de la 13e
 * promotion ; dates, rentrée et frais lus dans les paramètres de la campagne.
 */
final class LettreAdmissionWord
{
    /** @param  iterable<Candidature>  $auditeurs  retenus dotés d'un matricule */
    public function generer(ParametresLettres $p, iterable $auditeurs, ?string $signature): string
    {
        $word = G::document('Lettres d\'admission — '.$p->promotionCourte().' promotion');
        foreach ($auditeurs as $c) {
            $this->page(G::lettre($word), $p, $c, $signature);
        }

        return WordGabarit::enBinaire($word);
    }

    private function page(Section $s, ParametresLettres $p, Candidature $c, ?string $signature): void
    {
        $annee = $p->anneeAcademique();
        G::reference($s, $p->reference('reference_admission', (int) $c->rang_retenu), $p->date('date_lettres'));
        G::destinataire($s, $c);
        G::objet($s, 'Votre admission au Master Professionnel en Finances Publiques ('.$p->promotionCourte()
            .' promotion, année académique '.$annee.')');

        G::paragraphe($s, G::civilite($c).',');
        G::paragraphe($s, 'J\'ai l\'honneur de vous informer que vous êtes '.(G::feminin($c) ? 'admise' : 'admis')
            .' en première année du cycle de Master Professionnel en Finances Publiques, au sein du Programme Supérieur '
            .'de Spécialisation en Finances Publiques (PSSFP), au titre de l\'année académique '.$annee.'.');
        G::paragraphe($s, 'Le PSSFP a été mis sur pied par une convention entre le Ministère des Finances et '
            .'l\'Université de Yaoundé II.');

        $rentree = $s->addTextRun(G::CORPS);
        $rentree->addText('En vous adressant mes sincères félicitations, je vous informe que la rentrée solennelle, qui marque '
            .'le début effectif de votre cursus, aura lieu le ', G::police(G::TEXTE, 11));
        $rentree->addText($p->date('rentree_date').' à '.$p->texte('rentree_heure'), G::police(G::TEXTE, 11, bold: true));
        $rentree->addText(', '.rtrim($p->texte('rentree_lieu'), ' .').'.', G::police(G::TEXTE, 11));

        G::paragraphe($s, 'Au préalable, vous êtes invité'.(G::feminin($c) ? 'e' : '').' à accomplir les formalités '
            .'administratives et financières au campus de Messa, sis en face du Camp SIC. Le tableau ci-dessous précise '
            .'les modalités de règlement des frais de formation.');

        $this->frais($s, $p);

        G::paragraphe($s, 'Le calendrier académique, qui précise les étapes et les rendez-vous essentiels de votre parcours, '
            .'vous sera communiqué lors de votre inscription.');
        G::paragraphe($s, 'Veuillez agréer, '.G::civilite($c).', l\'expression de ma parfaite considération.');
        G::signature($s, $p, $signature);
    }

    /** Tableau épuré : filets horizontaux seuls, total souligné d'un filet prune. */
    private function frais(Section $s, ParametresLettres $p): void
    {
        G::rubrique($s, 'Frais de formation — première année de Master');
        $table = G::tableau($s, ['cellMarginTop' => 60, 'cellMarginBottom' => 60, 'cellMarginLeft' => 100, 'cellMarginRight' => 100]);
        $this->ligne($table, ['Nature', 'Échéance', 'Montant (FCFA)'], entete: true);
        $this->ligne($table, ['Frais d\'inscription et péri-académiques', $p->texte('frais_inscription_echeance'), $p->montant('frais_inscription_montant')]);
        $this->ligne($table, ['1re tranche', $p->date('frais_tranche1_date'), $p->montant('frais_tranche1_montant')]);
        $this->ligne($table, ['2e tranche', $p->date('frais_tranche2_date'), $p->montant('frais_tranche2_montant')]);
        $this->ligne($table, ['Total', '', $p->totalFrais()], total: true);
        $s->addText('', ['size' => 4], ['spaceAfter' => 120]);
    }

    /** @param  array{0: string, 1: string, 2: string}  $cellules */
    private function ligne(Table $table, array $cellules, bool $entete = false, bool $total = false): void
    {
        $table->addRow(null, ['cantSplit' => true, 'tblHeader' => $entete]);
        $filet = $entete
            ? ['borderBottomSize' => 8, 'borderBottomColor' => G::OR]
            : ($total ? ['borderTopSize' => 10, 'borderTopColor' => G::PRUNE] : ['borderBottomSize' => 2, 'borderBottomColor' => 'D9D4CC']);
        $police = $entete
            ? G::police(G::LIBELLE, 8.5, bold: true, color: G::GRIS, espacement: 15)
            : G::police(G::TEXTE, 11, bold: $total, color: $total ? G::PRUNE : G::ENCRE);
        foreach ([[4300, Jc::START], [3112, Jc::START], [2000, Jc::END]] as $i => [$largeur, $alignement]) {
            $texte = $entete ? mb_strtoupper($cellules[$i]) : $cellules[$i];
            $table->addCell($largeur, $filet + ['valign' => 'center'])->addText($texte, $police, ['alignment' => $alignement, 'spaceAfter' => 0]);
        }
    }
}
