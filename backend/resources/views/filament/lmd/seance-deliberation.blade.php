@php
    $d = $getRecord();
    $participations = $d->participations()->with('membre')->get();
    $manquantes = $d->statut === \App\Models\Deliberation::ARRETEE ? app(\App\Services\Lmd\Deliberations::class)->signaturesManquantes($d) : [];
@endphp
<ul style="list-style:none;margin:0;padding:0;font-size:.875rem;">
    @foreach ($participations as $p)
        <li style="padding:.25rem 0;">
            <strong>{{ $p->membre->libelle() }}</strong> —
            {{ $p->present ? 'présent' : 'absent ('.$p->motif_absence.')' }}
            @if ($p->signe_at) · a signé le {{ $p->signe_at->timezone('Africa/Douala')->format('d/m/Y à H:i') }} @endif
        </li>
    @endforeach
</ul>
@if ($manquantes !== [])
    <p style="font-size:.875rem;margin-top:.5rem;">Signatures attendues : {{ implode(', ', $manquantes) }}.</p>
@endif
