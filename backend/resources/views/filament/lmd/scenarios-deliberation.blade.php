@php
    $scenarios = $getRecord()->scenarios()->withCount('mesures')->get();
    $cellule = 'padding:.4rem .75rem .4rem 0;border-bottom:1px solid rgb(var(--gray-200));';
    $num = 'text-align:right;white-space:nowrap;';
@endphp
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Comparaison des scénarios</caption>
        <thead>
            <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                <th scope="col" style="padding:.35rem .75rem .35rem 0;">Scénario</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Mesures</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Effectif</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">AD</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">AS</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">AJ</th>
                <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">Réussite</th>
                <th scope="col" style="padding:.35rem 0;">État</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($scenarios as $s)
                <tr>
                    <th scope="row" style="{{ $cellule }}text-align:left;font-weight:{{ $s->retenu ? '700' : '500' }};">{{ $s->libelle }}</th>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->mesures_count }}</td>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->effectif ?? '—' }}</td>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->admis ?? '—' }}</td>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->autorises ?? '—' }}</td>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->ajournes ?? '—' }}</td>
                    <td style="{{ $cellule }}{{ $num }}">{{ $s->taux_reussite !== null ? number_format((float) $s->taux_reussite, 1, ',', ' ').' %' : '—' }}</td>
                    <td style="{{ $cellule }}padding-right:0;">
                        {{ $s->retenu ? 'Retenu par le jury' : ($s->calcule_at ? 'Calculé le '.$s->calcule_at->timezone('Africa/Douala')->format('d/m à H:i') : 'À calculer') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
