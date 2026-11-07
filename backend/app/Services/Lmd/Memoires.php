<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AvisMemoire;
use App\Models\Deliberation;
use App\Models\DocumentDelivre;
use App\Models\DossierMemoire;
use App\Models\EtapeMemoire;
use App\Models\InscriptionAnnuelle;
use App\Models\RapportSimilitude;
use App\Models\ResultatAnnuel;
use App\Models\SuiviMemoire;
use App\Models\User;
use App\Support\CandidatureObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Suivi des mémoires des autorisés à soutenir et attestation d'autorisation de dépôt
 * (spec lot 11 ; D32 à D34 ; RG-061).
 */
final class Memoires
{
    /** Taux de similitude maximal (V-13, provisoire). */
    public const SEUIL_SIMILITUDE = 20.0;

    /** Étapes que l'auditeur dépose lui-même dans son espace. */
    public const DEPOTS_AUDITEUR = ['cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2', 'depot_final'];

    public function __construct(private readonly DocumentsOfficiels $documents) {}

    /** Ouvre un dossier pour chaque autorisé à soutenir d'une délibération arrêtée. @return int dossiers créés */
    public function ouvrirPourDeliberation(Deliberation $d, User $auteur): int
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        if ($d->statut !== Deliberation::ARRETEE) {
            throw new InvalidArgumentException('Seule une délibération arrêtée ouvre les mémoires.');
        }
        $n = 0;
        foreach (ResultatAnnuel::query()->where('deliberation_id', $d->id)->where('decision', 'AS')->with('inscriptionAnnuelle')->get() as $r) {
            if ($this->ouvrir($r->inscriptionAnnuelle, $d)->wasRecentlyCreated) {
                $n++;
            }
        }

