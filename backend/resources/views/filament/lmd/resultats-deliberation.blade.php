@php
    $d = $getRecord();
    $arretee = ! $d->estModifiable();
    $scenario = $arretee ? null : ($d->scenarioRetenu ?? $d->scenarios()->whereNotNull('calcule_at')->latest('calcule_at')->first());
    $resultats = $arretee
        ? $d->resultats()->with('inscriptionAnnuelle.auditeur.candidature')->get()
        : ($scenario?->resultats()->with('inscriptionAnnuelle.auditeur.candidature')->get() ?? collect());
    $resultats = $resultats->sortBy(fn ($r) => mb_strtoupper((string) $r->inscriptionAnnuelle?->auditeur?->candidature?->nom))->values();
    $regle = \App\Models\RegleEvaluation::pourAnnee($d->annee_academique);
    $cellule = 'padding:.35rem .6rem .35rem 0;border-bottom:1px solid rgb(var(--gray-200));';
    $num = 'text-align:right;white-space:nowrap;';
    $fmt = fn ($v, int $dec = 2) => $v === null ? '—' : number_format((float) $v, $dec, ',', ' ');
@endphp
@if ($resultats->isEmpty())
    <p style="font-size:.875rem;">Aucun résultat : calculez un scénario.</p>
@else
    @if ($scenario)
        <p style="font-size:.8rem;color:rgb(var(--gray-500));margin-bottom:.5rem;">Scénario affiché : {{ $scenario->libelle }}. Les lignes surlignées sont des cas limites (ajournés à 8 crédits ou moins de la décision supérieure).</p>
    @endif
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Résultats</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Matricule</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Nom</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;{{ $num }}">Crédits</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;{{ $num }}">Moyenne</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;{{ $num }}">MGP</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Décision</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;{{ $num }}">Rang</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Mention</th>
                    <th scope="col" style="padding:.35rem 0;">Mesures</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resultats as $r)
                    @php
                        $dossier = $r->inscriptionAnnuelle?->auditeur?->candidature;
                        $limite = $r->decision === 'AJ' && (
                            ($r->inscriptionAnnuelle?->niveau === 'M2' && $r->credits_acquis >= $regle->credits_ecrits_terminal - 8)
                            || $r->credits_acquis >= $regle->credits_niveau - 8);
                    @endphp
                    <tr style="{{ $limite ? 'background:rgb(254 249 195);' : '' }}">
                        <td style="{{ $cellule }}font-family:ui-monospace,monospace;">{{ $dossier?->matricule }}</td>
                        <td style="{{ $cellule }}">{{ trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom) }}</td>
                        <td style="{{ $cellule }}{{ $num }}">{{ $r->credits_acquis }}</td>
                        <td style="{{ $cellule }}{{ $num }}">{{ $fmt($r->moyenne) }}</td>
                        <td style="{{ $cellule }}{{ $num }}">{{ $fmt($r->mgp) }}</td>
                        <td style="{{ $cellule }}font-weight:600;">{{ $r->decision }}@if ($r->decision !== $r->decision_calculee)<span style="font-weight:400;"> (calculée {{ $r->decision_calculee }})</span>@endif</td>
                        <td style="{{ $cellule }}{{ $num }}">{{ $r->rang ?? '—' }}</td>
                        <td style="{{ $cellule }}">{{ $r->mention }}{{ $r->felicitations_proposees ? ' · félicitations proposées' : '' }}</td>
                        <td style="{{ $cellule }}padding-right:0;">{{ $r->mesures }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
