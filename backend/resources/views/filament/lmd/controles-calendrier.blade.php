@php
    $c = $getRecord();
    $k = app(\App\Services\Lmd\ControlesCalendrier::class);
    $anomalies = $k->anomaliesBloquantes($c);
    $controles = array_values(array_filter($k->controles($c), fn ($x) => ! $x['bloquant']));
    $dependances = $k->dependances($c);
    $journal = \App\Models\ModificationEvenement::query()->whereIn('evenement_id', $c->evenements()->select('id'))
        ->with(['auteur'])->orderByDesc('id')->limit(30)->get();
    $libelles = $c->evenements()->pluck('libelle', 'id');
    $cellule = 'padding:.3rem .6rem .3rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
    $titre = 'font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--gray-600));margin:1rem 0 .4rem;';
    $etats = ['ok' => 'respecté', 'trop_tot' => 'trop tôt', 'trop_tard' => 'trop tard'];
@endphp
<div style="font-size:.85rem;">
    <p style="{{ $titre }}margin-top:0;">Anomalies bloquantes ({{ count($anomalies) }})</p>
    @forelse ($anomalies as $a)
        <p style="margin:.15rem 0;color:#B42318;"><strong>{{ $a['libelle'] }}</strong> — {{ $a['detail'] }}</p>
    @empty
        <p style="margin:.15rem 0;color:#1F6B3A;">Aucune : le calendrier peut être soumis et signé.</p>
    @endforelse

    @if ($controles !== [])
        <p style="{{ $titre }}">Alertes ({{ count($controles) }})</p>
        @foreach ($controles as $x)
            <p style="margin:.15rem 0;"><strong>{{ $x['evenement']->libelle }}</strong> — {{ $x['detail'] }}</p>
        @endforeach
    @endif

    <p style="{{ $titre }}">Dépendances ({{ count($dependances) }})</p>
    @if ($dependances === [])
        <p style="margin:.15rem 0;">Aucun lien : générez les dépendances des règles ou liez des événements.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Dépendances entre événements</caption>
                <thead>
                    <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Prédécesseur → successeur</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Règle</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Écart</th>
                        <th scope="col" style="padding:.3rem .6rem .3rem 0;">Marge</th>
                        <th scope="col" style="padding:.3rem 0;">État</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dependances as $d)
                        <tr>
                            <td style="{{ $cellule }}">{{ $d['lien']->predecesseur->libelle }} → {{ $d['lien']->successeur->libelle }}</td>
                            <td style="{{ $cellule }}">{{ $d['lien']->regle }}{{ $d['lien']->bloquant ? '' : ' (alerte)' }}</td>
                            <td style="{{ $cellule }}">{{ $d['ecart'] }} j (min {{ $d['lien']->decalage_min_jours }}{{ $d['lien']->decalage_max_jours !== null ? ', max '.$d['lien']->decalage_max_jours : '' }})</td>
                            <td style="{{ $cellule }}{{ $d['marge'] === 0 ? 'font-weight:600;' : '' }}">{{ $d['marge'] }} j{{ $d['marge'] === 0 ? ' (critique)' : '' }}</td>
                            <td style="{{ $cellule }}padding-right:0;font-weight:600;color:{{ $d['etat'] === 'ok' ? '#1F6B3A' : '#B42318' }};">{{ $etats[$d['etat']] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p style="{{ $titre }}">Journal des réajustements</p>
    @forelse ($journal as $j)
        <p style="margin:.15rem 0;">
            {{ $j->created_at?->timezone('Africa/Douala')->format('d/m/Y H:i') }} — <strong>{{ $libelles[$j->evenement_id] ?? '' }}</strong> :
            @if ($j->ancien_debut && ! $j->ancien_debut->equalTo($j->nouveau_debut) || $j->ancienne_fin && ! $j->ancienne_fin->equalTo($j->nouvelle_fin))
                {{ $j->ancien_debut?->format('d/m/Y') }}–{{ $j->ancienne_fin?->format('d/m/Y') }} → {{ $j->nouveau_debut?->format('d/m/Y') }}–{{ $j->nouvelle_fin?->format('d/m/Y') }} ;
            @endif
            @if ($j->ancien_statut !== $j->nouveau_statut)
                {{ \App\Models\EvenementCalendrier::STATUTS[$j->ancien_statut] ?? 'création' }} → {{ \App\Models\EvenementCalendrier::STATUTS[$j->nouveau_statut] ?? '' }} ;
            @endif
            {{ $j->motif }} ({{ $j->auteur?->name ?? 'reprise' }})
        </p>
    @empty
        <p style="margin:.15rem 0;">Aucun réajustement.</p>
    @endforelse
</div>
