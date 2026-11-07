<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\CalendrierAcademique;
use App\Models\DependanceEvenement;
use App\Models\EvenementCalendrier;
use App\Models\RegleEvaluation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dépendances, contrôles et diagramme de Gantt du calendrier (spec lot 12a ; D11, D20, D21).
 * Port des fonctions generer_dependances, anomalies_bloquantes_calendrier et des vues v_gantt,
 * v_dependances_evenement, v_controle_calendrier du dossier « PSSFP Système ».
 */
final class ControlesCalendrier
{
    /** Contrôles qui empêchent la soumission et la signature (D21). */
    public const BLOQUANTS = ['publication_sans_jury', 'rattrapage_avant_publication'];

    /** @return Collection<int, EvenementCalendrier> événements hors phases, non annulés */
    private function evenements(CalendrierAcademique $c): Collection
    {
        return $c->evenements()->where('type', '<>', 'phase')->where('statut', '<>', 'annule')->orderBy('debut_prevu')->orderBy('id')->get();
    }

    /** Liens fixés par les règles académiques ; rejouable, un lien existant n'est pas recréé. @return int liens créés */
    public function genererDependances(CalendrierAcademique $c): int
    {
        $ev = $this->evenements($c);
        $liens = [];
        $max = (int) (RegleEvaluation::pourAnnee($c->annee_academique)->jury['delai_publication_max_jours'] ?? 21);
        $publications = $ev->where('type', 'publication');
        $examens = $ev->whereIn('type', ['examens', 'rattrapage']);
        $jurys = $ev->whereIn('type', EvenementCalendrier::JURYS);

        // R1 (D20) : examens → publication de la même session, dans le délai du niveau II.
        foreach ($examens as $x) {
            $session = $x->type === 'examens' ? 'normale' : 'rattrapage';
            $p = $publications->first(fn (EvenementCalendrier $p): bool => $p->niveau === $x->niveau && $p->session === $session && $p->fin_prevue->gte($x->debut_prevu));
            if ($p !== null) {
                $liens[] = [$x, $p, 0, $x->niveau === 'M2' ? $max : null, 'D20 : publication après les examens, dans le délai du niveau', true];
            }
        }
        // R2 (D11) : publication de la session normale → rattrapage.
        foreach ($ev->where('type', 'rattrapage') as $r) {
            $p = $publications->filter(fn (EvenementCalendrier $p): bool => $p->niveau === $r->niveau && $p->session === 'normale' && $p->debut_prevu->lte($r->fin_prevue))->last();
            if ($p !== null) {
                $liens[] = [$p, $r, 1, null, 'D11 : rattrapage après la publication de la session normale', true];
            }
        }
        // R3 (D20) : examens → jury → publication.
        foreach ($jurys as $j) {
            $x = $examens->filter(fn (EvenementCalendrier $x): bool => ($j->niveau === null || $x->niveau === $j->niveau)
                && ($j->session === null || $x->session === $j->session) && $x->debut_prevu->lte($j->debut_prevu))->sortBy('fin_prevue')->last();
            if ($x !== null) {
                $liens[] = [$x, $j, 0, null, 'D20 : le jury siège après les examens', true];
            }
            foreach ($publications as $p) {
                $meme = ($j->niveau === null || $p->niveau === $j->niveau) && ($j->session === null || $p->session === null || $p->session === $j->session);
                if (! $meme || $p->debut_prevu->lt($j->debut_prevu)) {
                    continue;
                }
                $autreJury = $jurys->contains(fn (EvenementCalendrier $j2): bool => $j2->id !== $j->id && ($j2->niveau === null || $p->niveau === $j2->niveau)
                    && $j2->debut_prevu->gt($j->debut_prevu) && $j2->debut_prevu->lte($p->debut_prevu));
                $examenEntre = $examens->contains(fn (EvenementCalendrier $e): bool => $e->niveau === $p->niveau
                    && $e->debut_prevu->gt($j->debut_prevu) && $e->debut_prevu->lte($p->debut_prevu));
                if (! $autreJury && ! $examenEntre) {
                    $liens[] = [$j, $p, 0, null, 'D20 : publication après le jury', true];
                }
            }
        }
        // R4 : étapes successives des mémoires d'une promotion (deux vagues de soutenances ne s'enchaînent pas).
        foreach ($ev->whereIn('type', ['redaction_memoires', 'depot_memoires', 'pre_soutenance', 'soutenances'])->groupBy(fn ($e) => (string) $e->promotion) as $chaine) {
            $prec = null;
            foreach ($chaine->values() as $e) {
                if ($prec !== null && $prec->type !== 'soutenances') {
                    $liens[] = [$prec, $e, 1, null, 'Mémoires : étapes successives', true];
                }
                $prec = $e;
            }
        }
        // R5 : inscriptions → première période de cours (alerte seulement).
        foreach ($ev->where('type', 'inscriptions') as $i) {
            $cours = $ev->first(fn (EvenementCalendrier $e): bool => $e->type === 'periode_cours' && ($e->niveau === null || $e->niveau === $i->niveau));
            if ($cours !== null) {
                $liens[] = [$i, $cours, 0, null, 'Cours après les inscriptions', false];
            }
        }

        $n = 0;
        foreach ($liens as [$a, $b, $min, $maxJours, $regle, $bloquant]) {
            $lien = DependanceEvenement::query()->firstOrCreate(['predecesseur_id' => $a->id, 'successeur_id' => $b->id], [
                'type_lien' => 'fin_debut', 'decalage_min_jours' => $min, 'decalage_max_jours' => $maxJours, 'regle' => $regle, 'bloquant' => $bloquant,
            ]);
            $n += $lien->wasRecentlyCreated ? 1 : 0;
        }

        return $n;
    }

