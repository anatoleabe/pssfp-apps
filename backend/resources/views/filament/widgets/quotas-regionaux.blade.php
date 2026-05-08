@php
    $data = $this->getViewData();
    $campagne = $data['campagne'];
    $total = $data['total'];
    $rows = $data['rows'];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Quotas régionaux — Module 5 Admissions
        </x-slot>

        <x-slot name="description">
            @if($campagne)
                Campagne <strong>{{ $campagne->nom }}</strong> · {{ $total }} dossier(s)
            @else
                Aucune campagne ouverte actuellement.
            @endif
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::button
                wire:click="$refresh"
                size="sm"
                color="gray"
                icon="heroicon-o-arrow-path">
                Actualiser
            </x-filament::button>
        </x-slot>

        @if($campagne)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Région</th>
                            <th class="px-3 py-2 text-right">Cible</th>
                            <th class="px-3 py-2 text-right">Actuel</th>
                            <th class="px-3 py-2 text-right">Réel</th>
                            <th class="px-3 py-2 text-right">Écart</th>
                            <th class="px-3 py-2 text-center">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                    @foreach($rows as $row)
                        <tr>
                            <td class="px-3 py-2 font-medium">{{ $row['nom'] }}</td>
                            <td class="px-3 py-2 text-right">
                                {{ $row['target_percent'] !== null ? $row['target_percent'].'%' : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right">{{ $row['count'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $row['real_percent'] }}%</td>
                            <td class="px-3 py-2 text-right">
                                {{ $row['gap_points'] !== null ? $row['gap_points'].' pts' : '—' }}
                            </td>
                            <td class="px-3 py-2 text-center">
                                <x-filament::badge :color="$row['color']">
                                    @switch($row['color'])
                                        @case('success') OK @break
                                        @case('warning') Attention @break
                                        @case('danger') Critique @break
                                        @default —
                                    @endswitch
                                </x-filament::badge>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Vert : écart &lt; 3 pts · Orange : 3-7 pts · Rouge : &gt; 7 pts
                — actualisation automatique toutes les 60 s.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
