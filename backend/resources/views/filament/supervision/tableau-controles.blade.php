@php
    $lignes = $this->lignes();
    $cellule = 'padding:.35rem .7rem .35rem 0;vertical-align:top;';
    $droite = $cellule.'text-align:right;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
    $couleurs = ['bloquant' => 'danger', 'majeur' => 'warning', 'mineur' => 'gray'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Tableau de bord des contrôles</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Anomalies par contrôle</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">
                    <th scope="col" style="{{ $cellule }}">Contrôle</th><th scope="col" style="{{ $cellule }}">Criticité</th>
                    <th scope="col" style="{{ $droite }}">À traiter</th><th scope="col" style="{{ $droite }}">En retard</th><th scope="col" style="{{ $droite }}">Justifiées</th>
                    <th scope="col" style="{{ $droite }}">Corrigées (30 j)</th><th scope="col" style="{{ $droite }}">Délai moyen</th><th scope="col" style="{{ $droite }}">Relances</th><th scope="col" style="{{ $cellule }}">Dernière exécution</th>
                </tr></thead>
                <tbody>
                    @foreach ($lignes as $l)
                        <tr style="border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;">
                            <th scope="row" style="{{ $cellule }}font-weight:500;">{{ $l['libelle'] }}<br><span class="text-gray-500 dark:text-gray-400" style="font-size:.75rem;font-weight:400;">{{ $l['domaine'] }} · {{ $l['code'] }}</span></th>
                            <td style="{{ $cellule }}"><x-filament::badge :color="$couleurs[$l['criticite']] ?? 'gray'">{{ ucfirst($l['criticite']) }}</x-filament::badge></td>
                            <td style="{{ $droite }}">{{ $l['ouvertes'] }}</td>
                            <td style="{{ $droite }}{{ $l['en_retard'] > 0 ? 'font-weight:600;' : '' }}">{{ $l['en_retard'] }}</td>
                            <td style="{{ $droite }}">{{ $l['justifiees'] }}</td>
                            <td style="{{ $droite }}">{{ $l['corrigees_30j'] }}</td>
                            <td style="{{ $droite }}">{{ $l['delai_moyen'] === null ? '—' : str_replace('.', ',', (string) $l['delai_moyen']).' j' }}</td>
                            <td style="{{ $droite }}">{{ $l['relances'] }}</td>
                            <td style="{{ $cellule }}">
                                @if ($l['erreur'])
                                    <x-filament::badge color="danger">En erreur</x-filament::badge>
                                @elseif ($l['derniere_execution'])
                                    {{ \Carbon\CarbonImmutable::parse($l['derniere_execution'])->setTimezone('Africa/Douala')->format('d/m/Y H:i') }}
                                @else
                                    Jamais
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
