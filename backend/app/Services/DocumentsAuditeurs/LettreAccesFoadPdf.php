<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\Candidature;
use App\Services\DocumentsAuditeurs\GabaritLettre as G;
use App\Services\ExportsRetenus\IdentifiantsMoodle;
use Barryvdh\DomPDF\Facade\Pdf;
use finfo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lettre d'accès FOAD d'un auditeur, en PDF, téléchargée depuis son espace :
 * mêmes textes que la version Word de l'administration (`AccesFoadWord`),
 * signataire précédé de la mention (é), le document n'étant pas signé à la main.
 *
 * Les conditions de remise (inscription soldée, compte créé, mot de passe connu)
 * se vérifient avant l'appel : ce service ne fait que mettre en page.
 */
final class LettreAccesFoadPdf
{
    /**
     * @throws DocumentsIncomplets paramètres des lettres absents sur la promotion
     */
    public function generer(Candidature $c): string
    {
        return Pdf::loadHTML($this->html($c))->setPaper('a4', 'portrait')->output();
    }

    /**
     * @throws DocumentsIncomplets paramètres des lettres absents sur la promotion
     */
    public function html(Candidature $c): string
    {
        $c->loadMissing('campagne');
        $parametres = ParametresLettres::pour($c->campagne);
        if (($manquants = $parametres->manquants(ParametresLettres::DOCUMENT_ACCES)) !== []) {
            throw new DocumentsIncomplets($manquants);
        }

        return view('pdf.lettre-acces-foad', [
            'reference' => $parametres->reference('reference_acces', (int) $c->rang_retenu),
            'date' => $parametres->date('date_lettres'),
            'expediteur' => mb_strtoupper((string) config('documents_auditeurs.signataire')),
            'signataire' => trim(config('documents_auditeurs.mention_signature_electronique').' '.config('documents_auditeurs.signataire')),
            'appellation' => G::appellation($c),
            'civilite' => G::civilite($c),
            'matricule' => (string) $c->matricule,
            'adresse_foad' => self::sansProtocole((string) config('pssfp.foad_url')),
            'url_foad' => (string) config('pssfp.foad_url'),
            'adresse_espace' => self::sansProtocole((string) config('pssfp.candidature_app_url')),
            'identifiant' => IdentifiantsMoodle::identifiant($c),
            'mot_de_passe' => (string) $c->moodle_password,
            'telephones' => G::telephones($c),
            'email' => (string) ($c->email ?: '—'),
            'pied_de_page' => (string) config('documents_auditeurs.pied_de_page'),
            'logo' => self::imageEnLigne((string) file_get_contents(resource_path('images/pdf/logo.png'))),
            'signature' => $this->signature($parametres->signature),
        ])->render();
    }

    /**
     * Scan facultatif. Illisible, la lettre part sans lui (la mention (é) en
     * tient lieu) : l'auditeur n'a pas à attendre une correction de l'admin.
     */
    private function signature(?string $chemin): ?string
    {
        if ($chemin === null) {
            return null;
        }
        try {
            return self::imageEnLigne(DocumentsAuditeurs::lireSignature($chemin));
        } catch (Throwable $e) {
            Log::channel('single')->warning('Signature des lettres illisible : lettre d\'accès PDF éditée sans elle.', ['erreur' => $e::class]);

            return null;
        }
    }

    private static function imageEnLigne(string $octets): ?string
    {
        if ($octets === '') {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($octets);

        return in_array($mime, ['image/png', 'image/jpeg'], true) ? 'data:'.$mime.';base64,'.base64_encode($octets) : null;
    }

    private static function sansProtocole(string $url): string
    {
        return (string) preg_replace('#^https?://#', '', $url);
    }
}
