<?php

declare(strict_types=1);

namespace App\Services\Acma;

use App\Models\AcmaBareme;
use App\Models\AcmaComplement;
use App\Models\AcmaDossier;
use App\Models\AcmaInscription;
use App\Models\AcmaPaiement;
use App\Models\AcmaRapprochement;
use App\Models\AcmaResultatUe;
use App\Models\Auditeur;
use App\Models\User;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Archives ACMA (lot 15d, spec §6.3, §7.4, §7.5, §7.7) : lecture des valeurs en vigueur et seuls gestes permis sur
 * l'archive gelée.
 *
 * - La scolarité ne modifie jamais l'archive : elle la complète (`acma_complements`, en ajout seul) d'après une pièce.
 *   Le dernier complément d'un champ l'emporte ; tous restent visibles. Ce n'est pas une délibération (décision 1).
 * - L'arbitrage du rapprochement écrit d'abord sa décision (`acma_rapprochements`, ajout seul), puis le lien.
 * - Rien n'est envoyé à personne (décision 4). Le journal ne reçoit que des uuid, jamais d'identité ni de note.
 */
final class ArchivesAcma
{
    public const CONSULTER = 'archives.consulter';

    public const COMPLETER = 'archives.completer';

    public const RAPPROCHER = 'archives.rapprocher';

    public const MOTIF_MINIMUM = 10;

    public function completerDateNaissance(AcmaDossier $dossier, string $date, string $reference, string $motif, User $auteur): AcmaComplement
    {
        $this->autoriser($auteur, self::COMPLETER);
        $jour = CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null;
        if ($jour === null || $jour->format('Y-m-d') !== $date || $jour->year < 1920 || $jour->isFuture()) {
            throw new InvalidArgumentException('Date de naissance invalide.');
        }

        return $this->ajouter($dossier, null, null, 'date_naissance', $date, $reference, $motif, $auteur);
    }

    /** Décision (et moyenne, facultative) transcrite d'un PV signé, pour une inscription sans décision enregistrée (Q3, Q4). */
    public function transcrireDecision(AcmaInscription $inscription, string $decision, ?string $moyenne, string $reference, string $motif, User $auteur): void
    {
        $this->autoriser($auteur, self::COMPLETER);
        if ($inscription->decision !== 'non_enregistree') {
            throw new InvalidArgumentException('La décision de cette inscription est enregistrée dans ACMA : rien à transcrire.');
        }
        if (! in_array($decision, ['admis', 'non_admis'], true)) {
            throw new InvalidArgumentException('Décision inconnue.');
        }
        $valeurMoyenne = null;
        if ($moyenne !== null && trim($moyenne) !== '') {
            $m = (float) str_replace(',', '.', trim($moyenne));
            if (! is_numeric(str_replace(',', '.', trim($moyenne))) || $m < 0 || $m > 20) {
                throw new InvalidArgumentException('La moyenne doit être comprise entre 0 et 20.');
            }
            $valeurMoyenne = number_format($m, 2, '.', '');
        }
        DB::transaction(function () use ($inscription, $decision, $valeurMoyenne, $reference, $motif, $auteur): void {
            $dossier = $inscription->dossier;
            $this->ajouter($dossier, $inscription, null, 'decision', $decision, $reference, $motif, $auteur);
            if ($valeurMoyenne !== null) {
                $this->ajouter($dossier, $inscription, null, 'moyenne', $valeurMoyenne, $reference, $motif, $auteur);
            }
        });
    }

    /** Note retenue entre deux résultats en conflit (fiches fusionnées) : seule elle comptera dans les relevés. */
    public function retenirNote(AcmaResultatUe $resultat, string $reference, string $motif, User $auteur): AcmaComplement
    {
        $this->autoriser($auteur, self::COMPLETER);
        if (! $resultat->en_conflit) {
            throw new InvalidArgumentException('Cette note n\'est pas en conflit.');
        }

        return $this->ajouter($resultat->inscription->dossier, $resultat->inscription, $resultat, 'note_retenue', 'retenue', $reference, $motif, $auteur);
    }

