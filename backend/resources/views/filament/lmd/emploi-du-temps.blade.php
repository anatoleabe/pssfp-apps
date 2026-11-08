@php
    /** Conflits, alertes et volume horaire d'un emploi du temps (lot 12c, D21). */
    $e = $getRecord();
    $conflits = app(\App\Services\Lmd\ConflitsEmploiDuTemps::class)->pour($e);
    $bloquants = array_values(array_filter($conflits, fn ($k) => $k['bloquant']));
    $alertes = array_values(array_filter($conflits, fn ($k) => ! $k['bloquant']));
    $volumes = app(\App\Services\Lmd\EmploisDuTemps::class)->volumes($e);
    $libelles = \App\Services\Lmd\ConflitsEmploiDuTemps::LIBELLES;
    $cellule = 'padding:.3rem .6rem .3rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $titre = 'font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--gray-600));margin:1rem 0 .4rem;';
@endphp
<div style="font-size:.85rem;">
    <p style="{{ $titre }}margin-top:0;">Conflits bloquants ({{ count($bloquants) }})</p>
    @forelse ($bloquants as $k)
        <p style="margin:.15rem 0;color:#B42318;"><strong>{{ $k['seance']->libelle() }}</strong> — {{ $libelles[$k['controle']] ?? $k['controle'] }} : {{ $k['detail'] }}</p>
    @empty
        <p style="margin:.15rem 0;color:#1F6B3A;">Aucun : l'emploi du temps peut être publié.</p>
    @endforelse

    @if ($alertes !== [])
        <p style="{{ $titre }}">Alertes ({{ count($alertes) }})</p>
        @foreach ($alertes as $k)
            <p style="margin:.15rem 0;"><strong>{{ $k['seance']->libelle() }}</strong> — {{ $libelles[$k['controle']] ?? $k['controle'] }} : {{ $k['detail'] }}</p>
        @endforeach
    @endif

    <p style="{{ $titre }}">Volume horaire par ECUE</p>
    @if ($volumes === [])
        <p style="margin:.15rem 0;">Aucun ECUE : la maquette du parcours n'est pas validée pour cette année.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Volume horaire par ECUE</caption>
                <thead>
                    <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">ECUE</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Maquette</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Programmé</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Tenu</th>
                        <th scope="col" style="padding:.3rem 0;">Reste à programmer</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($volumes as $v)
                        <tr>
                            <td style="{{ $cellule }}"><strong>{{ $v['ecue']->code }}</strong> {{ $v['ecue']->intitule }}</td>
                            <td style="{{ $cellule }}">{{ $v['maquette'] > 0 ? $v['maquette'].' h' : 'non renseigné' }}</td>
                            <td style="{{ $cellule }}{{ $v['maquette'] > 0 && $v['programme'] > $v['maquette'] ? 'color:#B42318;font-weight:600;' : '' }}">{{ $v['programme'] }} h</td>
                            <td style="{{ $cellule }}">{{ $v['tenu'] }} h</td>
                            <td style="{{ $cellule }}padding-right:0;">{{ $v['maquette'] > 0 ? $v['reste'].' h' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