    /**
     * Liens avec écart, marge et état (trop tôt, trop tard, ok), sur les dates réelles à défaut des prévues.
     *
     * @return list<array{lien: DependanceEvenement, ecart: int, marge: int, etat: string}>
     */
    public function dependances(CalendrierAcademique $c): array
    {
        $ids = $c->evenements()->pluck('id');

        return DependanceEvenement::query()->whereIn('successeur_id', $ids)->with(['predecesseur', 'successeur'])->orderBy('id')->get()
            ->reject(fn (DependanceEvenement $d): bool => $d->predecesseur->statut === 'annule' || $d->successeur->statut === 'annule')
            ->map(function (DependanceEvenement $d): array {
                $p = $d->predecesseur;
                $s = $d->successeur;
                $finP = $p->fin_reelle ?? $p->fin_prevue;
                $debutP = $p->debut_reel ?? $p->debut_prevu;
                $cote = in_array($d->type_lien, ['fin_debut', 'debut_debut'], true) ? ($s->debut_reel ?? $s->debut_prevu) : ($s->fin_reelle ?? $s->fin_prevue);
                $origine = in_array($d->type_lien, ['fin_debut', 'fin_fin'], true) ? $finP : $debutP;
                $ecart = (int) $origine->diffInDays($cote, false);
                $etat = $ecart < $d->decalage_min_jours ? 'trop_tot' : ($d->decalage_max_jours !== null && $ecart > $d->decalage_max_jours ? 'trop_tard' : 'ok');

                return ['lien' => $d, 'ecart' => $ecart, 'marge' => $ecart - $d->decalage_min_jours, 'etat' => $etat];
            })->values()->all();
    }

    /**
     * Contrôles de cohérence (D11, D20, D21).
     *
     * @return list<array{controle: string, evenement: EvenementCalendrier, detail: string, bloquant: bool}>
     */
    public function controles(CalendrierAcademique $c): array
    {
        $ev = $this->evenements($c);
        $examens = $ev->whereIn('type', ['examens', 'rattrapage']);
        $max = (int) (RegleEvaluation::pourAnnee($c->annee_academique)->jury['delai_publication_max_jours'] ?? 21);
        $sortie = [];

        foreach ($ev->where('type', 'publication') as $p) {
            $finExamens = $examens->filter(fn (EvenementCalendrier $x): bool => $x->niveau === $p->niveau && $x->fin_prevue->lte($p->debut_prevu))->max('fin_prevue');
            if ($p->session !== null) {
                $jury = $ev->contains(fn (EvenementCalendrier $j): bool => in_array($j->type, EvenementCalendrier::JURYS, true)
                    && ($j->niveau ?? $p->niveau) === $p->niveau && ($j->session === null || $j->session === $p->session)
                    && $j->debut_prevu->lte($p->debut_prevu) && ($finExamens === null || $j->debut_prevu->gte($finExamens)));
                if (! $jury) {
                    $sortie[] = ['controle' => 'publication_sans_jury', 'evenement' => $p, 'detail' => 'Prévoir le jury de la session avant la publication (D20).', 'bloquant' => true];
                }
            }
            $finMemeSession = $examens->filter(fn (EvenementCalendrier $x): bool => $x->niveau === $p->niveau && $x->fin_prevue->lte($p->debut_prevu)
                && ($x->type === 'examens' ? 'normale' : 'rattrapage') === $p->session)->max('fin_prevue');
            if ($p->niveau === 'M2' && $finMemeSession instanceof CarbonImmutable) {
                $jours = (int) $finMemeSession->diffInDays($p->debut_prevu);
                if ($jours > $max) {
                    $sortie[] = ['controle' => 'publication_tardive', 'evenement' => $p, 'detail' => 'Publication '.$jours.' jours après les examens ; maximum '.$max.' (D20).', 'bloquant' => false];
                }
            }
        }
        foreach ($ev->where('type', 'rattrapage') as $r) {
            $publiee = $ev->contains(fn (EvenementCalendrier $p): bool => $p->type === 'publication' && $p->niveau === $r->niveau
                && $p->session === 'normale' && $p->fin_prevue->lt($r->debut_prevu));
            if (! $publiee) {
                $sortie[] = ['controle' => 'rattrapage_avant_publication', 'evenement' => $r, 'detail' => 'Le rattrapage suppose des résultats publiés et des notes complètes (D11).', 'bloquant' => true];
            }
        }
        foreach ($ev->filter(fn (EvenementCalendrier $e): bool => $e->enRetard()) as $e) {
            $sortie[] = ['controle' => 'retard_execution', 'evenement' => $e, 'detail' => 'Échu le '.$e->fin_prevue->format('d/m/Y').' sans être déclaré réalisé.', 'bloquant' => false];
        }

        return $sortie;
    }