    /** Arbitrage du rapprochement (§6.3) : lier, ne pas lier (personnes différentes) ou écarter le dossier. */
    public function arbitrer(AcmaDossier $dossier, string $decision, ?Auditeur $auditeur, string $motif, User $auteur): void
    {
        $this->autoriser($auteur, self::RAPPROCHER);
        $motif = trim($motif);
        if (! array_key_exists($decision, AcmaRapprochement::DECISIONS)) {
            throw new InvalidArgumentException('Décision d\'arbitrage inconnue.');
        }
        if (mb_strlen($motif) < self::MOTIF_MINIMUM) {
            throw new InvalidArgumentException('Le motif doit compter au moins '.self::MOTIF_MINIMUM.' caractères.');
        }
        if ($decision === 'lie' && $auditeur === null) {
            throw new InvalidArgumentException('Choisissez l\'auditeur à lier.');
        }
        $proposition = AcmaRapprochement::query()->where('dossier_id', $dossier->id)->whereNull('decide_par')->latest('id')->first();
        $statut = ['lie' => 'lie_arbitre', 'non_lie' => 'sans_auditeur', 'ecarte' => 'ecarte'][$decision];

        DB::transaction(function () use ($dossier, $decision, $auditeur, $motif, $auteur, $proposition, $statut): void {
            DB::table('acma_rapprochements')->insert([
                'dossier_id' => $dossier->id,
                'auditeur_id' => $decision === 'lie' ? $auditeur?->id : ($proposition?->auditeur_id ?? $auditeur?->id),
                'cas' => $proposition?->cas,
                'score' => $proposition?->score,
                'decision' => $decision,
                'motif' => $motif,
                'decide_par' => $auteur->getKey(),
                'reprise_id' => null,
                'created_at' => now(),
            ]);
            // Hors mode reprise, le déclencheur n'accepte que ce geste sur un dossier : le lien et son statut.
            DB::table('acma_dossiers')->where('id', $dossier->id)->update([
                'auditeur_id' => $decision === 'lie' ? $auditeur?->id : null,
                'statut_rapprochement' => $statut,
                'updated_at' => now(),
            ]);
        });
        activity(Journaux::ARCHIVES)->causedBy($auteur)->event('rapprochement_decide')
            ->withProperties(['dossier' => $dossier->uuid, 'decision' => $decision, 'cas' => $proposition?->cas, 'auditeur' => $decision === 'lie' ? $auditeur?->uuid : null])
            ->log('Rapprochement d\'un dossier d\'archive ACMA arbitré');
    }

    /** Date de naissance en vigueur : le dernier complément, sinon ACMA. */
    public static function dateNaissance(AcmaDossier $dossier): string
    {
        $complement = self::dernier($dossier->id, 'date_naissance');
        if ($complement !== null) {
            return CarbonImmutable::parse($complement->valeur)->format('d/m/Y').' — '.self::source($complement, 'd\'après');
        }
        if ($dossier->date_naissance !== null) {
            return $dossier->date_naissance->format('d/m/Y');
        }
        if ($dossier->date_naissance_precision === 'vers' && $dossier->annee_naissance !== null) {
            return 'vers '.$dossier->annee_naissance;
        }

        return 'À vérifier (ACMA : '.($dossier->date_naissance_source ?? 'vide').')';
    }

    /** Décision d'archive en vigueur : transcrite d'un PV, sinon celle d'ACMA. @return array{code: string, libelle: string} */
    public static function decision(AcmaInscription $inscription): array
    {
        $complement = self::dernier($inscription->dossier_id, 'decision', $inscription->id);
        if ($complement !== null) {
            return ['code' => $complement->valeur, 'libelle' => AcmaInscription::DECISIONS[$complement->valeur].' — décision '.self::source($complement, 'transcrite du')];
        }
        $code = (string) ($inscription->decision ?? 'non_enregistree');
        $libelle = AcmaInscription::DECISIONS[$code] ?? $code;

        return ['code' => $code, 'libelle' => $code === 'non_enregistree'
            ? $libelle.' ('.(AcmaInscription::MOTIFS_DECISION[(string) $inscription->decision_motif] ?? 'ACMA').')'
            : $libelle.' (enregistrée par ACMA)'];
    }

    public static function moyenne(AcmaInscription $inscription): string
    {
        $complement = self::dernier($inscription->dossier_id, 'moyenne', $inscription->id);
        if ($complement !== null) {
            return self::note((float) $complement->valeur).' — '.self::source($complement, 'transcrite du');
        }
        $m = $inscription->moyenneEnregistree();

        return $m === null ? 'Non enregistrée' : self::note($m).' (enregistrée par ACMA)';
    }

    public static function retenue(AcmaResultatUe $resultat): bool
    {
        return AcmaComplement::query()->where('resultat_id', $resultat->id)->where('champ', 'note_retenue')->exists();
    }

