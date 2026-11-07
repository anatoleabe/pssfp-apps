@php $anomalies = app(\App\Services\Lmd\Deliberations::class)->prealables($getRecord()); @endphp
@if ($anomalies === [])
    <p style="font-size:.875rem;">Tous les préalables sont remplis : la délibération peut être arrêtée après la séance.</p>
@else
    <ul style="list-style:none;margin:0;padding:0;font-size:.875rem;">
        @foreach ($anomalies as $a)
            <li style="display:flex;gap:.6rem;align-items:baseline;padding:.3rem 0;">
                <span style="flex:none;font-size:.75rem;font-weight:600;padding:.05rem .45rem;border-radius:.375rem;{{ $a->estBloquante() ? 'background:rgb(254 226 226);color:rgb(153 27 27);' : 'background:rgb(254 243 199);color:rgb(146 64 14);' }}">{{ $a->estBloquante() ? 'Bloquant' : 'Avertissement' }}</span>
                <span>{{ $a->message }}</span>
            </li>
        @endforeach
    </ul>
@endif
