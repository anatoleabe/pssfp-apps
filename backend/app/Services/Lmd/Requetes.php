<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\Auditeur;
use App\Models\Deliberation;
use App\Models\EtapeSaisie;
use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Jury;
use App\Models\MaquetteEcue;
use App\Models\Requete;
use App\Models\RequeteEvenement;
use App\Models\RequetePiece;
use App\Models\SeanceRequetes;
use App\Models\User;
use App\Support\CandidatureObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Requêtes des auditeurs (spec lot 8 ; D26 à D28). Chaque étape écrit un événement
 * (ajout seul). Une note ne change qu'à la validation définitive du PV de séance.
 */
final class Requetes
{
    public const TAILLE_MAX = 5 * 1024 * 1024;

    private const TYPES_PIECE = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private readonly SaisieNotes $saisie) {}

    /**
     * ECUE sur lesquels l'auditeur peut déposer une requête de note, avec les notes visables.
     *
     * @return list<array{ecue: MaquetteEcue, session: string, notes: list<string>}>
     */
    public function cibles(Auditeur $auditeur): array
    {
        $inscription = $auditeur->inscriptionCourante()->first();
        if ($inscription === null) {
            return [];
        }
        $cibles = [];
        $ecues = MaquetteEcue::query()->whereIn('maquette_ue_id', $inscription->inscriptionsPedagogiques()->select('maquette_ue_id'))
            ->with('etapes')->orderBy('code')->get();
        foreach ($ecues as $ecue) {
            foreach ([EtapeSaisie::NORMALE, EtapeSaisie::RATTRAPAGE] as $session) {
                $notes = $ecue->etapes->where('session', $session)->where('statut', EtapeSaisie::VERROUILLEE)->pluck('etape')->values()->all();
                if ($notes !== [] && ! $this->horsDelai($inscription, $session)) {
                    $cibles[] = ['ecue' => $ecue, 'session' => $session, 'notes' => $notes];
                }
            }
        }

        return $cibles;
    }

    /**
     * Dépôt (RG-055) : contrôles de recevabilité, accusé de réception (numéro, empreinte), cotation automatique.
     *
     * @param  array{type: string, maquette_ecue_id?: ?int, session?: ?string, note_visee?: ?string, motif?: ?string, type_document?: ?string, description: string}  $data
     * @param  list<UploadedFile>  $pieces
     */
    public function deposer(Auditeur $auditeur, array $data, array $pieces): Requete
    {
        $inscription = $auditeur->inscriptionCourante()->first() ?? throw new InvalidArgumentException('Aucune inscription en cours.');
        $description = trim($data['description'] ?? '');
        if (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
            throw new InvalidArgumentException('Décrivez votre requête (10 à 2 000 caractères).');
        }
        if (count($pieces) > 5) {
            throw new InvalidArgumentException('Cinq pièces au plus.');
        }
        foreach ($pieces as $piece) {
            $this->controlerPiece($piece);
        }

        $champs = ['type' => $data['type'], 'description' => $description];
        if ($data['type'] === Requete::NOTE) {
            $champs += $this->recevabiliteNote($auditeur, $inscription, $data);
            if ($pieces === [] && in_array($champs['motif'], ['erreur_report', 'absence_note'], true)) {
                // La fiche du PSSFP demande une pièce pour ces motifs (copie, convocation, attestation).
                throw new InvalidArgumentException('Joignez au moins une pièce justificative pour ce motif.');
            }
        } elseif ($data['type'] === Requete::DOCUMENT) {
            if (! array_key_exists((string) ($data['type_document'] ?? ''), Requete::DOCUMENTS)) {
                throw new InvalidArgumentException('Choisissez le document demandé.');
            }
            $champs['type_document'] = $data['type_document'];
        } else {
            throw new InvalidArgumentException('Type de requête inconnu.');
        }

        return DB::transaction(function () use ($auditeur, $inscription, $champs, $pieces): Requete {
            $annee = (int) CarbonImmutable::now('Africa/Douala')->format('Y');
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('requete_'.$annee)]);
            $rang = Requete::query()->where('numero', 'like', "RQ-{$annee}-%")->count() + 1;
            $stockees = array_map(fn (UploadedFile $f): array => $this->stocker($f), $pieces);
            $sceau = hash('sha256', json_encode([$champs, $auditeur->id, array_column($stockees, 'sha256')], JSON_THROW_ON_ERROR));

            $requete = Requete::query()->create([...$champs, 'numero' => sprintf('RQ-%d-%06d', $annee, $rang), 'auditeur_id' => $auditeur->id,
                'inscription_annuelle_id' => $inscription->id, 'sha256' => $sceau]);
            foreach ($stockees as $s) {
                RequetePiece::query()->create(['requete_id' => $requete->id, 'nature' => 'piece_auditeur', ...$s]);
            }
            $this->evenement($requete, 'deposee', 'Requête déposée — accusé de réception '.$requete->numero, null, null);
            $this->coterAutomatiquement($requete);

            return $requete->refresh();
        });
    }

    public function annuler(Requete $requete, Auditeur $auditeur): void
    {
        if ($requete->auditeur_id !== $auditeur->id) {
            abort(404);
        }
        if (! in_array($requete->statut, ['deposee', 'cotee'], true)) {
            throw new InvalidArgumentException('La requête est déjà en vérification : elle ne s\'annule plus.');
        }
        $this->passer($requete, 'annulee', 'Requête annulée par l\'auditeur', null, null);
    }

    /** Cotation automatique : l'agent de scolarité actif le moins chargé (D27). */
    public function coterAutomatiquement(Requete $requete): ?User
    {
        $agent = User::role('scolarite_agent')->get()
            ->sortBy(fn (User $u): array => [Requete::query()->where('agent_id', $u->id)->whereIn('statut', Requete::OUVERTS)->count(), $u->id])
            ->first();
        if ($agent === null) {
            return null;
        }
        $requete->forceFill(['agent_id' => $agent->id])->save();
        $this->passer($requete, $requete->type === Requete::NOTE ? 'cotee' : 'en_traitement', 'Requête confiée à un agent de la scolarité', 'Cotation automatique : '.$agent->name, null);

        return $agent;
    }

    public function coter(Requete $requete, User $agent, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_COTER), 403);
        if (! in_array($requete->statut, ['deposee', 'cotee', 'en_instruction', 'en_traitement'], true)) {
            throw new InvalidArgumentException('Cette requête ne se cote plus à ce stade.');
        }
        if (! $agent->can(PermissionsLmd::REQUETES_TRAITER)) {
            throw new InvalidArgumentException('Ce compte ne traite pas les requêtes.');
        }
        $requete->forceFill(['agent_id' => $agent->id])->save();
        $statut = $requete->type === Requete::NOTE ? ($requete->statut === 'deposee' ? 'cotee' : $requete->statut) : 'en_traitement';
        $this->passer($requete, $statut, 'Requête confiée à un agent de la scolarité', 'Cotation manuelle : '.$agent->name, $auteur);
    }

    public function irrecevable(Requete $requete, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_COTER), 403);
        if (! in_array($requete->statut, ['deposee', 'cotee', 'en_instruction'], true) || mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Motif obligatoire (10 caractères au moins), avant l\'inscription au jury.');
        }
        $requete->forceFill(['decision_motif' => mb_substr(trim($motif), 0, 1000)])->save();
        $this->passer($requete, 'irrecevable', 'Requête déclarée irrecevable : '.trim($motif), null, $auteur);
    }

    /** Élément de vérification réuni par l'agent coté (D27, D28), avec son constat. */
    public function ajouterElement(Requete $requete, string $nature, ?UploadedFile $fichier, string $constat, User $agent): RequetePiece
    {
        $this->agentCote($requete, $agent);
        if (! array_key_exists($nature, RequetePiece::NATURES) || $nature === 'piece_auditeur') {
            throw new InvalidArgumentException('Nature d\'élément inconnue.');
        }
        if ($fichier === null && trim($constat) === '') {
            throw new InvalidArgumentException('Joignez la pièce ou consignez votre constat.');
        }
        if ($fichier !== null) {
            $this->controlerPiece($fichier);
        }

        return DB::transaction(function () use ($requete, $nature, $fichier, $constat, $agent): RequetePiece {
            if ($requete->statut === 'cotee') {
                $this->passer($requete, 'en_instruction', 'Requête en vérification', null, $agent);
            }
            $s = $fichier === null ? ['chemin' => '', 'nom' => '', 'sha256' => str_repeat('0', 64)] : $this->stocker($fichier);

            return RequetePiece::query()->create(['requete_id' => $requete->id, 'nature' => $nature, ...$s,
                'constat' => trim($constat) === '' ? null : mb_substr(trim($constat), 0, 1000), 'ajoute_par' => $agent->id]);
        });
    }

    public function marquerInstruite(Requete $requete, User $agent): void
    {
        $this->agentCote($requete, $agent);
        if (! in_array($requete->statut, ['cotee', 'en_instruction'], true)) {
            throw new InvalidArgumentException('Cette requête n\'est pas en vérification.');
        }
        if (! $requete->pieces()->where('nature', 'photocopie_pv_notes')->where('chemin', '!=', '')->exists()) {
            throw new InvalidArgumentException('Élément manquant : la photocopie du PV des notes (D28).');
        }
        $this->passer($requete, 'instruite', 'Vérification terminée', null, $agent);
    }

    public function creerSeance(Jury $jury, string $date, User $auteur): SeanceRequetes
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_JURY), 403);
        if ($jury->nature !== 'requetes') {
            throw new InvalidArgumentException('Une séance siège avec un jury des requêtes désigné.');
        }

        return SeanceRequetes::query()->create(['jury_id' => $jury->id, 'tenue_le' => $date]);
    }

    public function inscrire(Requete $requete, SeanceRequetes $seance, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_JURY), 403);
        if ($requete->statut !== 'instruite' || $seance->estClose()) {
            throw new InvalidArgumentException('Seule une requête instruite s\'inscrit, à une séance non close.');
        }
        $requete->forceFill(['seance_requetes_id' => $seance->id, 'ordre_seance' => (int) $seance->requetes()->max('ordre_seance') + 1])->save();
        $this->passer($requete, 'au_jury', 'Inscrite au jury des requêtes du '.$seance->tenue_le->format('d/m/Y'), null, $auteur);
    }

    /** Mention du jury (D27) ; « ajournée » renvoie en instruction. */
    public function statuer(Requete $requete, string $mention, ?string $noteRetenue, string $motif, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_JURY), 403);
        if ($requete->statut !== 'au_jury' || $requete->seance?->estClose()) {
            throw new InvalidArgumentException('Seule une requête au jury, en séance non close, se statue.');
        }
        if ($mention === 'ajournee') {
            $requete->forceFill(['seance_requetes_id' => null, 'ordre_seance' => null])->save();
            $this->passer($requete, 'en_instruction', 'Ajournée par le jury : vérification complémentaire', trim($motif), $auteur);

            return;
        }
        if (! array_key_exists($mention, Requete::MENTIONS)) {
            throw new InvalidArgumentException('Mention inconnue.');
        }
        $note = null;
        if ($mention !== 'non_fondee') {
            $texte = str_replace(',', '.', trim((string) $noteRetenue));
            if ($requete->type === Requete::NOTE && (! is_numeric($texte) || (float) $texte < 0 || (float) $texte > 20)) {
                throw new InvalidArgumentException('Indiquez la note retenue, entre 0 et 20.');
            }
            $note = number_format((float) $texte, 2, '.', '');
        }
        if (mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Motivez la décision (10 caractères au moins).');
        }
        $requete->forceFill(['mention' => $mention, 'note_retenue' => $note, 'decision_motif' => mb_substr(trim($motif), 0, 1000)])->save();
        $this->passer($requete, 'statuee', 'Examinée par le jury : '.Requete::MENTIONS[$mention], null, $auteur);
    }

    /** Clôture du PV de séance : refusée tant qu'une requête n'a pas de mention ; ensuite non modifiable. */
    public function cloreSeance(SeanceRequetes $seance, User $auteur): string
    {
        abort_unless($auteur->can(PermissionsLmd::REQUETES_JURY), 403);

        return DB::transaction(function () use ($seance, $auteur): string {
            $seance = SeanceRequetes::query()->lockForUpdate()->findOrFail($seance->id);
            if ($seance->estClose()) {
                throw new InvalidArgumentException('PV déjà clos.');
            }
            $requetes = $seance->requetes()->get();
            if ($requetes->isEmpty() || $requetes->contains(fn (Requete $r): bool => $r->statut !== 'statuee')) {
                throw new InvalidArgumentException('Chaque requête inscrite doit avoir une mention avant la clôture du PV.');
            }
            $annee = (int) $seance->tenue_le->format('Y');
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('pv_requetes_'.$annee)]);
            $rang = SeanceRequetes::query()->where('numero_pv', 'like', "PVR-{$annee}-%")->count() + 1;
            $numero = sprintf('PVR-%d-%04d', $annee, $rang);
            $sceau = hash('sha256', $requetes->map(fn (Requete $r): string => implode('|', [$r->numero, $r->mention, $r->note_retenue, $r->decision_motif]))->implode("\n"));
            $seance->forceFill(['close_at' => now(), 'close_par' => $auteur->id, 'numero_pv' => $numero, 'pv_sha256' => $sceau])->save();

            return $numero;
        });
    }

    /**
     * Validation définitive du PV clos (chef de la commission des requêtes, D28) : les mentions
     * s'appliquent alors aux notes. Celui qui a instruit une requête de la séance ne valide pas.
     */
    public function validerSeance(SeanceRequetes $seance, User $chef): int
    {
        abort_unless($chef->can(PermissionsLmd::REQUETES_VALIDER), 403);

        return DB::transaction(function () use ($seance, $chef): int {
            $seance = SeanceRequetes::query()->lockForUpdate()->findOrFail($seance->id);
            if (! $seance->estClose() || $seance->validee_at !== null) {
                throw new InvalidArgumentException('Seul un PV clos, non encore validé, se valide.');
            }
            $requetes = $seance->requetes()->with(['ecue', 'inscriptionAnnuelle'])->get();
            if ($requetes->contains(fn (Requete $r): bool => $r->agent_id === $chef->id)) {
                throw new InvalidArgumentException('Vous avez instruit une requête de cette séance : la validation revient à une autre personne.');
            }
            $seance->forceFill(['validee_at' => now(), 'validee_par' => $chef->id])->save();
            $appliquees = 0;
            foreach ($requetes as $r) {
                $this->passer($r, 'validee', 'Décision validée par la commission des requêtes', null, $chef);
                if ($r->mention === 'non_fondee' || $r->type !== Requete::NOTE) {
                    $this->passer($r, 'cloturee', 'Requête clôturée : '.Requete::MENTIONS[$r->mention ?? 'non_fondee'], null, $chef);

                    continue;
                }
                $this->appliquer($r, $chef);
                $appliquees++;
            }

            return $appliquees;
        });
    }

    /** Document produit par l'agent pour une demande de document. */
    public function deposerDocument(Requete $requete, UploadedFile $fichier, User $agent): void
    {
        $this->agentCote($requete, $agent);
        if ($requete->type !== Requete::DOCUMENT || $requete->statut !== 'en_traitement') {
            throw new InvalidArgumentException('Seule une demande de document en traitement reçoit un document.');
        }
        if ($fichier->getMimeType() !== 'application/pdf') {
            throw new InvalidArgumentException('Le document délivré est un PDF.');
        }
        $this->controlerPiece($fichier);
        $s = $this->stocker($fichier);
        $requete->forceFill(['document_path' => $s['chemin']])->save();
        $this->passer($requete, 'document_disponible', 'Document disponible dans votre espace', null, $agent);
    }

    public function rejeterDocument(Requete $requete, string $motif, User $agent): void
    {
        $this->agentCote($requete, $agent);
        if ($requete->type !== Requete::DOCUMENT || $requete->statut !== 'en_traitement' || mb_strlen(trim($motif)) < 10) {
            throw new InvalidArgumentException('Motif obligatoire (10 caractères au moins), pour une demande en traitement.');
        }
        $requete->forceFill(['decision_motif' => mb_substr(trim($motif), 0, 1000)])->save();
        $this->passer($requete, 'rejetee', 'Demande rejetée : '.trim($motif), null, $agent);
    }

    private function appliquer(Requete $r, User $chef): void
    {
        $ip = InscriptionPedagogique::query()->where('inscription_annuelle_id', $r->inscription_annuelle_id)
            ->where('maquette_ue_id', $r->ecue->maquette_ue_id)->firstOrFail();
        $this->saisie->corrigerParVoieAutorisee($ip, $r->ecue, (string) $r->session, (string) $r->note_visee, (string) $r->note_retenue, 'Requête '.$r->numero, $chef);
        $this->passer($r, 'appliquee', 'Note corrigée : '.number_format((float) $r->note_retenue, 2, ',', ' ').' / 20', null, $chef);

        $inscription = $r->inscriptionAnnuelle;
        Deliberation::query()->where('annee_academique', $inscription->annee_academique)->where('niveau', $inscription->niveau)
            ->where('session', $r->session === EtapeSaisie::RATTRAPAGE ? 'rattrapage' : 'normale')->where('statut', Deliberation::ARRETEE)
            ->whereNull('a_rectifier_at')
            ->update(['a_rectifier_at' => now(), 'a_rectifier_motif' => 'Requête '.$r->numero.' appliquée après l\'arrêt']);
    }

    /** @return array<string, mixed> */
    private function recevabiliteNote(Auditeur $auditeur, InscriptionAnnuelle $inscription, array $data): array
    {
        $ecue = MaquetteEcue::query()->find((int) ($data['maquette_ecue_id'] ?? 0));
        $session = (string) ($data['session'] ?? '');
        $visee = (string) ($data['note_visee'] ?? '');
        $motif = (string) ($data['motif'] ?? '');
        if (! array_key_exists($motif, Requete::MOTIFS) || ! array_key_exists($visee, Requete::NOTES_VISEES)) {
            throw new InvalidArgumentException('Choisissez la note visée et le motif.');
        }
        $cible = collect($this->cibles($auditeur))->first(fn (array $c): bool => $c['ecue']->id === $ecue?->id && $c['session'] === $session);
        if ($cible === null || ! in_array($visee, $cible['notes'], true)) {
            throw new InvalidArgumentException('Requête hors fenêtre : la note doit être verrouillée et le délai de requête ouvert.');
        }
        $doublon = Requete::query()->where('auditeur_id', $auditeur->id)->where('maquette_ecue_id', $ecue->id)->where('session', $session)
            ->where('note_visee', $visee)->whereIn('statut', Requete::OUVERTS)->exists();
        if ($doublon) {
            throw new InvalidArgumentException('Une requête sur cette note est déjà en cours.');
        }

        return ['maquette_ecue_id' => $ecue->id, 'session' => $session, 'note_visee' => $visee, 'motif' => $motif];
    }

    private function horsDelai(InscriptionAnnuelle $inscription, string $session): bool
    {
        $d = Deliberation::query()->where('annee_academique', $inscription->annee_academique)->where('niveau', $inscription->niveau)
            ->where('session', $session)->where('statut', Deliberation::ARRETEE)->whereNotNull('publiee_at')->first();

        return $d !== null && $d->requetes_jusqu_au !== null && CarbonImmutable::now('Africa/Douala')->startOfDay()->gt($d->requetes_jusqu_au);
    }

    private function controlerPiece(UploadedFile $f): void
    {
        if (! $f->isValid() || $f->getSize() > self::TAILLE_MAX || ! array_key_exists((string) $f->getMimeType(), self::TYPES_PIECE)) {
            throw new InvalidArgumentException('Pièces acceptées : PDF, JPEG ou PNG, 5 Mo au plus chacune.');
        }
    }

    /** @return array{chemin: string, nom: string, sha256: string} */
    private function stocker(UploadedFile $f): array
    {
        $contenu = (string) file_get_contents($f->getRealPath());
        $chemin = RequetePiece::DOSSIER.'/'.Str::ulid().'.'.self::TYPES_PIECE[(string) $f->getMimeType()];
        if (! Storage::disk(CandidatureObjectStorage::DISK)->put($chemin, $contenu)) {
            throw new InvalidArgumentException('La pièce n\'a pas pu être enregistrée : réessayez dans quelques minutes.');
        }

        return ['chemin' => $chemin, 'nom' => mb_substr($f->getClientOriginalName(), 0, 255), 'sha256' => hash('sha256', $contenu)];
    }

    private function agentCote(Requete $requete, User $agent): void
    {
        abort_unless($agent->can(PermissionsLmd::REQUETES_TRAITER), 403);
        if ($requete->agent_id !== $agent->id) {
            throw new InvalidArgumentException('Seul l\'agent à qui la requête est confiée la traite.');
        }
    }

    private function passer(Requete $requete, string $statut, string $libelle, ?string $detail, ?User $auteur): void
    {
        $requete->forceFill(['statut' => $statut])->save();
        $this->evenement($requete, $statut, $libelle, $detail, $auteur);
    }

    private function evenement(Requete $requete, string $statut, string $libelle, ?string $detail, ?User $auteur): void
    {
        RequeteEvenement::query()->create(['requete_id' => $requete->id, 'statut' => $statut, 'libelle' => mb_substr($libelle, 0, 255),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 1000), 'visible_auditeur' => true, 'auteur_id' => $auteur?->id]);
    }
}
