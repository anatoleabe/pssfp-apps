@php
    $periode = $this->choisie();
    $suivi = $periode === null ? null : $this->suivi($periode);
    $libelles = \App\Services\Lmd\ControlesPointage::LIBELLES;
    $groupes = $suivi === null ? collect() : collect($suivi['controles'])->groupBy('controle');
    $cellule = 'padding:.35rem .7rem .35rem 0;vertical-align:top;';
    $entete = 'padding:.35rem .7rem .35rem 0;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
    $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
    $natures = ['CM', 'TD', 'TP'];
@endphp
<x-filament-panels::page>
    <div style="max-width:40rem;">{{ $this->form }}</div>

    @if ($periode === null)
        <p style="font-size:.875rem;">Aucune période de cours dans un calendrier signé.</p>
    @else
        <x-filament::section>
            <x-slot name="heading">Contrôles ({{ count($suivi['controles']) }})</x-slot>
            <div style="font-size:.85rem;">
                @forelse ($groupes as $controle => $lignes)
                    <details @if ($lignes->count() <= 10) open @endif style="margin:.2rem 0 .5rem;">
                        <summary style="cursor:pointer;font-weight:600;">{{ $libelles[$controle] ?? $controle }} ({{ $lignes->count() }})</summary>
                        <ul style="margin:.25rem 0 0 1.1rem;list-style:disc;">
                            @foreach ($lignes as $k)
                                <li style="margin:.1rem 0;">{{ $k['detail'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                @empty
                    <p style="margin:0;">Aucun écart : les pointages de la période sont cohérents.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Heures par enseignant et par cours</x-slot>
            <x-slot name="description">Heures validées face aux heures allouées par la programmation, par nature. Seules les heures validées seront payées.</x-slot>
            @if ($suivi['bilan'] === [])
                <p style="font-size:.85rem;margin:0;">Aucune programmation ni aucun pointage dans la période.</p>
            @else
                <div style="overflow-x:auto;font-size:.85rem;">
                    <table style="width:100%;border-collapse:collapse;">
                        <caption style="{{ $masque }}">Heures enseignées de la période, par enseignant et par cours</caption>
                        <thead><tr class="text-gray-500 dark:text-gray-400" style="text-align:left;font-size:.72rem;text-transform:uppercase;">
                            <th scope="col" style="{{ $entete }}">Enseignant</th><th scope="col" style="{{ $entete }}">Cours</th>
                            @foreach ($natures as $n)<th scope="col" style="{{ $entete }}">{{ $n }} validé / alloué</th>@endforeach
                            <th scope="col" style="{{ $entete }}">Pointé</th><th scope="col" style="{{ $entete }}">À valider</th><th scope="col" style="padding:.35rem 0;">Rejetés</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($suivi['bilan'] as $l)
                                <tr class="border-b border-gray-200 dark:border-white/10">
                                    <td style="{{ $cellule }}">{{ $l['enseignant']->name }}</td>
                                    <td style="{{ $cellule }}">{{ $l['ecue']->code }} <span class="text-gray-500 dark:text-gray-400">{{ $l['ecue']->intitule }}</span></td>
                                    @foreach ($natures as $n)
                                        <td style="{{ $cellule }}">
                                            @if ($l['hors'][$n] > 0)
                                                <span class="fi-color-custom text-custom-700 dark:text-custom-400" style="--c-400:var(--danger-400);--c-700:var(--danger-700);font-weight:600;">{{ $h($l['valide'][$n]) }} / {{ $h($l['alloue'][$n]) }} h (au-delà)</span>
                                            @else
                                                {{ $h($l['valide'][$n]) }} / {{ $h($l['alloue'][$n]) }} h
                                            @endif
                                        </td>
                                    @endforeach
                                    <td style="{{ $cellule }}">{{ $h($l['pointe']) }} h</td>
                                    <td style="{{ $cellule }}">{{ $h($l['a_valider']) }} h</td>
                                    <td style="{{ $cellule }}padding-right:0;">{{ $l['rejetes'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
