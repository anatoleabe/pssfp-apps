@php
    /** Diagramme de Gantt du calendrier (lot 12a, D21) : prévu, référence signée, réalisé, jalons, retards. */
    $c = $getRecord();
    $lignes = app(\App\Services\Lmd\ControlesCalendrier::class)->gantt($c);
    $debut = $c->date_debut->startOfMonth();
    $fin = $c->date_fin->endOfMonth()->startOfDay();
    $total = max(1, $debut->diffInDays($fin) + 1);
    $pct = fn ($d) => round(max(0, min($total, $debut->diffInDays($d, false))) * 100 / $total, 3);
    $largeur = fn ($a, $b) => max(0.35, round(($debut->diffInDays($b, false) + 1 - max(0, $debut->diffInDays($a, false))) * 100 / $total, 3));
    $aujourdhui = now('Africa/Douala')->startOfDay();
    $mois = [];
    for ($m = $debut; $m->lte($fin); $m = $m->addMonth()) {
        $mois[] = $m;
    }
    $libelleMois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $label = 'width:19rem;min-width:19rem;padding:0 .6rem 0 0;font-size:.78rem;line-height:1.15;overflow:hidden;';
@endphp
@if ($lignes === [])
    <p style="font-size:.875rem;">Aucun événement : ajoutez des phases et des événements ci-dessous.</p>
