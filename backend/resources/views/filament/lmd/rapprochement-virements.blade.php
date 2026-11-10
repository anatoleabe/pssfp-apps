@php
    $anomalies = $this->anomalies();
    $situations = \App\Services\Lmd\RapprochementVirements::SITUATIONS;
    $fcfa = fn (int $m): string => \App\Services\Lmd\EtatsPaie::fcfa($m);
    $cellule = 'padding:.4rem .8rem .4rem 0;vertical-align:top;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Écarts à traiter ({{ count($anomalies) }})</x-slot>
        <x-slot name="description">Virés partiels, virés en trop, virés sans état, et enseignants non virés d'un état payé. Un écart se corrige en annulant l'avis erroné ou en enregistrant le virement manquant, depuis la fiche de l'état.</x-slot>
        @if ($anomalies === [])
            <p style="font-size:.875rem;margin:0;">Aucun écart : chaque virement enregistré correspond au net dû.</p>
        @else
            <div style="overflow-x:auto;font-size:.875rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Écarts entre la paie des enseignants et les virements reçus</caption>
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400" style="text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">
                            <th scope="col" style="{{ $cellule }}">État</th>
                            <th scope="col" style="{{ $cellule }}">Enseignant</th>
                            <th scope="col" style="{{ $cellule }}">Situation</th>
                            <th scope="col" style="{{ $cellule }}text-align:right;">Net dû</th>
                            <th scope="col" style="{{ $cellule }}text-align:right;">Viré</th>
                            <th scope="col" style="{{ $cellule }}text-align:right;">Écart</th>
                            <th scope="col" style="{{ $cellule }}">Références</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($anomalies as $a)
                            <tr style="border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;">
                                <td style="{{ $cellule }}">
                                    @if ($a['etat_uuid'])
                                        <a href="{{ \App\Filament\Resources\EtatPaieResource::getUrl('view', ['record' => $a['etat_uuid']]) }}" class="text-primary-600 dark:text-primary-400" style="text-decoration:underline;">{{ $a['etat'] }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td style="{{ $cellule }}">{{ $a['enseignant'] }}</td>
                                <td style="{{ $cellule }}">
                                    <x-filament::badge :color="$a['situation'] === \App\Services\Lmd\RapprochementVirements::NON_VIRE ? 'warning' : 'danger'">{{ $situations[$a['situation']] ?? $a['situation'] }}</x-filament::badge>
                                </td>
                                <td style="{{ $cellule }}text-align:right;">{{ $fcfa($a['net_du']) }}</td>
                                <td style="{{ $cellule }}text-align:right;">{{ $fcfa($a['vire']) }}</td>
                                <td style="{{ $cellule }}text-align:right;">{{ ($a['ecart'] > 0 ? '+' : '').$fcfa($a['ecart']) }}</td>
                                <td style="{{ $cellule }}">{{ $a['references'] !== '' ? $a['references'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
