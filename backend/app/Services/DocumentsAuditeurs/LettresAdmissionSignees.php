<?php

declare(strict_types=1);

namespace App\Services\DocumentsAuditeurs;

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Support\CandidatureObjectStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lettres d'admission signées et scannées, déposées par l'administration et
 * visibles par chaque auditeur dans son espace, sans condition de paiement.
 *
 * Un fichier n'est rattaché que si son nom est exactement le matricule du dossier,
 * en lot comme dossier par dossier : un nom approchant est refusé plutôt que de risquer de remettre à
 * un auditeur la lettre d'un autre. Stockage privé sous une clé sans matricule.
 */
final class LettresAdmissionSignees
{
    public const PERMISSION = 'candidature.lettres_signees';

    /** Lot traité dans une seule requête : au-delà, procéder en plusieurs fois. */
    public const LOT_MAX = 50;

    /** En Ko : 10 Mo, plafond d'envoi de PHP en production. */
    public const TAILLE_MAX_KO = 10240;

    private const DOSSIER = 'lettres-admission';

    private const URL_SIGNEE_MINUTES = 30;

    public static function disponible(Candidature $c): bool
    {
        return $c->statut === Candidature::STATUT_ACCEPTE
            && $c->withdrawn_at === null
            && filled($c->lettre_admission_path);
    }

    /** Retenu non retiré et numéroté : seul dossier qui peut recevoir une lettre. */
    public static function depotPossible(Candidature $c): bool
    {
        return $c->statut === Candidature::STATUT_ACCEPTE && $c->withdrawn_at === null && $c->matricule !== null;
    }

    /**
     * Une lettre déjà déposée n'est pas remplacée, pour qu'un lot interrompu se
     * relance sans risque : le remplacement passe par deposer().
     *
     * @param  iterable<UploadedFile>  $fichiers
     * @return array{deposees: int, deja: list<string>, inconnus: list<string>, invalides: list<string>, echecs: list<string>, manquantes: int}
     */
    public function deposerLot(CampagneCandidature $campagne, iterable $fichiers, User $auteur): array
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        $fichiers = collect($fichiers);
        if ($fichiers->count() > self::LOT_MAX) {
            throw new InvalidArgumentException('Au plus '.self::LOT_MAX.' fichiers par dépôt : procédez en plusieurs fois.');
        }

        $dossiers = AttributionMatricules::retenus($campagne)
            ->whereNotNull('matricule')
            ->get()
            ->keyBy(fn (Candidature $c): string => mb_strtoupper((string) $c->matricule));

        $bilan = ['deposees' => [], 'deja' => [], 'inconnus' => [], 'invalides' => [], 'echecs' => []];
        foreach ($fichiers as $fichier) {
            $nom = $fichier->getClientOriginalName();
            $dossier = $dossiers->get($this->matriculeDuNom($nom));

            if ($dossier === null) {
                $bilan['inconnus'][] = $nom;
            } elseif (! $this->estUnPdf($fichier)) {
                $bilan['invalides'][] = $nom;
            } else {
                $bilan[$this->deposerSiAbsente($dossier, $fichier, $auteur)][] = $nom;
            }
        }
        $bilan['deposees'] = count($bilan['deposees']);

        activity('candidatures')->causedBy($auteur)->performedOn($campagne)
            ->event('lettres_admission_deposees')
            ->withProperties([
                'deposees' => $bilan['deposees'],
                'deja' => count($bilan['deja']),
                'inconnus' => count($bilan['inconnus']),
                'invalides' => count($bilan['invalides']),
                'echecs' => count($bilan['echecs']),
            ])
            ->log('Lettres d\'admission signées déposées');