    /**
     * Paiements par année (§7.7) : dû du barème (niveau le plus élevé de l'année), total versé selon ACMA, et l'écart,
     * qui n'est **pas** un arriéré.
     *
     * @return list<array{annee: string, niveau: string, du: int|null, verse: int, ecart: int|null, lignes: int, signalees: int}>
     */
    public static function paiementsParAnnee(AcmaDossier $dossier): array
    {
        $niveaux = [];
        foreach ($dossier->inscriptions as $i) {
            $actuel = $niveaux[$i->annee] ?? null;
            if ($actuel === null || ($actuel === 'FPTC4' && $i->niveau_acma !== 'FPTC4')) {
                $niveaux[$i->annee] = $i->niveau_acma;
            }
        }
        $parAnnee = [];
        foreach ($dossier->paiements as $p) {
            /** @var AcmaPaiement $p */
            $parAnnee[$p->annee]['verse'] = ($parAnnee[$p->annee]['verse'] ?? 0) + (int) $p->total;
            $parAnnee[$p->annee]['lignes'] = ($parAnnee[$p->annee]['lignes'] ?? 0) + 1;
            $parAnnee[$p->annee]['signalees'] = ($parAnnee[$p->annee]['signalees'] ?? 0) + (int) ($p->drapeaux() !== []);
        }
        $annees = array_unique(array_merge(array_keys($niveaux), array_keys($parAnnee)));
        sort($annees);
        $baremes = AcmaBareme::query()->whereIn('annee', $annees)->get()->keyBy(fn (AcmaBareme $b): string => $b->annee.'|'.$b->niveau_acma);
        $lignes = [];
        foreach ($annees as $annee) {
            $niveau = $niveaux[$annee] ?? '';
            $du = $baremes->get($annee.'|'.$niveau)?->du_total;
            $verse = (int) ($parAnnee[$annee]['verse'] ?? 0);
            $lignes[] = ['annee' => (string) $annee, 'niveau' => $niveau, 'du' => $du === null ? null : (int) $du, 'verse' => $verse,
                'ecart' => $du === null ? null : (int) $du - $verse, 'lignes' => (int) ($parAnnee[$annee]['lignes'] ?? 0), 'signalees' => (int) ($parAnnee[$annee]['signalees'] ?? 0)];
        }

        return $lignes;
    }

    public static function note(float $n): string
    {
        return number_format($n, 2, ',', ' ');
    }

    private function ajouter(AcmaDossier $dossier, ?AcmaInscription $inscription, ?AcmaResultatUe $resultat, string $champ, string $valeur, string $reference, string $motif, User $auteur): AcmaComplement
    {
        $motif = trim($motif);
        $reference = trim($reference);
        if (mb_strlen($motif) < self::MOTIF_MINIMUM) {
            throw new InvalidArgumentException('Le motif doit compter au moins '.self::MOTIF_MINIMUM.' caractères.');
        }
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Indiquez la référence de la pièce (PV signé ou dossier papier).');
        }
        $complement = AcmaComplement::query()->create([
            'dossier_id' => $dossier->id, 'inscription_id' => $inscription?->id, 'resultat_id' => $resultat?->id,
            'champ' => $champ, 'valeur' => $valeur, 'motif' => $motif, 'piece_reference' => $reference, 'auteur_id' => $auteur->getKey(),
        ]);
        activity(Journaux::ARCHIVES)->causedBy($auteur)->event('complement_ajoute')
            ->withProperties(['dossier' => $dossier->uuid, 'inscription' => $inscription?->uuid, 'champ' => $champ, 'complement' => $complement->uuid])
            ->log('Complément ajouté à un dossier d\'archive ACMA');

        return $complement;
    }

    private static function dernier(int $dossierId, string $champ, ?int $inscriptionId = null): ?AcmaComplement
    {
        return AcmaComplement::query()->with('auteur:id,name')->where('dossier_id', $dossierId)->where('champ', $champ)
            ->when($inscriptionId !== null, fn ($q) => $q->where('inscription_id', $inscriptionId))->latest('id')->first();
    }

    private static function source(AcmaComplement $c, string $verbe): string
    {
        return "{$verbe} {$c->piece_reference}, le ".$c->created_at->timezone('Africa/Douala')->format('d/m/Y').' par '.($c->auteur?->name ?? 'un compte supprimé');
    }

    private function autoriser(User $auteur, string $permission): void
    {
        if (! $auteur->can($permission)) {
            throw new AuthorizationException('Geste réservé : permission '.$permission.' requise.');
        }
    }
}
