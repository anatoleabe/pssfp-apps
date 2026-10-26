<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Candidature;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * Reporte sur les dossiers déjà tranchés le motif ou le commentaire envoyé
 * avec la décision, lu dans le journal d'activité.
 *
 * Avant le 26/10/2026, ces textes ne vivaient que dans le journal : sur le
 * dossier pour une décision unitaire, sur le lot (liste de numéros) pour une
 * décision groupée. Seule la DERNIÈRE décision de chaque dossier compte, et
 * seulement si elle correspond à son statut actuel : un dossier refusé, remis
 * en examen puis accepté ne doit pas hériter du motif de refus.
 *
 * Seuls les champs encore vides sont remplis : la commande peut être rejouée.
 * Simulation par défaut (textes affichés pour relecture) ; `--appliquer` pour écrire.
 */
final class ReprendreTextesDecision extends Command
{
    protected $signature = 'candidatures:reprendre-textes-decision
                            {--appliquer : Écrire en base (sinon simulation)}';

    protected $description = 'Reporte motifs de refus et commentaires d\'admission du journal vers les dossiers';

    /** Événement de décision → [statut produit, propriété portant le texte]. */
    private const EVENEMENTS = [
        'candidature_accepted' => [Candidature::STATUT_ACCEPTE, 'internal_comment'],
        'candidature_accepted_bulk' => [Candidature::STATUT_ACCEPTE, null],
        'candidature_refused' => [Candidature::STATUT_REFUSE, 'motif'],
        'candidature_refused_bulk' => [Candidature::STATUT_REFUSE, 'motif'],
    ];

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');

        $candidatures = Candidature::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('statut', Candidature::STATUT_REFUSE)->whereNull('decision_motif'))
                ->orWhere(fn ($q) => $q->where('statut', Candidature::STATUT_ACCEPTE)->whereNull('decision_commentaire')))
            ->get(['id', 'numero_dossier', 'statut', 'decision_motif', 'decision_commentaire']);

        $dernieres = $this->dernieresDecisions($candidatures->pluck('id', 'numero_dossier')->all());

        $remplis = [];
        foreach ($candidatures as $candidature) {
            $decision = $dernieres[$candidature->id] ?? null;
            // Décision la plus récente d'un autre sens que le statut : on ne devine pas.
            if ($decision === null || $decision['statut'] !== $candidature->statut || ! filled($decision['texte'])) {
                continue;
            }

            $colonne = $candidature->statut === Candidature::STATUT_REFUSE ? 'decision_motif' : 'decision_commentaire';
            $remplis[] = [$candidature->numero_dossier, $colonne, $decision['texte']];
            if ($appliquer) {
                $candidature->forceFill([$colonne => $decision['texte']])->saveQuietly();
            }
        }

        if (! $appliquer && $remplis !== []) {
            // Le champ d'admission s'intitulait « Commentaire interne » : relire
            // avant d'écrire, ces textes deviennent visibles dans l'espace candidat.
            $this->table(['Dossier', 'Colonne', 'Texte'], $remplis);
        }

        $this->info(sprintf(
            '%d dossier(s) tranché(s) sans texte, %d complété(s) depuis le journal%s.',
            $candidatures->count(),
            count($remplis),
            $appliquer ? '' : ' (simulation — relancer avec --appliquer)',
        ));

        return self::SUCCESS;
    }

    /**
     * Dernière décision journalisée de chaque dossier, unitaire ou groupée.
     *
     * @param  array<string, int>  $idsParNumero
     * @return array<int, array{statut: string, texte: ?string}>
     */
    private function dernieresDecisions(array $idsParNumero): array
    {
        if ($idsParNumero === []) {
            return [];
        }

        $morph = (new Candidature)->getMorphClass();
        $dernieres = [];

        Activity::query()
            ->whereIn('event', array_keys(self::EVENEMENTS))
            ->orderBy('id')
            ->each(function (Activity $activite) use (&$dernieres, $idsParNumero, $morph): void {
                [$statut, $propriete] = self::EVENEMENTS[$activite->event];
                $texte = $propriete !== null ? $activite->properties->get($propriete) : null;
                $decision = ['statut' => $statut, 'texte' => filled($texte) ? (string) $texte : null];

                if (str_ends_with((string) $activite->event, '_bulk')) {
                    foreach ((array) $activite->properties->get('numeros', []) as $numero) {
                        if (isset($idsParNumero[(string) $numero])) {
                            $dernieres[$idsParNumero[(string) $numero]] = $decision;
                        }
                    }

                    return;
                }

                if ($activite->subject_type === $morph && in_array((int) $activite->subject_id, $idsParNumero, true)) {
                    // Parcours chronologique : la décision la plus récente l'emporte.
                    $dernieres[(int) $activite->subject_id] = $decision;
                }
            });

        return $dernieres;
    }
}