    /** @return list<array{origine: string, libelle: string, detail: string}> */
    public function anomaliesBloquantes(CalendrierAcademique $c): array
    {
        $sortie = [];
        foreach ($this->dependances($c) as $d) {
            if ($d['lien']->bloquant && $d['etat'] !== 'ok') {
                $l = $d['lien'];
                $sortie[] = ['origine' => 'dependance', 'libelle' => $l->predecesseur->libelle.' → '.$l->successeur->libelle,
                    'detail' => $l->regle.' : écart de '.$d['ecart'].' j (minimum '.$l->decalage_min_jours.($l->decalage_max_jours !== null ? ', maximum '.$l->decalage_max_jours : '').').'];
            }
        }
        foreach ($this->controles($c) as $k) {
            if ($k['bloquant']) {
                $sortie[] = ['origine' => $k['controle'], 'libelle' => $k['evenement']->libelle, 'detail' => $k['detail']];
            }
        }

        return $sortie;
    }

    /**
     * Lignes du Gantt : phases (enveloppe de leurs événements) puis événements, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    public function gantt(CalendrierAcademique $c): array
    {
        $tous = $c->evenements()->withCount('modifications')->orderBy('ordre')->orderBy('debut_prevu')->orderBy('id')->get();
        $enfants = $tous->where('type', '<>', 'phase')->groupBy(fn ($e) => (string) $e->parent_id);
        $ligne = fn (EvenementCalendrier $e, bool $phase, ?Collection $sous = null): array => [
            'id' => $e->uuid,
            'phase' => $phase,
            'niveau_hierarchie' => $phase || $e->parent_id === null ? 0 : 1,
            'type' => $e->type,
            'libelle' => $e->libelle,
            'niveau' => $e->niveau,
            'session' => $e->session,
            'debut_prevu' => $phase && $sous?->isNotEmpty() ? $sous->min('debut_prevu') : $e->debut_prevu,
            'fin_prevue' => $phase && $sous?->isNotEmpty() ? $sous->max('fin_prevue') : $e->fin_prevue,
            'debut_signe' => $phase && $sous?->isNotEmpty() ? $sous->min('debut_signe') : $e->debut_signe,
            'fin_signe' => $phase && $sous?->isNotEmpty() ? $sous->max('fin_signe') : $e->fin_signe,
            'debut_reel' => $phase && $sous?->isNotEmpty() ? $sous->min('debut_reel') : $e->debut_reel,
            'fin_reelle' => $phase && $sous?->isNotEmpty() ? ($sous->every(fn ($x) => $x->fin_reelle !== null || $x->statut === 'annule') ? $sous->max('fin_reelle') : null) : $e->fin_reelle,
            'statut' => $e->statut,
            'avancement' => $phase && $sous?->isNotEmpty() ? (int) round($sous->avg(fn ($x) => $x->statut === 'realise' ? 100 : $x->avancement_pct))
                : ($e->statut === 'realise' ? 100 : $e->avancement_pct),
            'jalon' => ! $phase && $e->estJalon(),
            'retard' => ! $phase && $e->enRetard(),
            'glissement' => $phase ? null : $e->glissement(),
            'modifications' => $e->modifications_count,
        ];

        $lignes = [];
        foreach ($tous->where('type', 'phase') as $ph) {
            $sous = $enfants->get((string) $ph->id, collect())->sortBy([['debut_prevu', 'asc'], ['id', 'asc']])->values();
            $lignes[] = $ligne($ph, true, $sous);
            foreach ($sous as $e) {
                $lignes[] = $ligne($e, false);
            }
        }
        foreach ($enfants->get('', collect()) as $e) {
            $lignes[] = $ligne($e, false);
        }

        return $lignes;
    }
}
