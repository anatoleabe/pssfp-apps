@php
    $options = $this->options();
    $dimensions = $this->regroupements();
    $tableau = app(\App\Services\Scolarite\AnalyseFinancesExcel::class)->tableau($this->lignesAnalyse(), $dimensions);
    $colonnes = \App\Services\Scolarite\AnalyseFinancesExcel::COLONNES;
    $fcfa = fn (?int $m): string => $m === null ? '—' : \App\Services\Scolarite\LibelleSituation::fcfa($m);
    $valeur = fn (string $k, $v): string => match (true) {
        $k === 'taux' => $v === null ? '—' : number_format($v, 1, ',', ' ').' %',
        in_array($k, ['inscrits', 'a_jour', 'en_retard', 'soldes'], true) => (string) $v,
        default => $fcfa($v),
    };
    $cellule = 'padding:.35rem .7rem .35rem 0;vertical-align:top;';
    $droite = $cellule.'text-align:right;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
    $entete = 'text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;';
    $ligne = 'border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;';
    $champ = 'display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;';
    $select = 'border-radius:.5rem;border:1px solid rgba(120,113,108,.4);padding:.35rem .5rem;font-size:.85rem;background:transparent;';
    $listes = [
        ['annee', 'Année académique', $options['annees'], 'Toutes'], ['campagne', 'Promotion', $options['campagnes'], 'Toutes'],
        ['niveau', 'Niveau', $options['niveaux'], 'Tous'], ['parcours', 'Parcours', $options['parcours'], 'Tous'],
        ['statut', 'Statut de l\'auditeur', $options['statuts'], 'Tous'], ['situation', 'Situation', \App\Services\Scolarite\LibelleSituation::LIBELLES, 'Toutes'],
        ['financement', 'Financement', \App\Services\Scolarite\IndicateursFinanciers::FINANCEMENTS, 'Tous'],
    ];
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Périmètre et regroupement</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            @foreach ($listes as [$propriete, $libelle, $choix, $tous])
                <label style="{{ $champ }}">{{ $libelle }}
                    <select wire:model.live="{{ $propriete }}" style="{{ $select }}">
                        <option value="">{{ $tous }}</option>
                        @foreach ($choix as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                    </select>
                </label>
            @endforeach
            <label style="display:flex;gap:.4rem;align-items:center;font-size:.8rem;">
                <input type="checkbox" wire:model.live="retires"> Inclure les dossiers retirés
            </label>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;margin-top:1rem;">
            <label style="{{ $champ }}">Lignes par
                <select wire:model.live="lignesPar" style="{{ $select }}">
                    @foreach (\App\Services\Scolarite\IndicateursFinanciers::DIMENSIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Puis par (facultatif)
                <select wire:model.live="puisPar" style="{{ $select }}">
                    <option value="">—</option>
                    @foreach (\App\Services\Scolarite\IndicateursFinanciers::DIMENSIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
        </div>
        <p style="font-size:.78rem;margin:.75rem 0 0;" class="text-gray-500 dark:text-gray-400">
            Mêmes calculs que les fiches : montants sur tout l'argent enregistré (dont à confirmer), effectifs sur l'argent confirmé. La ligne de total est recalculée sur tout le périmètre ; les taux ne s'additionnent pas.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Tableau croisé ({{ $this->lignesAnalyse()->count() }} inscriptions)</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;" data-testid="analyse-tableau">
                <caption style="{{ $masque }}">Analyse financière par {{ implode(' puis ', array_map(fn ($d) => mb_strtolower(\App\Services\Scolarite\IndicateursFinanciers::DIMENSIONS[$d]), $dimensions)) }}</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                    @foreach ($dimensions as $d)<th scope="col" style="{{ $cellule }}">{{ \App\Services\Scolarite\IndicateursFinanciers::DIMENSIONS[$d] }}</th>@endforeach
                    @foreach ($colonnes as $libelle)<th scope="col" style="{{ $droite }}">{{ $libelle }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach ($tableau as $i => $groupe)
                        @php($total = $i === count($tableau) - 1)
                        <tr style="{{ $ligne }}{{ $total ? 'font-weight:600;' : '' }}">
                            @foreach ($groupe['cles'] as $k => $cle)
                                @if ($k === 0)<th scope="row" style="{{ $cellule }}{{ $total ? '' : 'font-weight:400;' }}">{{ $cle }}</th>@else<td style="{{ $cellule }}">{{ $cle }}</td>@endif
                            @endforeach
                            @foreach ($colonnes as $k => $libelle)<td style="{{ $droite }}">{{ $valeur($k, $groupe['indicateurs'][$k]) }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