        return $bilan + ['manquantes' => $this->manquantes($campagne)];
    }

    /** Dépose ou remplace la lettre d'un dossier. */
    public function deposer(Candidature $c, UploadedFile $fichier, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        if (! self::depotPossible($c)) {
            throw new InvalidArgumentException("Le dossier {$c->numero_dossier} n'est pas un retenu doté d'un matricule.");
        }
        if ($this->matriculeDuNom($fichier->getClientOriginalName()) !== mb_strtoupper((string) $c->matricule)) {
            throw new InvalidArgumentException("Ce fichier ne porte pas le matricule du dossier : il doit s'appeler {$c->matricule}.pdf.");
        }
        if (! $this->estUnPdf($fichier)) {
            throw new InvalidArgumentException('Le fichier doit être un PDF de 10 Mo au plus.');
        }

        $ancien = $c->lettre_admission_path;
        $chemin = $this->ecrire($c, $fichier);
        $c->forceFill($this->champs($chemin, $auteur))->save();

        if ($ancien !== null && ! $this->disque()->delete($ancien)) {
            Log::warning('Ancienne lettre signée non supprimée.', ['candidature' => $c->id]);
        }

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('lettre_admission_deposee')
            ->withProperties(['remplacement' => $ancien !== null])
            ->log('Lettre d\'admission signée déposée');
    }

    /** Pour une lettre rattachée par erreur : l'auditeur ne la voit plus aussitôt. */
    public function retirer(Candidature $c, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        $chemin = $c->lettre_admission_path;
        if ($chemin === null) {
            return;
        }

        $c->forceFill(['lettre_admission_path' => null, 'lettre_admission_deposee_at' => null, 'lettre_admission_deposee_par' => null])->save();
        if (! $this->disque()->delete($chemin)) {
            Log::warning('Lettre signée retirée mais fichier non supprimé.', ['candidature' => $c->id]);
        }

        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('lettre_admission_retiree')
            ->log('Lettre d\'admission signée retirée');
    }

    public function journaliserConsultation(Candidature $c, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
        activity('candidatures')->causedBy($auteur)->performedOn($c)
            ->event('lettre_admission_consultee')
            ->log('Lettre d\'admission signée consultée');
    }

    public function lienSigne(Candidature $c): string
    {
        return $this->disque()->temporaryUrl(
            (string) $c->lettre_admission_path,
            now()->addMinutes(self::URL_SIGNEE_MINUTES),
            [
                'ResponseContentType' => 'application/pdf',
                // Nom générique : ce paramètre figure en clair dans l'adresse, donc dans les journaux.
                'ResponseContentDisposition' => 'attachment; filename="lettre-admission.pdf"',
            ],
        );
    }

    public function manquantes(CampagneCandidature $campagne): int
    {
        return AttributionMatricules::retenus($campagne)
            ->whereNotNull('matricule')
            ->whereNull('lettre_admission_path')
            ->count();
    }

    /** Nom exact « 26EFP014.pdf », sans tenir compte de la casse ; sinon chaîne vide. */
    private function matriculeDuNom(string $nom): string
    {
        return preg_match('/^(.+)\.pdf$/i', $nom, $m) === 1 ? mb_strtoupper($m[1]) : '';
    }

    private function estUnPdf(UploadedFile $fichier): bool
    {
        return $fichier->getSize() <= self::TAILLE_MAX_KO * 1024
            && str_starts_with((string) file_get_contents($fichier->getRealPath(), false, null, 0, 5), '%PDF-');
    }

    /** @return 'deposees'|'deja'|'echecs' clé du bilan où ranger le fichier */
    private function deposerSiAbsente(Candidature $c, UploadedFile $fichier, User $auteur): string
    {
        if ($c->lettre_admission_path !== null) {
            return 'deja';
        }

        try {
            $chemin = $this->ecrire($c, $fichier);
        } catch (RuntimeException) {
            return 'echecs';
        }

        // Mise à jour conditionnelle : deux dépôts simultanés ne se remplacent pas.
        $ecrit = Candidature::query()->whereKey($c->id)->whereNull('lettre_admission_path')
            ->update($this->champs($chemin, $auteur));
        if ($ecrit === 0) {
            $this->disque()->delete($chemin);

            return 'deja';
        }
        // Un second fichier du même lot pour ce dossier doit voir la lettre déposée.
        $c->lettre_admission_path = $chemin;

        return 'deposees';
    }

    private function ecrire(Candidature $c, UploadedFile $fichier): string
    {
        $chemin = self::DOSSIER.'/'.$c->campagne_id.'/'.Str::uuid().'.pdf';
        $ecrit = $this->disque()->put($chemin, (string) file_get_contents($fichier->getRealPath()), [
            'visibility' => 'private',
            'ContentType' => 'application/pdf',
        ]);
        if ($ecrit === false) {
            Log::error('Lettre signée non enregistrée.', ['candidature' => $c->id]);

            throw new RuntimeException('Enregistrement du fichier impossible. Réessayez dans quelques instants.');
        }

        return $chemin;
    }

    /** @return array<string, mixed> */
    private function champs(string $chemin, User $auteur): array
    {
        return [
            'lettre_admission_path' => $chemin,
            'lettre_admission_deposee_at' => now(),
            'lettre_admission_deposee_par' => $auteur->id,
        ];
    }

    private function disque(): Filesystem
    {
        return Storage::disk(CandidatureObjectStorage::DISK);
    }
}
