@php
    $d = $getRecord();
    $service = app(\App\Services\Lmd\Memoires::class);
    $avancement = $service->avancement($d);
    $conditions = $service->conditions($d);
    $attestation = $service->attestationValide($d);
    $etapes = $d->etapes()->get();
    $suivis = $d->suivis()->limit(10)->get();
    $avis = $d->avis()->limit(5)->get();
    $rapports = $d->rapports()->limit(5)->get();
    $cellule = 'padding:.35rem .6rem .35rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $entete = 'padding:.35rem .6rem .35rem 0;';
    $titre = 'font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--gray-600));margin:1.25rem 0 .4rem;';
@endphp
<div style="font-size:.875rem;">
    <p><strong>Avancement : {{ $avancement['pourcentage'] }} %</strong>@if ($avancement['estime'] !== null) — estimation de l'encadreur : {{ $avancement['estime'] }} %@endif
        @if ($avancement['prochaine']) · prochaine étape : {{ $avancement['prochaine'] }}@endif</p>
    @if ($avancement['retards'] !== [])
        <p style="color:rgb(var(--danger-600));">Retard : {{ implode(', ', $avancement['retards']) }}.</p>
    @endif

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.5rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Étapes du mémoire</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="{{ $entete }}">Étape</th>
                    <th scope="col" style="{{ $entete }}">Poids</th>
                    <th scope="col" style="{{ $entete }}">Prévue</th>
                    <th scope="col" style="{{ $entete }}">État</th>
                    <th scope="col" style="{{ $entete }}">Note</th>
                    <th scope="col" style="padding:.35rem 0;">Appréciation, document, retard</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($etapes as $e)
                    <tr>
                        <td style="{{ $cellule }}">{{ $e->libelle() }}</td>
                        <td style="{{ $cellule }}">{{ $e->poids }}</td>
                        <td style="{{ $cellule }}">{{ $e->date_prevue?->format('d/m/Y') ?? '—' }}</td>
                        <td style="{{ $cellule }}font-weight:600;{{ $e->enRetard() || $e->enAttenteDepassee() ? 'color:rgb(var(--danger-600));' : '' }}">
                            {{ \App\Models\EtapeMemoire::STATUTS[$e->statut] ?? $e->statut }}@if ($e->realisee_at) le {{ $e->realisee_at->timezone('Africa/Douala')->format('d/m/Y') }}@endif
                        </td>
                        <td style="{{ $cellule }}">{{ $e->note !== null ? number_format((float) $e->note, 2, ',', ' ') : '' }}</td>
                        <td style="{{ $cellule }}padding-right:0;">
                            {{ $e->appreciation }}
                            @if ($e->document_sha256)<br><span style="font-family:ui-monospace,monospace;font-size:.75rem;">PDF déposé · SHA-256 {{ substr($e->document_sha256, 0, 16) }}…</span>@endif
                            @if ($e->retard_autorise_at)<br>Retard autorisé le {{ $e->retard_autorise_at->timezone('Africa/Douala')->format('d/m/Y') }} : {{ $e->retard_motif }}@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p style="{{ $titre }}">Attestation d'autorisation de dépôt</p>
    @if ($attestation)
        <p><strong>{{ $attestation->numero }}</strong> délivrée le {{ $attestation->created_at->timezone('Africa/Douala')->format('d/m/Y') }}.</p>
    @else
        <ul style="list-style:none;padding:0;margin:0;">
            @foreach ($conditions as $c)
                <li style="padding:.15rem 0;">
                    <span aria-hidden="true" style="font-weight:700;color:rgb(var({{ $c['ok'] ? '--success-600' : '--danger-600' }}));">{{ $c['ok'] ? '✓' : '✗' }}</span>
                    <span class="sr-only">{{ $c['ok'] ? 'Remplie :' : 'Manquante :' }}</span>
                    {{ $c['libelle'] }}@if ($c['detail'] && ! in_array($c['cle'], ['theme', 'encadreur'], true)) — {{ $c['detail'] }}@endif
                </li>
            @endforeach
        </ul>
    @endif

    <p style="{{ $titre }}">Avis des encadreurs et similitude</p>
    @forelse ($avis as $a)
        <p style="margin:.1rem 0;">{{ $a->created_at?->timezone('Africa/Douala')->format('d/m/Y') }} — encadreur {{ $a->encadreur === 'academique' ? 'académique' : 'professionnel' }} : <strong>{{ $a->favorable ? 'favorable' : 'défavorable' }}</strong>@if ($a->commentaire) ({{ $a->commentaire }})@endif</p>
    @empty
        <p style="margin:.1rem 0;">Aucun avis.</p>
    @endforelse
    @foreach ($rapports as $r)
        <p style="margin:.1rem 0;">{{ $r->date_rapport->format('d/m/Y') }} — {{ $r->outil }} : <strong>{{ number_format((float) $r->taux, 1, ',', ' ') }} %</strong></p>
    @endforeach

    <p style="{{ $titre }}">Fiches de suivi d'encadrement</p>
    @forelse ($suivis as $s)
        <p style="margin:.1rem 0;">{{ $s->date_suivi->format('d/m/Y') }} ({{ $s->mode }})@if ($s->avancement_estime !== null) — {{ $s->avancement_estime }} %@endif@if ($s->chapitres) — {{ $s->chapitres }}@endif@if ($s->observations) — {{ $s->observations }}@endif</p>
    @empty
        <p style="margin:.1rem 0;">Aucune fiche de suivi.</p>
    @endforelse
</div>
