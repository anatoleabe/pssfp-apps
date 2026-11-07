@php
    /** @var \App\Models\Maquette $maquette */
    $maquette = $getRecord()->loadMissing(['ues.ue', 'ues.ecues']);
    $cellule = 'padding:.4rem .75rem .4rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $num = 'text-align:right;white-space:nowrap;';
@endphp
@if ($maquette->ues->isEmpty())
    <p style="font-size:.875rem;">Aucune UE pour l'instant.</p>
@else
    @foreach ($maquette->ues->groupBy('semestre') as $semestre => $lignes)
        <h3 style="font-size:.95rem;font-weight:600;margin:1rem 0 .4rem;">Semestre {{ $semestre }} · {{ $lignes->sum('credits') }} crédits</h3>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Semestre {{ $semestre }}</caption>
                <thead>
                    <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;">Code</th>
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;">Intitulé</th>
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">CM</th>
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">TD</th>
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">TP</th>
                        <th scope="col" style="padding:.35rem .75rem .35rem 0;{{ $num }}">TPE</th>
                        <th scope="col" style="padding:.35rem 0;{{ $num }}">Crédits</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $ligne)
                        <tr style="background:rgb(var(--gray-50));font-weight:600;">
                            <th scope="row" style="{{ $cellule }}font-family:ui-monospace,monospace;text-align:left;">{{ $ligne->ue?->code }}</th>
                            <td style="{{ $cellule }}">{{ $ligne->ue?->intitule }}
                                @if ($ligne->partie === \App\Models\MaquetteUe::SOUTENANCE)
                                    <span style="font-weight:400;font-size:.75rem;"> — soutenance</span>
                                @endif
                                <span style="display:block;font-weight:400;font-size:.75rem;color:rgb(var(--gray-500));">CC {{ round((float) $ligne->pcc * 100) }} % · assiduité {{ round((float) $ligne->ptp * 100) }} % · examen {{ round((float) $ligne->pexam * 100) }} %</span>
                            </td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $ligne->ecues->sum('heures_cm') }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $ligne->ecues->sum('heures_td') }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $ligne->ecues->sum('heures_tp') }}</td>
                            <td style="{{ $cellule }}{{ $num }}">{{ $ligne->ecues->sum('heures_tpe') }}</td>
                            <td style="{{ $cellule }}{{ $num }}padding-right:0;">{{ $ligne->credits }}</td>
                        </tr>
                        @foreach ($ligne->ecues as $ecue)
                            <tr>
                                <td style="{{ $cellule }}font-family:ui-monospace,monospace;padding-left:1rem;">{{ $ecue->code }}</td>
                                <td style="{{ $cellule }}">{{ $ecue->intitule }}</td>
                                <td style="{{ $cellule }}{{ $num }}">{{ $ecue->heures_cm }}</td>
                                <td style="{{ $cellule }}{{ $num }}">{{ $ecue->heures_td }}</td>
                                <td style="{{ $cellule }}{{ $num }}">{{ $ecue->heures_tp }}</td>
                                <td style="{{ $cellule }}{{ $num }}">{{ $ecue->heures_tpe }}</td>
                                <td style="{{ $cellule }}{{ $num }}padding-right:0;">{{ $ecue->credits }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
    <p style="font-size:.875rem;font-weight:600;margin-top:.75rem;">Total : {{ $maquette->totalCredits() }} crédits</p>
@endif