@else
    <figure style="margin:0;" aria-label="Diagramme de Gantt du calendrier ; le détail de chaque événement figure dans le tableau des événements.">
        <div style="display:flex;flex-wrap:wrap;gap:1rem;font-size:.75rem;color:rgb(var(--gray-600));margin-bottom:.6rem;">
            <span><span style="display:inline-block;width:1.4rem;height:.6rem;background:#A592BD;border-radius:2px;vertical-align:middle;"></span> prévu (avancement en prune)</span>
            <span><span style="display:inline-block;width:1.4rem;height:3px;background:rgb(var(--gray-400));vertical-align:middle;"></span> référence signée</span>
            <span><span style="display:inline-block;width:1.4rem;height:3px;background:#1F6B3A;vertical-align:middle;"></span> réalisé</span>
            <span><span style="display:inline-block;width:.6rem;height:.6rem;background:#D4AF6A;transform:rotate(45deg);vertical-align:middle;"></span> jalon</span>
            <span><span style="display:inline-block;width:1.4rem;height:.6rem;border:2px solid #B42318;vertical-align:middle;"></span> en retard</span>
            <span><span style="display:inline-block;width:2px;height:.8rem;background:#B42318;vertical-align:middle;"></span> aujourd'hui</span>
            <span><span style="display:inline-block;width:.45rem;height:.45rem;border-radius:50%;background:#0F3A4A;vertical-align:middle;"></span> échéance d'un acte (rouge : en retard, vert : signé)</span>
            <span><span style="display:inline-block;width:1.4rem;height:.45rem;background:#C9DCE3;vertical-align:middle;"></span> programmation d'un enseignant (heures tenues en bleu pétrole)</span>
        </div>
        <div style="overflow-x:auto;">
            <div style="min-width:68rem;">
                <div style="display:flex;border-bottom:1px solid rgb(var(--gray-200));padding-bottom:.25rem;">
                    <div style="{{ $label }}font-weight:600;color:rgb(var(--gray-500));text-transform:uppercase;font-size:.7rem;">Événement</div>
                    <div style="position:relative;flex:1;height:1.2rem;">
                        @foreach ($mois as $m)
                            <span style="position:absolute;left:{{ $pct($m) }}%;font-size:.7rem;color:rgb(var(--gray-500));border-left:1px solid rgb(var(--gray-200));padding-left:.2rem;">{{ $libelleMois[$m->month - 1] }} {{ $m->format('y') }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($lignes as $l)
                    @php
                        $titre = $l['titre'] ?? $l['libelle'].' : '.$l['debut_prevu']->format('d/m/Y').($l['jalon'] ? '' : ' au '.$l['fin_prevue']->format('d/m/Y'))
                            .($l['debut_signe'] ? ' ; référence '.$l['debut_signe']->format('d/m/Y').' au '.$l['fin_signe']->format('d/m/Y') : '')
                            .($l['glissement'] ? ' ; glissement '.($l['glissement'] > 0 ? '+' : '').$l['glissement'].' j' : '')
                            .' ; '.($l['phase'] ? 'avancement '.$l['avancement'].' %' : (\App\Models\EvenementCalendrier::STATUTS[$l['statut']] ?? $l['statut']))
                            .implode('', array_map(fn ($a) => ' ; '.$a['libelle'].' : '.$a['echeance']->format('d/m/Y').' ('.(\App\Models\Acte::STATUTS[$a['statut']] ?? $a['statut']).')', $l['actes']));
                        $fond = $l['phase'] ? 'rgb(var(--gray-50))' : 'transparent';
                    @endphp
                    <div style="display:flex;align-items:center;min-height:1.75rem;border-bottom:1px solid rgb(var(--gray-100));background:{{ $fond }};" title="{{ $titre }}">
                        <div style="{{ $label }}padding-left:{{ ['0', '1rem', '2rem'][$l['niveau_hierarchie']] }};{{ ($l['sous_barre'] ?? false) ? 'font-size:.72rem;color:rgb(var(--gray-600));' : '' }}{{ $l['phase'] ? 'font-weight:700;color:#3A2452;' : '' }}{{ $l['statut'] === 'annule' ? 'text-decoration:line-through;color:rgb(var(--gray-400));' : '' }}">
                            {{ \Illuminate\Support\Str::limit($l['libelle'], 64) }}
                            @if ($l['glissement'])<span style="color:{{ $l['glissement'] > 0 ? '#B42318' : '#1F6B3A' }};font-weight:600;"> {{ $l['glissement'] > 0 ? '+' : '' }}{{ $l['glissement'] }} j</span>@endif
                            @if ($l['modifications'] > 0)<span style="color:rgb(var(--gray-500));"> ({{ $l['modifications'] }} modif.)</span>@endif
                        </div>
                        <div style="position:relative;flex:1;height:1.75rem;">
                            @if ($aujourdhui->between($debut, $fin))
                                <span style="position:absolute;left:{{ $pct($aujourdhui) }}%;top:0;bottom:0;width:2px;background:#B42318;opacity:.55;"></span>
                            @endif
                            @if ($l['debut_signe'] && ! $l['jalon'])
                                <span style="position:absolute;left:{{ $pct($l['debut_signe']) }}%;width:{{ $largeur($l['debut_signe'], $l['fin_signe']) }}%;top:78%;height:3px;background:rgb(var(--gray-400));"></span>
                            @endif
                            @if ($l['jalon'])
                                <span style="position:absolute;left:calc({{ $pct($l['debut_prevu']) }}% - .35rem);top:.5rem;width:.7rem;height:.7rem;background:{{ $l['retard'] ? '#B42318' : '#D4AF6A' }};transform:rotate(45deg);{{ $l['statut'] === 'annule' ? 'opacity:.35;' : '' }}"></span>
                                @if ($l['debut_signe'] && ! $l['debut_signe']->equalTo($l['debut_prevu']))
                                    <span style="position:absolute;left:calc({{ $pct($l['debut_signe']) }}% - .25rem);top:1.15rem;width:.5rem;height:.5rem;border:1px solid rgb(var(--gray-400));transform:rotate(45deg);"></span>
                                @endif
                            @else
                                <span style="position:absolute;left:{{ $pct($l['debut_prevu']) }}%;width:{{ $largeur($l['debut_prevu'], $l['fin_prevue']) }}%;top:{{ $l['phase'] ? '35%' : (($l['sous_barre'] ?? false) ? '32%' : '22%') }};height:{{ $l['phase'] ? '30%' : (($l['sous_barre'] ?? false) ? '30%' : '45%') }};background:{{ $l['phase'] ? '#3A2452' : (($l['sous_barre'] ?? false) ? '#C9DCE3' : '#A592BD') }};border-radius:2px;overflow:hidden;{{ $l['retard'] ? 'outline:2px solid #B42318;' : '' }}{{ $l['statut'] === 'annule' ? 'opacity:.35;' : '' }}">
                                    @if (! $l['phase'] && $l['avancement'] > 0)
                                        <span style="display:block;height:100%;width:{{ $l['avancement'] }}%;background:{{ ($l['sous_barre'] ?? false) ? '#0F3A4A' : '#4A2E67' }};"></span>
                                    @endif
                                </span>
                            @endif
                            @foreach ($l['actes'] as $a)
                                <span style="position:absolute;left:calc({{ $pct($a['echeance']) }}% - .22rem);top:.1rem;width:.45rem;height:.45rem;border-radius:50%;background:{{ in_array($a['statut'], ['signe', 'diffuse'], true) ? '#1F6B3A' : ($a['alerte'] === 'en_retard' ? '#B42318' : '#0F3A4A') }};"></span>
                            @endforeach
                            @if ($l['debut_reel'])
                                <span style="position:absolute;left:{{ $pct($l['debut_reel']) }}%;width:{{ $largeur($l['debut_reel'], $l['fin_reelle'] ?? $aujourdhui) }}%;top:6%;height:3px;background:#1F6B3A;"></span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </figure>
@endif
