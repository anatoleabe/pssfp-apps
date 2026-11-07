<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\CalendrierAcademique;
use App\Models\EvenementCalendrier;
use App\Models\JourNonOuvre;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reprise d'un calendrier déjà signé par le chef de la structure (spec lot 12a, §2.9) : chargé
 * signé, référence figée, dépendances générées ; ses anomalies restent signalées. Essai à blanc
 * par défaut. Refusé si un calendrier existe déjà pour l'année.
 */
final class ImportCalendrier
{
    public function __construct(private readonly Calendriers $calendriers, private readonly ControlesCalendrier $controles, private readonly Actes $actes) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array{annee: string, evenements: int, phases: int, jours: int, dependances: int, actes: int, anomalies: list<string>, alertes: list<string>}
     */
    public function importer(array $document, bool $appliquer): array
    {
        $annee = (string) ($document['annee_academique'] ?? '');
        $evenements = (array) ($document['evenements'] ?? []);
        $phases = array_values((array) ($document['phases'] ?? []));
        if ($annee === '' || $evenements === [] || empty($document['date_debut']) || empty($document['date_fin'])) {
            throw new InvalidArgumentException('Document incomplet : année, bornes et événements attendus.');
        }
        foreach ($evenements as $i => $e) {
            if (! array_key_exists((string) ($e['type'] ?? ''), EvenementCalendrier::TYPES) || ! in_array($e['phase'] ?? null, $phases, true)
                || (string) $e['debut'] < (string) $document['date_debut'] || (string) $e['fin'] > (string) $document['date_fin'] || (string) $e['fin'] < (string) $e['debut']) {
                throw new InvalidArgumentException('Événement '.($i + 1).' invalide : '.($e['libelle'] ?? '?').'.');
            }
        }
        if (CalendrierAcademique::query()->where('annee_academique', $annee)->exists()) {
            throw new InvalidArgumentException('Un calendrier '.$annee.' existe déjà : la reprise ne l\'écrase pas.');
        }
        $bilan = ['annee' => $annee, 'evenements' => count($evenements), 'phases' => count($phases), 'jours' => count((array) ($document['jours_non_ouvres'] ?? [])), 'dependances' => 0, 'actes' => 0, 'anomalies' => [], 'alertes' => []];

        $executer = function () use ($document, $annee, $evenements, $phases, &$bilan): void {
            $c = new CalendrierAcademique(['annee_academique' => $annee, 'date_debut' => $document['date_debut'], 'date_fin' => $document['date_fin'],
                'acte_validation' => $document['acte_validation'] ?? null]);
            $c->forceFill(['version' => 1])->save();
            $ids = [];
            foreach ($phases as $ordre => $libelle) {
                $ph = new EvenementCalendrier(['type' => 'phase', 'libelle' => $libelle, 'ordre' => $ordre + 1, 'debut_prevu' => $document['date_debut'], 'fin_prevue' => $document['date_debut']]);
                $ph->calendrier_id = $c->id;
                $ph->save();
                $ids[$libelle] = $ph->id;
            }
            foreach ($evenements as $e) {
                $ev = new EvenementCalendrier(['type' => $e['type'], 'libelle' => mb_substr((string) $e['libelle'], 0, 255), 'niveau' => $e['niveau'] ?? null,
                    'promotion' => $e['promotion'] ?? null, 'session' => $e['session'] ?? null, 'debut_prevu' => $e['debut'], 'fin_prevue' => $e['fin'],
                    'parent_id' => $ids[$e['phase']]]);
                $ev->calendrier_id = $c->id;
                $ev->save();
            }
            foreach ((array) ($document['jours_non_ouvres'] ?? []) as $j) {
                JourNonOuvre::query()->firstOrCreate(['jour' => $j['jour']], ['libelle' => $j['libelle']]);
            }
            $bilan['dependances'] = $this->controles->genererDependances($c);
            $this->calendriers->figerReference($c);
            $c->forceFill(['statut' => CalendrierAcademique::SIGNE, 'signataire' => (string) ($document['signataire'] ?? 'Le Chef de la structure')])->save();
            $bilan['actes'] = $this->actes->generer($c);
            activity('lmd')->performedOn($c)->event('calendrier_repris')->withProperties(['source' => $document['source'] ?? null])->log('Calendrier signé repris');
            $bilan['anomalies'] = array_map(fn (array $a): string => $a['libelle'].' : '.$a['detail'], $this->controles->anomaliesBloquantes($c));
            $bilan['alertes'] = array_values(array_map(fn (array $k): string => $k['evenement']->libelle.' : '.$k['detail'],
                array_filter($this->controles->controles($c), fn (array $k): bool => $k['controle'] === 'acte_pendant_interruption')));
        };

        if ($appliquer) {
            DB::transaction($executer);
        } else {
            DB::beginTransaction();
            try {
                $executer();
            } finally {
                DB::rollBack();
            }
        }

        return $bilan;
    }
}
