<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\DossierPaieEnseignant;
use App\Models\EvenementCalendrier;
use App\Models\PointageEnseignant;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Les huit contrôles du pointage des enseignants d'une période de cours (spec lot 13a, §2.5 ; port de v_controle_paie,
 * sans les contrôles du barème, qui viennent avec l'état de paie du lot 13b). Non bloquants.
 */
final class ControlesPointage
{
    public const LIBELLES = [
        'seance_sans_pointage' => 'Séance passée sans pointage de l\'enseignant',
        'auditeurs_sans_enseignant' => 'Auditeurs présents sans pointage de l\'enseignant',
        'pointage_en_attente' => 'Pointage en attente de validation',
        'remplacant_non_programme' => 'Cours fait par un enseignant non programmé',
        'double_emargement' => 'Pointages qui se chevauchent',
        'seance_plusieurs_enseignants' => 'Séance pointée par plusieurs enseignants',
        'heures_hors_allocation' => 'Heures validées au-delà de l\'allocation',
        'dossier_paie_incomplet' => 'Dossier de paie absent ou incomplet',
    ];

    public function __construct(private readonly PointagesEnseignant $pointages) {}

    /**
     * @param  list<array<string, mixed>>|null  $bilan  bilan déjà calculé par PointagesEnseignant::bilan (évite un second calcul)
     * @return list<array{controle: string, detail: string}>
     */
    public function pour(EvenementCalendrier $periode, ?array $bilan = null): array
    {
        $seances = Seance::query()->whereIn('statut', Seance::ACTIVES)->whereHas('emploiDuTemps', fn ($q) => $q->where('periode_id', $periode->id))
            ->with(['ecue', 'enseignant', 'pointagesEnseignant.enseignant'])
            ->withExists(['pointages as auditeurs_presents' => fn ($q) => $q->where('statut', 'present')])
            ->orderBy('date_seance')->orderBy('debut')->get();
        $allocations = $this->pointages->allocations($periode);
        $pointages = $seances->flatMap(fn (Seance $s) => $s->pointagesEnseignant->each(fn (PointageEnseignant $p) => $p->setRelation('seance', $s)))->values();

        return [
            ...$this->seances($seances),
            ...$this->attentes($pointages),
            ...$this->remplacants($allocations, $pointages),
            ...$this->chevauchements($pointages),
            ...$this->plusieurs($seances),
            ...$this->allocations($bilan ?? $this->pointages->bilan($periode, $allocations)),
            ...$this->dossiers($pointages),
        ];
    }

