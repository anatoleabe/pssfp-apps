<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Auditeur;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\DossierMemoire;
use App\Models\InscriptionAnnuelle;
use App\Models\LeveeRestriction;
use App\Models\ResultatAnnuel;
use App\Models\User;
use App\Support\CandidatureObjectStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Documents officiels de l'auditeur (spec lot 7 ; RG-059, D9) : relevé de notes,
 * attestation de réussite, certificat de scolarité. Numérotés, scellés, conservés,
 * vérifiables, révocables. Un second téléchargement sert le même fichier.
 */
final class DocumentsOfficiels
{
    public const DOSSIER = 'lmd/documents';

    public function __construct(private readonly ResultatsAuditeur $resultats) {}

    /**
     * Document valide déjà délivré, sinon délivrance. `$auteur` null : l'auditeur lui-même.
     *
     * @throws InvalidArgumentException document indisponible (motif lisible par l'auditeur)
     */
    public function obtenir(string $type, Auditeur $auditeur, ?Deliberation $d, ?User $auteur = null): DocumentDelivre
    {
        [$inscription, $resultat] = $this->verifier($type, $auditeur, $d);
        $existant = DocumentDelivre::query()->where('type', $type)->where('inscription_annuelle_id', $inscription->id)
            ->where('deliberation_id', $type === DocumentDelivre::CERTIFICAT ? null : $d?->id)->whereNull('revoque_at')->first();
        if ($existant !== null) {
            return $existant;
        }

        return $this->emettre($type, $auditeur, $inscription, $type === DocumentDelivre::CERTIFICAT ? null : $d,
            fn (string $numero, string $code): string => $this->pdf($type, $numero, $code, $auditeur, $inscription, $d, $resultat), $auteur);
    }

