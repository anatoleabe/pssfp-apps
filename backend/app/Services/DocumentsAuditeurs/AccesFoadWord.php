<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\GabaritLettre as G;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use App\Services\ResultatsSelection\WordGabarit;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * « Informations d'accès aux plateformes de formation à distance » : une page
 * par auditeur, avec ses identifiants Moodle (figés à l'attribution du
 * matricule). Le fichier contient des mots de passe : réservé à
 * l'administration (candidature.matricules_moodle).
 */
final class AccesFoadWord
{
    /** Espace insécable avant « : » (typographie française). */
    private const NB = "\u{00A0}";

    /** @param  iterable<Candidature>  $auditeurs  retenus dotés d'un matricule */
    public function generer(ParametresLettres $p, iterable $auditeurs, ?string $signature): string
    {
        $word = G::document('Informations d\'accès à la plateforme de formation à distance');
        foreach ($auditeurs as $c) {
            $this->page(G::lettre($word), $p, $c, $signature);
        }

        return WordGabarit::enBinaire($word);
    }

    private function page(Section $s, ParametresLettres $p, Candidature $c, ?string $signature): void
    {
        G::reference($s, $p->reference('reference_acces', (int) $c->rang_retenu), $p->date('date_lettres'));
        G::destinataire($s, $c);
        G::objet($s, 'Informations d\'accès à la plateforme de formation à distance');

        G::paragraphe($s, G::civilite($c).',');
        G::paragraphe($s, 'J\'ai le plaisir de vous transmettre vos identifiants personnels d\'accès au campus numérique '
            .'du Programme Supérieur de Spécialisation en Finances Publiques (PSSFP), sur lequel se déroulent les '
            .'enseignements à distance de votre formation.');

        $this->identifiants($s, $c);
        $this->utilisation($s);
        $this->espaceAuditeur($s);
        $this->coordonnees($s, $c);

        G::paragraphe($s, 'Je vous souhaite la bienvenue au PSSFP ; la scolarité reste à votre disposition pour toute assistance.');
        G::paragraphe($s, 'Veuillez agréer, '.G::civilite($c).', l\'expression de mes salutations distinguées.');
        G::signature($s, $signature);
    }

    /** Encadré ivoire, filet prune à gauche : ce que l'auditeur doit retrouver d'un coup d'œil. */
    private function identifiants(Section $s, Candidature $c): void
    {
        G::rubrique($s, 'Vos identifiants');
        $table = G::tableau($s, ['cellMarginTop' => 70, 'cellMarginBottom' => 70, 'cellMarginLeft' => 220, 'cellMarginRight' => 160]);
        $lignes = [
            ['Adresse', preg_replace('#^https?://#', '', (string) config('pssfp.foad_url')), false],
            ['Identifiant', IdentifiantsMoodle::identifiant($c), true],
            ['Mot de passe', (string) $c->moodle_password, true],
        ];
        foreach ($lignes as [$libelle, $valeur, $code]) {
            $table->addRow(null, ['cantSplit' => true]);
            $style = ['bgColor' => G::IVOIRE, 'valign' => 'center', 'borderLeftSize' => 24, 'borderLeftColor' => G::PRUNE];
            $table->addCell(2600, $style)->addText($libelle, G::police(G::LIBELLE, 9.5, color: G::GRIS), ['spaceAfter' => 0]);
            $table->addCell(6812, ['bgColor' => G::IVOIRE, 'valign' => 'center'])->addText($valeur,
                $code ? G::police(G::CODE, 13, bold: true, color: G::PRUNE) : G::police(G::TEXTE, 12.5, bold: true),
                ['spaceAfter' => 0]);
        }
        $s->addText('Ces identifiants vous sont personnels et vous sont attribués pour toute la durée de votre formation. '
            .'Ne communiquez jamais votre mot de passe.', G::police(G::LIBELLE, 8.5, italic: true, color: G::GRIS),
            ['spaceBefore' => 80, 'spaceAfter' => 0]);
    }

    private function utilisation(Section $s): void
    {
        G::rubrique($s, 'Se connecter');
        $this->point($s, 'Sur ordinateur : ', 'ouvrez '.config('pssfp.foad_url').' et saisissez votre identifiant et votre mot de passe.');
        $this->point($s, 'Sur téléphone : ', 'installez gratuitement l\'application « Moodle » depuis Google Play ou '
            .'l\'App Store, indiquez l\'adresse de la plateforme, puis vos identifiants.');
    }

    /** L'espace auditeur réunit le dossier, les messages et les documents administratifs. */
    private function espaceAuditeur(Section $s): void
    {
        G::rubrique($s, 'Votre espace auditeur');
        $this->point($s, 'Adresse'.self::NB.': ', preg_replace('#^https?://#', '', (string) config('pssfp.candidature_app_url')));
        $this->point($s, 'Connexion'.self::NB.': ', 'avec les identifiants ci-dessus (onglet «'.self::NB.'Matricule'.self::NB.'»), '
            .'ou avec votre numéro de téléphone et votre code personnel à 6 chiffres.');
        $s->addText('Vous y retrouvez votre dossier, les messages du PSSFP et vos documents administratifs.',
            G::police(G::LIBELLE, 8.5, italic: true, color: G::GRIS), ['spaceAfter' => 0, 'indentation' => ['left' => 280]]);
    }

    /** Coordonnées enregistrées : l'auditeur signale une erreur avant qu'un message ne se perde. */
    private function coordonnees(Section $s, Candidature $c): void
    {
        G::rubrique($s, 'Vos coordonnées enregistrées');
        $telephones = array_values(array_filter(array_map(
            fn (?string $indicatif, ?string $numero): string => blank($numero) ? ''
                : trim((filled($indicatif) ? '+'.ltrim((string) $indicatif, '+').' ' : '').$numero),
            [$c->indicatif1, $c->indicatif2],
            [$c->telephone1, $c->telephone2],
        ))) ?: array_filter([(string) $c->phone_e164]);
        $this->point($s, 'Téléphone : ', $telephones === [] ? '—' : implode('   ·   ', $telephones));
        $this->point($s, 'E-mail : ', (string) ($c->email ?: '—'));
        $s->addText('Une erreur ? Signalez-la à la scolarité.', G::police(G::LIBELLE, 8.5, italic: true, color: G::GRIS),
            ['spaceAfter' => 160, 'indentation' => ['left' => 280]]);
    }

    private function point(Section $s, string $libelle, string $texte): void
    {
        $run = $s->addTextRun(['spaceAfter' => 60, 'indentation' => ['left' => 280], 'alignment' => Jc::START]);
        $run->addText($libelle, G::police(G::TEXTE, 11, bold: true));
        $run->addText($texte, G::police(G::TEXTE, 11));
    }
}
