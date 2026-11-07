@php
    $e = $getRecord();
    $service = app(\App\Services\Lmd\Anonymat::class);
    $copies = $e->copies()->with('saisies')->get()->sortBy('numero')->values();
    $blocages = $e->statut === 'anonymisee' ? $service->blocages($e) : [];
    $cellule = 'padding:.35rem .6rem .35rem 0;border-bottom:1px solid rgb(var(--gray-200));';
    $fmt = fn (?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ');
@endphp
@if ($blocages !== [])
    <p style="font-size:.875rem;margin-bottom:.75rem;"><strong>Avant le verrouillage :</strong> {{ implode(' ; ', $blocages) }}.</p>
@endif
@if ($copies->isEmpty())
    <p style="font-size:.875rem;">Aucun numéro attribué.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Copies de l'épreuve</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Numéro</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Constat</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;text-align:right;">1re corr.</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;text-align:right;">2e corr.</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;text-align:right;">3e corr.</th>
                    <th scope="col" style="padding:.35rem 0;text-align:right;">Note retenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($copies as $c)
                    <tr>
                        <td style="{{ $cellule }}font-family:ui-monospace,monospace;">{{ $c->numero }}</td>
                        <td style="{{ $cellule }}">{{ \App\Models\CopieAnonyme::CONSTATS[$c->constat] ?? $c->constat }}</td>
                        @foreach ([1, 2, 3] as $n)
                            <td style="{{ $cellule }}text-align:right;">{{ in_array($n, $service->correctionsAttendues($c), true) ? $fmt($service->noteCorrection($c, $n)) : '' }}</td>
                        @endforeach
                        <td style="{{ $cellule }}text-align:right;padding-right:0;font-weight:600;">{{ $fmt($service->noteFinale($c)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
