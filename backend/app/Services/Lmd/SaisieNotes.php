<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\AttributionEcue;
use App\Models\EtapeSaisie;
use App\Models\HistoriqueNote;
use App\Models\InscriptionPedagogique;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\RegleEvaluation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saisie des notes par étapes (spec lot 5, § 3.6 à 3.9 ; D11 ; SAI-01 à SAI-04).
 *
 * - Une note ne s'écrit que dans une étape ouverte.
 * - Une valeur déjà saisie ne change qu'avec un motif ; chaque changement entre à l'historique.
 * - L'enseignant ne saisit que le CC et l'assiduité de ses ECUE.
 */
final class SaisieNotes
{
    public const MOTIF_MIN = 5;

    public function __construct(private readonly CalculNotes $calcul) {}

    public function etape(MaquetteEcue $ecue, string $session, string $etape): EtapeSaisie
    {
        if (! array_key_exists($session, EtapeSaisie::SESSIONS) || ! in_array($etape, EtapeSaisie::etapesDe($session), true)) {
            throw new InvalidArgumentException('Étape inconnue pour cette session.');
        }

        return EtapeSaisie::query()->firstOrCreate(['maquette_ecue_id' => $ecue->id, 'session' => $session, 'etape' => $etape]);
    }

    /** Auditeurs inscrits à l'UE de l'ECUE (statut « inscrit »), avec leur dossier. @return Collection<int, InscriptionPedagogique> */
    public function inscrits(MaquetteEcue $ecue): Collection
    {
        return InscriptionPedagogique::query()
            ->where('maquette_ue_id', $ecue->maquette_ue_id)
            ->where('statut', InscriptionPedagogique::INSCRIT)
            ->with('inscriptionAnnuelle.auditeur.candidature')
            ->get()
            ->sortBy(fn (InscriptionPedagogique $i): string => mb_strtoupper((string) $i->inscriptionAnnuelle?->auditeur?->candidature?->nom).' '.$i->inscriptionAnnuelle?->auditeur?->candidature?->prenom)
            ->values();
    }

