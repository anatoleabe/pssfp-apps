@php
    $periode = $this->choisie();
    $suivi = $periode === null ? null : $this->suivi($periode);
    $libelles = \App\Services\Lmd\ControlesProgrammation::LIBELLES;
    $cellule = 'padding:.35rem .7rem .35rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $entete = 'padding:.35rem .7rem .35rem 0;';
    $tete = 'text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
@endphp
<x-filament-panels::page>
    <div style="max-width:40rem;">{{ $this->form }}</div>

    @if ($periode === null)
        <p style="font-size:.875rem;">Aucune période de cours dans un calendrier signé.</p>
    @else
        <x-filament::section>
            <x-slot name="heading">Contrôles ({{ count($suivi['controles']) }})</x-slot>
            <div style="font-size:.85rem;">
                @forelse ($suivi['controles'] as $k)
                    <p style="margin:.15rem 0;"><strong>{{ $libelles[$k['controle']] ?? $k['controle'] }}</strong> — {{ $k['detail'] }}</p>
                @empty
                    <p class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--success-400);--c-600:var(--success-600);margin:0;">Aucun écart : la programmation de la période est cohérente.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Charge des enseignants</x-slot>
            @if ($suivi['charges'] === [])
                <p style="font-size:.85rem;margin:0;">Aucune programmation active dans la période.</p>
            @else
                <div style="overflow-x:auto;font-size:.85rem;">
                    <table style="width:100%;border-collapse:collapse;">
                        <caption style="{{ $masque }}">Charge des enseignants de la période</caption>
                        <thead><tr style="{{ $tete }}">
                            <th scope="col" style="{{ $entete }}">Enseignant</th><th scope="col" style="{{ $entete }}">Cours</th>
                            <th scope="col" style="{{ $entete }}">Alloué</th><th scope="col" style="{{ $entete }}">Programmé</th>
                            <th scope="col" style="{{ $entete }}">Tenu</th><th scope="col" style="padding:.35rem 0;">Plafond</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($suivi['charges'] as $c)
                                @php $depasse = $c['plafond'] !== null && $c['allouees'] > $c['plafond']; @endphp
                                <tr>
                                    <td style="{{ $cellule }}">{{ $c['enseignant']->name }}</td>
                                    <td style="{{ $cellule }}">{{ $c['cours'] }}</td>
                                    <td style="{{ $cellule }}">@if ($depasse)<span class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--danger-400);--c-600:var(--danger-600);font-weight:600;">{{ $c['allouees'] }} h (au-delà du plafond)</span>@else{{ $c['allouees'] }} h @endif</td>
                                    <td style="{{ $cellule }}">{{ $c['programme'] }} h</td>
                                    <td style="{{ $cellule }}">{{ $c['tenu'] }} h</td>
                                    <td style="{{ $cellule }}padding-right:0;">{{ $c['plafond'] !== null ? $c['plafond'].' h' : 'aucun' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Quotas de la période ({{ count($suivi['quotas']) }})</x-slot>
            @if ($suivi['quotas'] === [])
                <p style="font-size:.85rem;margin:0;">Aucun quota : fixez-les dans « Quotas horaires ».</p>
            @else
                <div style="overflow-x:auto;font-size:.85rem;">
                    <table style="width:100%;border-collapse:collapse;">
                        <caption style="{{ $masque }}">Avancement des quotas de la période</caption>
                        <thead><tr style="{{ $tete }}">
                            <th scope="col" style="{{ $entete }}">Cours</th><th scope="col" style="{{ $entete }}">Quota</th>
                            <th scope="col" style="{{ $entete }}">Réparti</th><th scope="col" style="{{ $entete }}">Programmé</th>
                            <th scope="col" style="{{ $entete }}">Tenu</th><th scope="col" style="padding:.35rem 0;">Avancement</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($suivi['quotas'] as $l)
                                @php $q = $l['quota']; @endphp
                                <tr>
                                    <td style="{{ $cellule }}">
                                        <a href="{{ \App\Filament\Resources\QuotaHoraireResource::getUrl('view', ['record' => $q]) }}" class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--primary-400);--c-600:var(--primary-600);font-weight:600;text-decoration:underline;">{{ $q->ecue->code }}</a>
                                        {{ $q->ecue->intitule }} <span style="color:rgb(var(--gray-500));">· {{ $q->ecue->maquetteUe->maquette->parcours->code }}</span>
                                    </td>
                                    <td style="{{ $cellule }}">{{ $q->total() }} h</td>
                                    <td style="{{ $cellule }}">@if (abs($l['reparti'] - $q->total()) > 0.01)<span class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--danger-400);--c-600:var(--danger-600);font-weight:600;">{{ $l['reparti'] }} h (écart)</span>@else{{ $l['reparti'] }} h @endif</td>
                                    <td style="{{ $cellule }}">{{ $l['programme'] }} h</td>
                                    <td style="{{ $cellule }}">{{ $l['tenu'] }} h</td>
                                    <td style="{{ $cellule }}padding-right:0;min-width:8rem;">
                                        <span style="display:inline-block;width:5rem;height:.5rem;background:rgb(var(--gray-200));border-radius:2px;vertical-align:middle;overflow:hidden;" aria-hidden="true">
                                            <span style="display:block;height:100%;width:{{ min(100, $l['avancement']) }}%;background:#4A2E67;"></span>
                                        </span>
                                        {{ $l['avancement'] }} %
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
