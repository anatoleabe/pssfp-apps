@php
    /** Contrôles et répartition en salles d'un planning d'examens (lot 27). */
    $p = $getRecord();
    $conflits = app(\App\Services\Lmd\ConflitsPlanningExamens::class)->pour($p);
    $bloquants = array_values(array_filter($conflits, fn ($k) => $k['bloquant']));
    $alertes = array_values(array_filter($conflits, fn ($k) => ! $k['bloquant']));
    $lignes = app(\App\Services\Lmd\DocumentsExamen::class)->lignes($p);
    $libelles = \App\Services\Lmd\ConflitsPlanningExamens::LIBELLES;
    $cellule = 'padding:.3rem .6rem .3rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $titre = 'font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--gray-600));margin:1rem 0 .4rem;';
@endphp
<div style="font-size:.85rem;">
    <p style="{{ $titre }}margin-top:0;">Conflits bloquants ({{ count($bloquants) }})</p>
    @forelse ($bloquants as $k)
        <p style="margin:.15rem 0;color:#B42318;"><strong>{{ $k['epreuve']->libelle() }}</strong> — {{ $libelles[$k['controle']] ?? $k['controle'] }} : {{ $k['detail'] }}</p>
    @empty
        <p style="margin:.15rem 0;color:#1F6B3A;">{{ $lignes === [] ? 'Aucune épreuve pour l\'instant.' : 'Aucun : le planning peut être publié.' }}</p>
    @endforelse

    @if ($alertes !== [])
        <p style="{{ $titre }}">Alertes ({{ count($alertes) }})</p>
        @foreach ($alertes as $k)
            <p style="margin:.15rem 0;"><strong>{{ $k['epreuve']->libelle() }}</strong> — {{ $libelles[$k['controle']] ?? $k['controle'] }} : {{ $k['detail'] }}</p>
        @endforeach
    @endif

    @if ($lignes !== [])
        <p style="{{ $titre }}">Répartition en salles</p>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Répartition des candidats en salles</caption>
                <thead>
                    <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Épreuve</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Candidats</th>
                        <th scope="col" style="padding:.3rem 0;">Salles : du premier au dernier nom</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lignes as $l)
                        <tr>
                            <td style="{{ $cellule }}"><strong>{{ $l['epreuve']->code_ecue }}</strong> {{ $l['epreuve']->intitule }}</td>
                            <td style="{{ $cellule }}">{{ $l['effectif'] }}</td>
                            <td style="{{ $cellule }}padding-right:0;">
                                @forelse ($l['salles'] as $s)
                                    <div>{{ $s['salle']->code }} : {{ $s['candidats']->count() }}@if ($s['candidats']->isNotEmpty())
                                        — de {{ \App\Services\Lmd\CandidatsExamen::nom($s['candidats']->first()) }} à {{ \App\Services\Lmd\CandidatsExamen::nom($s['candidats']->last()) }}@endif</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
