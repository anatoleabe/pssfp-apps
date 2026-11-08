@php
    /** Consommation d'un quota (lot 12d, D22) : réparti, programmé, tenu, par enseignant. */
    $q = $getRecord();
    $actives = $q->programmations()->whereIn('statut', \App\Models\ProgrammationEnseignant::ACTIVES)->with('enseignant')->get();
    $conso = app(\App\Services\Lmd\Programmations::class)->consommation($actives);
    $reparti = round($actives->sum(fn ($p) => $p->total()), 1);
    $programme = round($actives->sum(fn ($p) => $conso[$p->id]['programme']), 1);
    $tenu = round($actives->sum(fn ($p) => $conso[$p->id]['tenu']), 1);
    $avancement = $q->total() > 0 ? (int) round(100 * $tenu / $q->total()) : 0;
    $cellule = 'padding:.3rem .6rem .3rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
@endphp
<div style="font-size:.85rem;">
    <p style="margin:0 0 .6rem;">
        Quota <strong>{{ $q->total() }} h</strong> · réparti <strong>{{ $reparti }} h</strong>@if (abs($reparti - $q->total()) > 0.01) <span class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--danger-400);--c-600:var(--danger-600);font-weight:600;">(écart avec le quota)</span>@endif
        · programmé <strong>{{ $programme }} h</strong> · tenu <strong>{{ $tenu }} h</strong> · avancement <strong>{{ $avancement }} %</strong>
    </p>
    @if ($actives->isNotEmpty())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Consommation par enseignant</caption>
                <thead>
                    <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Enseignant</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Alloué</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Programmé</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Tenu</th>
                        <th scope="col" style="padding:.3rem 0;">Reste</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($actives as $p)
                        <tr>
                            <td style="{{ $cellule }}">{{ $p->enseignant->name }}</td>
                            <td style="{{ $cellule }}">{{ $p->total() }} h</td>
                            <td style="{{ $cellule }}">@if ($conso[$p->id]['programme'] > $p->total())<span class="fi-color-custom text-custom-600 dark:text-custom-400" style="--c-400:var(--danger-400);--c-600:var(--danger-600);font-weight:600;">{{ $conso[$p->id]['programme'] }} h (au-delà)</span>@else{{ $conso[$p->id]['programme'] }} h @endif</td>
                            <td style="{{ $cellule }}">{{ $conso[$p->id]['tenu'] }} h</td>
                            <td style="{{ $cellule }}padding-right:0;">{{ round($p->total() - $conso[$p->id]['tenu'], 1) }} h</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