    /**
     * Contrôles 1 et 2 : séance passée sans pointage ; auditeurs présents sans pointage de l'enseignant.
     *
     * @param  Collection<int, Seance>  $seances
     * @return list<array{controle: string, detail: string}>
     */
    private function seances(Collection $seances): array
    {
        $aujourdhui = now(PointagesEnseignant::FUSEAU)->startOfDay();
        $sortie = [];
        foreach ($seances as $s) {
            $qui = $s->enseignant?->name ?? 'sans enseignant';
            $retenus = $s->pointagesEnseignant->where('statut', '<>', PointageEnseignant::REJETE);
            if ($s->pointagesEnseignant->isEmpty() && $s->date_seance->lt($aujourdhui)) {
                $sortie[] = $this->ligne('seance_sans_pointage', $s->libelle().' ('.$qui.') : aucun pointage de l\'enseignant.');
            }
            if ($s->auditeurs_presents && $retenus->isEmpty()) {
                $sortie[] = $this->ligne('auditeurs_sans_enseignant', $s->libelle().' ('.$qui.') : auditeurs pointés présents, enseignant non pointé ou pointage rejeté.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôle 3 : pointage à valider plus de N jours après la séance.
     *
     * @param  Collection<int, PointageEnseignant>  $pointages
     * @return list<array{controle: string, detail: string}>
     */
    private function attentes(Collection $pointages): array
    {
        $aujourdhui = now(PointagesEnseignant::FUSEAU)->startOfDay();
        $delai = $this->pointages->delai();
        $sortie = [];
        foreach ($pointages->where('statut', PointageEnseignant::A_VALIDER) as $p) {
            $jours = (int) $p->seance->date_seance->diffInDays($aujourdhui);
            if ($jours > $delai) {
                $sortie[] = $this->ligne('pointage_en_attente', $p->seance->libelle().' ('.$p->enseignant->name.') : à valider depuis '.$jours.' jours.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôle 4 : cours fait par un enseignant sans programmation active sur ce cours dans la période.
     *
     * @param  array<string, array<string, float>>  $allocations
     * @param  Collection<int, PointageEnseignant>  $pointages
     * @return list<array{controle: string, detail: string}>
     */
    private function remplacants(array $allocations, Collection $pointages): array
    {
        $sortie = [];
        foreach ($pointages->where('statut', '<>', PointageEnseignant::REJETE) as $p) {
            if ($p->enseignant_id !== $p->seance->enseignant_id && ! isset($allocations[$p->enseignant_id.'-'.$p->seance->maquette_ecue_id])) {
                $sortie[] = $this->ligne('remplacant_non_programme', $p->seance->libelle().' : cours fait par '.$p->enseignant->name.', sans programmation sur ce cours.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôle 5 : deux pointages d'un même enseignant qui se chevauchent.
     *
     * @param  Collection<int, PointageEnseignant>  $pointages
     * @return list<array{controle: string, detail: string}>
     */
    private function chevauchements(Collection $pointages): array
    {
        $sortie = [];
        $complets = $pointages->filter(fn (PointageEnseignant $p): bool => $p->arrivee !== null && $p->depart !== null && $p->statut !== PointageEnseignant::REJETE);
        foreach ($complets->groupBy('enseignant_id') as $liste) {
            $tries = $liste->sortBy(fn (PointageEnseignant $p) => $p->arrivee->getTimestamp())->values();
            for ($i = 1; $i < $tries->count(); $i++) {
                [$a, $b] = [$tries[$i - 1], $tries[$i]];
                if ($b->arrivee->lt($a->depart)) {
                    $sortie[] = $this->ligne('double_emargement', $a->enseignant->name.' : '.$a->seance->libelle().' et '.$b->seance->libelle().' se chevauchent.');
                }
            }
        }

        return $sortie;
    }

    /**
     * Séance pointée (pointages non rejetés) par plusieurs enseignants : une seule sera validée et payée.
     *
     * @param  Collection<int, Seance>  $seances
     * @return list<array{controle: string, detail: string}>
     */
    private function plusieurs(Collection $seances): array
    {
        $sortie = [];
        foreach ($seances as $s) {
            $retenus = $s->pointagesEnseignant->where('statut', '<>', PointageEnseignant::REJETE);
            if ($retenus->count() > 1) {
                $sortie[] = $this->ligne('seance_plusieurs_enseignants', $s->libelle().' : pointée par '.$retenus->map(fn (PointageEnseignant $p): string => $p->enseignant->name)->sort()->implode(' et ').'.');
            }
        }

        return $sortie;
    }

    /**
     * Contrôle 6 : heures validées au-delà des heures allouées, par nature.
     *
     * @param  list<array<string, mixed>>  $bilan
     * @return list<array{controle: string, detail: string}>
     */
    private function allocations(array $bilan): array
    {
        $sortie = [];
        foreach ($bilan as $l) {
            foreach ($l['hors'] as $nature => $hors) {
                if ($hors > 0) {
                    $sortie[] = $this->ligne('heures_hors_allocation', $l['enseignant']->name.', '.$l['ecue']->code.' : '.$nature.' '.$this->h($l['valide'][$nature]).' h validées pour '.$this->h($l['alloue'][$nature]).' h allouées.');
                }
            }
        }

        return $sortie;
    }

    /**
     * Contrôle 7 : enseignant aux heures validées sans dossier de paie complet (NIU et compte).
     *
     * @param  Collection<int, PointageEnseignant>  $pointages
     * @return list<array{controle: string, detail: string}>
     */
    private function dossiers(Collection $pointages): array
    {
        $enseignants = $pointages->where('statut', PointageEnseignant::VALIDE)->where('ligne_paie_id', null)->pluck('enseignant')->unique('id');
        $dossiers = DossierPaieEnseignant::query()->whereIn('enseignant_id', $enseignants->pluck('id'))->get()->keyBy('enseignant_id');
        $sortie = [];
        foreach ($enseignants->sortBy('name') as $e) {
            /** @var User $e */
            $d = $dossiers->get($e->id);
            if ($d === null) {
                $sortie[] = $this->ligne('dossier_paie_incomplet', $e->name.' : pas de dossier de paie.');
            } elseif (! $d->estComplet()) {
                $sortie[] = $this->ligne('dossier_paie_incomplet', $e->name.' : '.implode(' et ', $d->manques()).' manquant(s).');
            }
        }

        return $sortie;
    }

    private function h(float $h): string
    {
        return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',');
    }

    /** @return array{controle: string, detail: string} */
    private function ligne(string $controle, string $detail): array
    {
        return ['controle' => $controle, 'detail' => $detail];
    }
}