    /**
     * Attestation d'autorisation de dépôt du mémoire (D33, D34) : les conditions sont vérifiées par
     * App\Services\Lmd\Memoires ; une seule attestation valide par mémoire.
     *
     * @param  list<array{libelle: string, detail: ?string}>  $conditions
     */
    public function delivrerAutorisationDepot(DossierMemoire $dossier, array $conditions, User $auteur): DocumentDelivre
    {
        $existant = DocumentDelivre::query()->where('type', DocumentDelivre::AUTORISATION_DEPOT)->where('inscription_annuelle_id', $dossier->inscription_annuelle_id)
            ->whereNull('revoque_at')->first();
        if ($existant !== null) {
            throw new InvalidArgumentException('Une attestation valide existe déjà ('.$existant->numero.') : révoquez-la avant d\'en délivrer une autre.');
        }
        $dossier->loadMissing(['auditeur.candidature', 'inscriptionAnnuelle.parcours', 'etapes']);

        return $this->emettre(DocumentDelivre::AUTORISATION_DEPOT, $dossier->auditeur, $dossier->inscriptionAnnuelle, null,
            function (string $numero, string $code) use ($dossier, $conditions): string {
                $url = config('pssfp.candidature_app_url').'/verifier/'.$code;

                return Pdf::loadView('pdf.lmd.autorisation-depot', [
                    'numero' => $numero, 'code' => $code, 'url' => $url,
                    'qr' => 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(110)->margin(0)->generate($url)),
                    'dossier' => $dossier, 'candidat' => $dossier->auditeur->candidature, 'conditions' => $conditions, 'edite_le' => now('Africa/Douala'),
                ])->setPaper('a4', 'portrait')->output();
            }, $auteur);
    }

    /**
     * Numérote, produit, conserve et scelle un document (RG-059).
     *
     * @param  callable(string, string): string  $produire  numéro, code → PDF
     */
    private function emettre(string $type, Auditeur $auditeur, InscriptionAnnuelle $inscription, ?Deliberation $d, callable $produire, ?User $auteur): DocumentDelivre
    {
        return DB::transaction(function () use ($type, $auditeur, $inscription, $d, $produire, $auteur): DocumentDelivre {
            $annee = (int) substr($inscription->annee_academique, 0, 4);
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('document_'.$type.'_'.$annee)]);
            $prefixe = DocumentDelivre::PREFIXES[$type].'-'.$annee.'-';
            $rang = (int) DocumentDelivre::query()->where('numero', 'like', $prefixe.'%')->count() + 1;
            $numero = $prefixe.str_pad((string) $rang, 6, '0', STR_PAD_LEFT);
            $code = Str::upper(Str::random(12));
            $dossier = $auditeur->candidature;
            $titulaire = trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom);

            $pdf = $produire($numero, $code);
            $chemin = self::DOSSIER.'/'.Str::ulid().'.pdf';
            if (! Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, $pdf)) {
                throw new InvalidArgumentException('Le document n\'a pas pu être enregistré : réessayez dans quelques minutes.');
            }

            $document = DocumentDelivre::query()->create([
                'type' => $type, 'numero' => $numero, 'code_verification' => $code, 'auditeur_id' => $auditeur->id,
                'inscription_annuelle_id' => $inscription->id, 'deliberation_id' => $d?->id,
                'titulaire' => $titulaire, 'chemin' => $chemin, 'sha256' => hash('sha256', $pdf), 'delivre_par' => $auteur?->id,
            ]);
            activity('lmd')->causedBy($auteur)->performedOn($auditeur)->event('document_delivre')
                ->withProperties(['type' => $type, 'numero' => $numero])->log('Document officiel délivré');

            return $document;
        });
    }

    /** Contenu conservé du document (jamais régénéré). */
    public function contenu(DocumentDelivre $document): string
    {
        $contenu = CandidatureObjectStorage::read($document->chemin);
        if ($contenu === null || hash('sha256', $contenu) !== $document->sha256) {
            throw new InvalidArgumentException('Le document est momentanément indisponible : réessayez plus tard.');
        }

        return $contenu;
    }

    public function revoquer(DocumentDelivre $document, string $motif, ?User $auteur): void
    {
        if ($auteur !== null) {
            abort_unless($auteur->can(PermissionsLmd::DOCUMENTS_REVOQUER), 403);
        }
        if (mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Indiquez le motif de la révocation (10 caractères au moins).');
        }
        if ($document->estRevoque()) {
            return;
        }
        $document->forceFill(['revoque_at' => now(), 'revoque_motif' => mb_substr(trim($motif), 0, 500), 'revoque_par' => $auteur?->id])->save();
        activity('lmd')->causedBy($auteur)->performedOn($document->auditeur)->event('document_revoque')
            ->withProperties(['numero' => $document->numero, 'motif' => trim($motif)])->log('Document officiel révoqué');
    }

    /** Une rectification arrêtée révoque les relevés et attestations de la délibération rectifiée. */
    public function revoquerPourRectification(Deliberation $rectifiee, Deliberation $nouvelle): int
    {
        $n = 0;
        foreach (DocumentDelivre::query()->where('deliberation_id', $rectifiee->id)->whereNull('revoque_at')->get() as $doc) {
            $this->revoquer($doc, 'Délibération rectifiée par le '.$nouvelle->numero_pv, null);
            $n++;
        }

        return $n;
    }

    public function leverRestriction(InscriptionAnnuelle $inscription, string $motif, User $auteur): LeveeRestriction
    {
        abort_unless($auteur->can(PermissionsLmd::DOCUMENTS_LEVER), 403);
        if (mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Indiquez le motif de la levée (10 caractères au moins).');
        }
        $levee = LeveeRestriction::query()->create(['inscription_annuelle_id' => $inscription->id, 'motif' => mb_substr(trim($motif), 0, 500), 'accordee_par' => $auteur->id]);
        activity('lmd')->causedBy($auteur)->performedOn($inscription)->event('restriction_levee')
            ->withProperties(['motif' => trim($motif)])->log('Restriction sur les documents levée');

        return $levee;
    }

    /** En règle (D9) : aucune échéance en retard, ou levée de restriction accordée pour l'inscription. */
    public function enRegle(InscriptionAnnuelle $inscription): bool
    {
        return ! (bool) $inscription->situation()?->enRetard()
            || LeveeRestriction::query()->where('inscription_annuelle_id', $inscription->id)->exists();
    }

    /**
     * Disponibilité d'un document : null si disponible, sinon le motif.
     */
    public function indisponibilite(string $type, Auditeur $auditeur, ?Deliberation $d): ?string
    {
        try {
            $this->verifier($type, $auditeur, $d);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @return array{0: InscriptionAnnuelle, 1: ?ResultatAnnuel}
     *
     * @throws InvalidArgumentException
     */
    private function verifier(string $type, Auditeur $auditeur, ?Deliberation $d): array
    {
        if (! array_key_exists($type, DocumentDelivre::TYPES)) {
            throw new InvalidArgumentException('Document inconnu.');
        }
        if ($type === DocumentDelivre::CERTIFICAT) {
            $inscription = $auditeur->inscriptionCourante()->first() ?? throw new InvalidArgumentException('Aucune inscription en cours.');
            if (! $this->enRegle($inscription)) {
                throw new InvalidArgumentException('Une échéance de vos frais de scolarité est en retard : rapprochez-vous de la scolarité.');
            }

            return [$inscription, null];
        }

        $resultat = $d === null ? null : $this->resultats->publie($auditeur, $d);
        if ($resultat === null) {
            throw new InvalidArgumentException('Vos résultats ne sont pas encore publiés.');
        }
        if ($type === DocumentDelivre::ATTESTATION) {
            if (! in_array($resultat->decision, ['AD', 'AS'], true)) {
                throw new InvalidArgumentException('L\'attestation de réussite n\'est délivrée qu\'aux admis et aux autorisés à soutenir.');
            }
            if (! $this->enRegle($resultat->inscriptionAnnuelle)) {
                throw new InvalidArgumentException('Une échéance de vos frais de scolarité est en retard : rapprochez-vous de la scolarité.');
            }
        }

        return [$resultat->inscriptionAnnuelle, $resultat];
    }

    private function pdf(string $type, string $numero, string $code, Auditeur $auditeur, InscriptionAnnuelle $inscription, ?Deliberation $d, $resultat): string
    {
        $url = config('pssfp.candidature_app_url').'/verifier/'.$code;
        $qr = 'data:image/svg+xml;base64,'.base64_encode((string) QrCode::format('svg')->size(110)->margin(0)->generate($url));
        $vue = match ($type) {
            DocumentDelivre::RELEVE => 'pdf.lmd.releve-notes',
            DocumentDelivre::ATTESTATION => 'pdf.lmd.attestation-reussite',
            default => 'pdf.lmd.certificat-scolarite',
        };

        return Pdf::loadView($vue, [
            'numero' => $numero, 'code' => $code, 'url' => $url, 'qr' => $qr,
            'dossier' => $auditeur->candidature, 'inscription' => $inscription->loadMissing('parcours'),
            'd' => $d, 'resultat' => $resultat, 'ues' => $resultat === null ? [] : $this->resultats->ues($resultat),
            'edite_le' => now('Africa/Douala'),
        ])->setPaper('a4', 'portrait')->output();
    }
}