        return $n;
    }

    public function ouvrir(InscriptionAnnuelle $inscription, ?Deliberation $d = null): DossierMemoire
    {
        return DB::transaction(function () use ($inscription, $d): DossierMemoire {
            $dossier = DossierMemoire::query()->where('inscription_annuelle_id', $inscription->id)->where('auditeur_id', $inscription->auditeur_id)->first();
            if ($dossier === null) {
                $dossier = (new DossierMemoire)->forceFill(['auditeur_id' => $inscription->auditeur_id, 'inscription_annuelle_id' => $inscription->id, 'deliberation_id' => $d?->id]);
                $dossier->save();
                $ordre = 0;
                foreach (DossierMemoire::ETAPES as $etape => [, $poids]) {
                    EtapeMemoire::query()->create(['dossier_memoire_id' => $dossier->id, 'etape' => $etape, 'ordre' => ++$ordre, 'poids' => $poids]);
                }
            }

            return $dossier;
        });
    }

    /** Dépôt d'un livrable par l'auditeur (PDF, 10 Mo). Le dépôt final exige l'attestation (D33). */
    public function deposer(DossierMemoire $dossier, string $etape, UploadedFile $fichier): EtapeMemoire
    {
        if (! in_array($etape, self::DEPOTS_AUDITEUR, true)) {
            throw new InvalidArgumentException('Cette étape ne se dépose pas depuis l\'espace.');
        }
        if (in_array($dossier->statut, ['soutenu', 'abandon', 'suspendu'], true)) {
            throw new InvalidArgumentException('Le mémoire n\'est plus en rédaction.');
        }
        if (! $fichier->isValid() || $fichier->getMimeType() !== 'application/pdf' || $fichier->getSize() > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('Déposez un PDF de 10 Mo au plus.');
        }
        if ($etape === 'depot_final' && ! $this->attestationValide($dossier)) {
            throw new InvalidArgumentException('Le dépôt final exige l\'attestation d\'autorisation de dépôt.');
        }
        $e = $dossier->etapes()->where('etape', $etape)->firstOrFail();
        if ($e->statut === 'valide') {
            throw new InvalidArgumentException('Cette étape est déjà validée.');
        }
        $contenu = (string) file_get_contents($fichier->getRealPath());
        $chemin = EtapeMemoire::DOSSIER.'/'.Str::ulid().'.pdf';
        if (! Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, $contenu)) {
            throw new InvalidArgumentException('Le document n\'a pas pu être enregistré : réessayez dans quelques minutes.');
        }
        $e->forceFill(['statut' => 'depose', 'document_path' => $chemin, 'document_sha256' => hash('sha256', $contenu)])->save();

        return $e;
    }

    /** Évaluation d'une étape par la scolarité : validée, ajournée (appréciation), dispensée ; note des pré-soutenances. */
    public function evaluer(DossierMemoire $dossier, string $etape, string $statut, ?string $note, ?string $appreciation, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        if (! in_array($statut, ['valide', 'ajourne', 'dispense'], true)) {
            throw new InvalidArgumentException('Décision inconnue.');
        }
        if ($statut === 'ajourne' && mb_strlen(trim((string) $appreciation)) < 5) {
            throw new InvalidArgumentException('Une étape ajournée exige une appréciation.');
        }
        if ($etape === 'depot_final' && $statut === 'valide' && ! $this->attestationValide($dossier)) {
            throw new InvalidArgumentException('Le dépôt final exige l\'attestation d\'autorisation de dépôt.');
        }
        $valeur = null;
        if ($note !== null && trim($note) !== '') {
            $texte = str_replace(',', '.', trim($note));
            if (! is_numeric($texte) || (float) $texte < 0 || (float) $texte > 20) {
                throw new InvalidArgumentException('La note doit être entre 0 et 20.');
            }
            $valeur = number_format((float) $texte, 2, '.', '');
        }
        DB::transaction(function () use ($dossier, $etape, $statut, $valeur, $appreciation, $auteur): void {
            $dossier->etapes()->where('etape', $etape)->firstOrFail()->forceFill([
                'statut' => $statut, 'note' => $valeur, 'appreciation' => $appreciation === null ? null : mb_substr(trim($appreciation), 0, 1000),
                'realisee_at' => $statut === 'valide' ? now() : null, 'evaluee_par' => $auteur->id,
            ])->save();
            $this->mettreAJourStatut($dossier->refresh());
            activity('lmd')->causedBy($auteur)->performedOn($dossier)->event('etape_memoire')
                ->withProperties(['etape' => $etape, 'statut' => $statut])->log('Étape du mémoire évaluée');
        });
    }

    /** Dates prévues des étapes (calendrier du mémoire). @param  array<string, ?string>  $dates  étape → date (Y-m-d) */
    public function planifier(DossierMemoire $dossier, array $dates, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        DB::transaction(function () use ($dossier, $dates): void {
            foreach ($dates as $etape => $date) {
                if (! array_key_exists((string) $etape, DossierMemoire::ETAPES)) {
                    throw new InvalidArgumentException('Étape inconnue.');
                }
                $dossier->etapes()->where('etape', $etape)->update(['date_prevue' => $date === '' ? null : $date]);
            }
        });
    }

    /** Suspension, abandon (motif obligatoire) ou reprise de la rédaction. */
    public function changerStatut(DossierMemoire $dossier, string $statut, ?string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        if (! in_array($statut, ['suspendu', 'abandon', 'en_redaction'], true)) {
            throw new InvalidArgumentException('Statut inconnu.');
        }
        if ($statut !== 'en_redaction' && mb_strlen(trim((string) $motif)) < 5) {
            throw new InvalidArgumentException('Indiquez le motif.');
        }
        $dossier->forceFill(['statut' => $statut, 'statut_motif' => $statut === 'en_redaction' ? null : mb_substr(trim((string) $motif), 0, 500)])->save();
        if ($statut === 'en_redaction') {
            $this->mettreAJourStatut($dossier->refresh());
        }
    }

    /** Contenu d'un livrable déposé, contrôlé par son empreinte. */
    public function contenuEtape(EtapeMemoire $etape): string
    {
        $contenu = $etape->document_path === null ? null : CandidatureObjectStorage::read($etape->document_path);
        if ($contenu === null || hash('sha256', $contenu) !== $etape->document_sha256) {
            throw new InvalidArgumentException('Le document est momentanément indisponible : réessayez plus tard.');
        }

        return $contenu;
    }

    /** Retard d'une étape autorisé par le chef de la scolarité (D34), motivé. */
    public function autoriserRetard(DossierMemoire $dossier, string $etape, string $motif, User $chef): void
    {
        abort_unless($chef->can(PermissionsLmd::MEMOIRES_ATTESTER), 403);
        if (mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Indiquez le motif (10 caractères au moins).');
        }
        $dossier->etapes()->where('etape', $etape)->firstOrFail()
            ->forceFill(['retard_autorise_at' => now(), 'retard_autorise_par' => $chef->id, 'retard_motif' => mb_substr(trim($motif), 0, 500)])->save();
        activity('lmd')->causedBy($chef)->performedOn($dossier)->event('retard_autorise')->withProperties(['etape' => $etape, 'motif' => trim($motif)])->log('Retard d\'étape autorisé');
    }

    /** @param  array{date_suivi: string, mode: string, avancement_estime?: ?int, chapitres?: ?string, observations?: ?string}  $data */
    public function ajouterSuivi(DossierMemoire $dossier, array $data, User $auteur): SuiviMemoire
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE) || $dossier->encadreur_academique_id === $auteur->id, 403);
        $suivi = SuiviMemoire::query()->create([...$data, 'dossier_memoire_id' => $dossier->id, 'auteur_id' => $auteur->id]);
        if (isset($data['avancement_estime'])) {
            $dossier->forceFill(['avancement_estime' => min(100, max(0, (int) $data['avancement_estime']))])->save();
        }

        return $suivi;
    }

    public function ajouterAvis(DossierMemoire $dossier, string $encadreur, bool $favorable, ?string $commentaire, User $auteur): AvisMemoire
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE) || $dossier->encadreur_academique_id === $auteur->id, 403);

        return AvisMemoire::query()->create(['dossier_memoire_id' => $dossier->id, 'encadreur' => $encadreur, 'favorable' => $favorable,
            'commentaire' => $commentaire === null ? null : mb_substr(trim($commentaire), 0, 1000), 'auteur_id' => $auteur->id]);
    }

    public function ajouterRapport(DossierMemoire $dossier, string $outil, string $taux, string $date, User $auteur): RapportSimilitude
    {
        abort_unless($auteur->can(PermissionsLmd::MEMOIRES_SUIVRE), 403);
        $t = (float) str_replace(',', '.', $taux);
        if ($t < 0 || $t > 100) {
            throw new InvalidArgumentException('Le taux est un pourcentage entre 0 et 100.');
        }

        return RapportSimilitude::query()->create(['dossier_memoire_id' => $dossier->id, 'outil' => mb_substr(trim($outil), 0, 120),
            'taux' => number_format($t, 2, '.', ''), 'date_rapport' => $date, 'auteur_id' => $auteur->id]);
    }

    /** @return array{pourcentage: int, estime: ?int, prochaine: ?string, retards: list<string>} */
    public function avancement(DossierMemoire $dossier): array
    {
        $etapes = $dossier->etapes()->get();
        $prochaine = $etapes->first(fn (EtapeMemoire $e): bool => ! in_array($e->statut, ['valide', 'dispense'], true));

        return [
            'pourcentage' => (int) $etapes->whereIn('statut', ['valide', 'dispense'])->sum('poids'),
            'estime' => $dossier->avancement_estime,
            'prochaine' => $prochaine?->libelle(),
            'retards' => $etapes->filter(fn (EtapeMemoire $e): bool => $e->enRetard() || $e->enAttenteDepassee())
                ->map(fn (EtapeMemoire $e): string => $e->libelle())->values()->all(),
        ];
    }

    /**
     * Conditions de délivrance de l'attestation (D33, D34), avec leur état.
     *
     * @return list<array{cle: string, libelle: string, ok: bool, detail: ?string}>
     */
    public function conditions(DossierMemoire $dossier): array
    {
        $etapes = $dossier->etapes()->get()->keyBy('etape');
        $requises = ['cadrage', 'depot_projet', 'presoutenance1', 'presoutenance2'];
        $nonValidees = collect($requises)->reject(fn (string $e): bool => in_array($etapes[$e]->statut ?? null, ['valide', 'dispense'], true))
            ->map(fn (string $e): string => DossierMemoire::ETAPES[$e][0])->values();
        $retards = collect($requises)->filter(fn (string $e): bool => isset($etapes[$e]) && $etapes[$e]->enRetard())
            ->map(fn (string $e): string => DossierMemoire::ETAPES[$e][0])->values();
        $avis = $dossier->avis()->where('encadreur', 'academique')->first();
        $rapport = $dossier->rapports()->first();

        return [
            ['cle' => 'theme', 'libelle' => 'Thème enregistré', 'ok' => trim((string) $dossier->theme) !== '', 'detail' => $dossier->theme],
            ['cle' => 'encadreur', 'libelle' => 'Encadreur académique désigné', 'ok' => trim((string) $dossier->encadreur_academique) !== '', 'detail' => $dossier->encadreur_academique],
            ['cle' => 'etapes', 'libelle' => 'Cadrage, dépôt du projet et deux pré-soutenances validés', 'ok' => $nonValidees->isEmpty(),
                'detail' => $nonValidees->isEmpty() ? null : 'Manquent : '.$nonValidees->implode(', ')],
            ['cle' => 'delais', 'libelle' => 'Étapes validées dans les délais, ou retard autorisé par le chef de la scolarité', 'ok' => $retards->isEmpty(),
                'detail' => $retards->isEmpty() ? null : 'Retard non autorisé : '.$retards->implode(', ')],
            ['cle' => 'avis', 'libelle' => 'Avis favorable de dépôt de l\'encadreur académique', 'ok' => (bool) $avis?->favorable,
                'detail' => $avis === null ? 'Aucun avis' : ($avis->favorable ? 'Favorable' : 'Défavorable').' ('.$avis->created_at?->timezone('Africa/Douala')->format('d/m/Y').')'],
            ['cle' => 'similitude', 'libelle' => 'Rapport de similitude au plus à '.(int) self::SEUIL_SIMILITUDE.' %',
                'ok' => $rapport !== null && (float) $rapport->taux <= self::SEUIL_SIMILITUDE,
                'detail' => $rapport === null ? 'Aucun rapport' : $rapport->outil.', '.number_format((float) $rapport->taux, 1, ',', ' ').' % le '.$rapport->date_rapport->format('d/m/Y')],
        ];
    }

    /** Délivrance par le chef de la scolarité seulement (D34) ; refus motivé par les conditions manquantes. */
    public function delivrerAttestation(DossierMemoire $dossier, User $chef): DocumentDelivre
    {
        abort_unless($chef->can(PermissionsLmd::MEMOIRES_ATTESTER), 403);
        $conditions = $this->conditions($dossier);
        $manquantes = array_values(array_filter($conditions, fn (array $c): bool => ! $c['ok']));
        if ($manquantes !== []) {
            throw new InvalidArgumentException('Conditions manquantes : '.implode(' ; ', array_map(fn (array $c): string => $c['libelle'].($c['detail'] ? ' ('.$c['detail'].')' : ''), $manquantes)).'.');
        }

        return $this->documents->delivrerAutorisationDepot($dossier, $conditions, $chef);
    }

    public function attestationValide(DossierMemoire $dossier): ?DocumentDelivre
    {
        return DocumentDelivre::query()->where('type', DocumentDelivre::AUTORISATION_DEPOT)
            ->where('inscription_annuelle_id', $dossier->inscription_annuelle_id)->whereNull('revoque_at')->first();
    }

    private function mettreAJourStatut(DossierMemoire $dossier): void
    {
        if (in_array($dossier->statut, ['suspendu', 'abandon'], true)) {
            return;
        }
        $etapes = $dossier->etapes()->get()->keyBy('etape');
        $faite = fn (string $e): bool => in_array($etapes[$e]->statut ?? null, ['valide', 'dispense'], true);
        $statut = $faite('soutenance') ? 'soutenu'
            : (collect(array_keys(DossierMemoire::ETAPES))->reject(fn (string $e): bool => $e === 'soutenance')->every($faite) ? 'pret' : 'en_redaction');
        $dossier->forceFill(['statut' => $statut])->save();
    }
}