    public function ouvrir(EtapeSaisie $etape, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::NOTES_VERROUILLER), 403);
        DB::transaction(function () use ($etape, $auteur): void {
            $etape = EtapeSaisie::query()->lockForUpdate()->findOrFail($etape->id);
            if ($etape->statut !== EtapeSaisie::FERMEE) {
                throw new InvalidArgumentException('Seule une étape fermée s\'ouvre ; une étape verrouillée se rouvre avec un motif.');
            }
            if ($etape->session === EtapeSaisie::RATTRAPAGE) {
                $normale = EtapeSaisie::query()->where('maquette_ecue_id', $etape->maquette_ecue_id)
                    ->where('session', EtapeSaisie::NORMALE)->where('etape', EtapeSaisie::EXAMEN)->value('statut');
                if ($normale !== EtapeSaisie::VERROUILLEE) {
                    throw new InvalidArgumentException('Le rattrapage s\'ouvre une fois l\'examen de la session normale verrouillé.');
                }
            }
            $etape->forceFill(['statut' => EtapeSaisie::OUVERTE, 'ouverte_par' => $auteur->id, 'ouverte_at' => now()])->save();
            $this->journal($etape, $auteur, 'etape_ouverte', 'Étape de saisie ouverte');
        });
    }

    /** @throws InvalidArgumentException étape non ouverte ou notes manquantes (matricules cités) */
    public function verrouiller(EtapeSaisie $etape, User $auteur): void
    {
        abort_unless($auteur->can(PermissionsLmd::NOTES_VERROUILLER), 403);
        DB::transaction(function () use ($etape, $auteur): void {
            $etape = EtapeSaisie::query()->lockForUpdate()->with('ecue.maquetteUe')->findOrFail($etape->id);
            if ($etape->statut !== EtapeSaisie::OUVERTE) {
                throw new InvalidArgumentException('Seule une étape ouverte se verrouille.');
            }
            $manquants = $this->manquants($etape);
            if ($manquants !== []) {
                throw new InvalidArgumentException(sprintf('Verrouillage impossible : %d auditeur(s) sans note (%s).',
                    count($manquants), implode(', ', array_slice($manquants, 0, 10)).(count($manquants) > 10 ? '…' : '')));
            }
            $etape->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_par' => $auteur->id, 'verrouillee_at' => now()])->save();
            $this->journal($etape, $auteur, 'etape_verrouillee', 'Étape de saisie verrouillée');
        });
    }

    public function rouvrir(EtapeSaisie $etape, User $auteur, string $motif): void
    {
        abort_unless($auteur->can(PermissionsLmd::NOTES_ROUVRIR), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new InvalidArgumentException('Indiquez le motif de la réouverture (10 caractères au moins).');
        }
        DB::transaction(function () use ($etape, $auteur, $motif): void {
            $etape = EtapeSaisie::query()->lockForUpdate()->findOrFail($etape->id);
            if ($etape->statut !== EtapeSaisie::VERROUILLEE) {
                throw new InvalidArgumentException('Seule une étape verrouillée se rouvre.');
            }
            $etape->forceFill(['statut' => EtapeSaisie::OUVERTE, 'rouverte_par' => $auteur->id, 'rouverte_at' => now(), 'motif_reouverture' => $motif])->save();
            $this->journal($etape, $auteur, 'etape_rouverte', 'Étape de saisie rouverte', ['motif' => $motif]);
        });
    }

    /** L'auteur peut-il saisir cette étape ? */
    public function peutSaisir(EtapeSaisie $etape, User $auteur): bool
    {
        if ($auteur->can(PermissionsLmd::NOTES_SAISIR)) {
            return true;
        }

        return $etape->etape !== EtapeSaisie::EXAMEN
            && $auteur->can(PermissionsLmd::NOTES_ENSEIGNANT)
            && AttributionEcue::query()->where('maquette_ecue_id', $etape->maquette_ecue_id)->where('user_id', $auteur->id)->exists();
    }

    /**
     * Saisie d'un lot de notes pour une étape : tout est contrôlé avant d'écrire.
     *
     * @param  array<int, array{note?: float|int|string|null, presence?: string|null, motif?: string|null}>  $lignes  par id d'inscription pédagogique
     * @return int nombre de notes modifiées (à modifier, en essai à blanc)
     *
     * @throws InvalidArgumentException étape non ouverte, auditeur non inscrit, note hors bornes, motif absent
     */
    public function saisir(EtapeSaisie $etape, array $lignes, User $auteur, bool $appliquer = true): int
    {
        abort_unless($this->peutSaisir($etape, $auteur), 403);

        return DB::transaction(function () use ($etape, $lignes, $auteur, $appliquer): int {
            $etape = EtapeSaisie::query()->lockForUpdate()->with('ecue')->findOrFail($etape->id);
            if ($etape->statut !== EtapeSaisie::OUVERTE) {
                throw new InvalidArgumentException(sprintf('Saisie refusée : étape « %s » %s. Correction par requête ou réouverture motivée.',
                    EtapeSaisie::ETAPES[$etape->etape], $etape->statut === EtapeSaisie::FERMEE ? 'fermée' : 'verrouillée'));
            }
            $inscrits = $this->inscrits($etape->ecue)->keyBy('id');
            $existantes = NoteEcue::query()->where('maquette_ecue_id', $etape->maquette_ecue_id)->where('session', $etape->session)
                ->whereIn('inscription_pedagogique_id', array_keys($lignes))->lockForUpdate()->get()->keyBy('inscription_pedagogique_id');

            $ecritures = [];
            $erreurs = [];
            foreach ($lignes as $id => $ligne) {
                $inscription = $inscrits->get((int) $id);
                if ($inscription === null) {
                    $erreurs[] = "Inscription {$id} : auditeur non inscrit à cette UE.";

                    continue;
                }
                $matricule = (string) $inscription->inscriptionAnnuelle?->auditeur?->candidature?->matricule;
                try {
                    $valeurs = $this->valeurs($etape->etape, $ligne);
                } catch (InvalidArgumentException $e) {
                    $erreurs[] = "{$matricule} : {$e->getMessage()}";

                    continue;
                }
                $note = $existantes->get((int) $id);
                $changements = [];
                foreach ($valeurs as $champ => $valeur) {
                    $avant = $note?->getAttribute($champ);
                    $avant = $avant === null ? null : (string) $avant;
                    $apres = $valeur === null ? null : (is_float($valeur) ? number_format($valeur, 2, '.', '') : (string) $valeur);
                    if ($avant !== $apres) {
                        $changements[$champ] = [$avant, $apres];
                    }
                }
                if ($changements === []) {
                    continue;
                }
                $correction = array_filter($changements, fn (array $c): bool => $c[0] !== null) !== [];
                $motif = trim((string) ($ligne['motif'] ?? ''));
                if ($correction && mb_strlen($motif) < self::MOTIF_MIN) {
                    $erreurs[] = "{$matricule} : une note déjà saisie ne change qu'avec un motif.";

                    continue;
                }
                $ecritures[] = [$inscription, $note, $valeurs, $changements, $correction ? $motif : 'Saisie'];
            }
            if ($erreurs !== []) {
                throw new InvalidArgumentException(implode(' ', array_slice($erreurs, 0, 15)).(count($erreurs) > 15 ? ' …' : ''));
            }
            if (! $appliquer) {
                return count($ecritures);
            }

            foreach ($ecritures as [$inscription, $note, $valeurs, $changements, $motif]) {
                $note ??= new NoteEcue(['inscription_pedagogique_id' => $inscription->id, 'maquette_ecue_id' => $etape->maquette_ecue_id, 'session' => $etape->session]);
                $note->forceFill([...$valeurs, 'modifie_par' => $auteur->id])->save();
                foreach ($changements as $champ => [$avant, $apres]) {
                    HistoriqueNote::query()->create([
                        'note_ecue_id' => $note->id, 'champ' => $champ, 'ancienne_valeur' => $avant,
                        'nouvelle_valeur' => $apres, 'motif' => mb_substr($motif, 0, 500), 'auteur_id' => $auteur->id,
                    ]);
                }
            }

            return count($ecritures);
        });
    }

    /**
     * Matricules des auditeurs attendus sans note pour l'étape. En rattrapage, seuls
     * les éligibles (UE non acquise en session normale) sont attendus.
     *
     * @return list<string>
     */
    public function manquants(EtapeSaisie $etape): array
    {
        $etape->loadMissing('ecue.maquetteUe.ecues', 'ecue.maquetteUe.maquette', 'ecue.maquetteUe.ue');
        $ecue = $etape->ecue;
        $notes = NoteEcue::query()->where('maquette_ecue_id', $ecue->id)->where('session', $etape->session)->get()->keyBy('inscription_pedagogique_id');
        $regle = $etape->session === EtapeSaisie::RATTRAPAGE ? RegleEvaluation::pourAnnee($ecue->maquetteUe->maquette->annee_academique) : null;

        $manquants = [];
        foreach ($this->inscrits($ecue) as $inscription) {
            if ($regle !== null && ! $this->resultat($inscription, $regle)->eligibleRattrapage) {
                continue;
            }
            $n = $notes->get($inscription->id);
            $saisie = match ($etape->etape) {
                EtapeSaisie::CC => $n?->cc !== null,
                EtapeSaisie::ASSIDUITE => $n?->assiduite !== null,
                default => $n?->examen !== null || in_array($n?->presence_examen, [NoteEcue::ABSENT_JUSTIFIE, NoteEcue::ABSENT_NON_JUSTIFIE], true),
            };
            if (! $saisie) {
                $manquants[] = (string) ($inscription->inscriptionAnnuelle?->auditeur?->candidature?->matricule ?? '#'.$inscription->id);
            }
        }

        return $manquants;
    }

    /** Résultat calculé de l'UE d'une inscription pédagogique (provisoire). */
    public function resultat(InscriptionPedagogique $inscription, RegleEvaluation $regle): ResultatUe
    {
        $inscription->loadMissing(['maquetteUe.ecues', 'maquetteUe.ue', 'notes', 'derogationRattrapage']);
        $normales = $inscription->notes->where('session', EtapeSaisie::NORMALE)->keyBy('maquette_ecue_id')->all();
        $rattrapages = $inscription->notes->where('session', EtapeSaisie::RATTRAPAGE)->keyBy('maquette_ecue_id')->all();

        return $this->calcul->ue($inscription->maquetteUe, $regle, $normales, $rattrapages, $inscription->derogationRattrapage !== null);
    }

    /**
     * Valeurs à écrire pour une étape. Examen : une note, ou une absence (sans note).
     *
     * @param  array{note?: float|int|string|null, presence?: string|null}  $ligne
     * @return array<string, string|null>
     */
    private function valeurs(string $etape, array $ligne): array
    {
        $brute = $ligne['note'] ?? null;
        $note = null;
        if ($brute !== null && trim((string) $brute) !== '') {
            $texte = str_replace(',', '.', trim((string) $brute));
            if (! is_numeric($texte) || (float) $texte < 0 || (float) $texte > 20) {
                throw new InvalidArgumentException('la note doit être un nombre entre 0 et 20.');
            }
            $note = number_format(round((float) $texte, 2), 2, '.', '');
        }
        if ($etape !== EtapeSaisie::EXAMEN) {
            return [$etape => $note];
        }
        $presence = $ligne['presence'] ?? null;
        $presence = $presence === '' ? null : $presence;
        if ($presence !== null && ! array_key_exists($presence, NoteEcue::PRESENCES)) {
            throw new InvalidArgumentException('présence inconnue.');
        }
        if ($presence !== null && $presence !== NoteEcue::PRESENT) {
            if ($note !== null) {
                throw new InvalidArgumentException('un absent n\'a pas de note d\'examen.');
            }

            return ['examen' => null, 'presence_examen' => $presence];
        }

        return ['examen' => $note, 'presence_examen' => $note === null ? null : NoteEcue::PRESENT];
    }

    /** @param  array<string, mixed>  $extra */
    private function journal(EtapeSaisie $etape, User $auteur, string $evenement, string $libelle, array $extra = []): void
    {
        activity('lmd')->causedBy($auteur)->performedOn($etape)->event($evenement)
            ->withProperties(['ecue' => $etape->ecue?->code, 'session' => $etape->session, 'etape' => $etape->etape, ...$extra])
            ->log($libelle);
    }
}
