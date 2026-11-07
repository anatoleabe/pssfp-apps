@php
    $s = $getRecord();
    $pointages = $s->pointages()->with('inscriptionPedagogique.inscriptionAnnuelle.auditeur.candidature')->get()
        ->sortBy(fn ($p) => mb_strtoupper((string) $p->inscriptionPedagogique?->inscriptionAnnuelle?->auditeur?->candidature?->nom))->values();
    $cellule = 'padding:.35rem .6rem .35rem 0;border-bottom:1px solid rgb(var(--gray-200));';
@endphp
@if ($pointages->isEmpty())
    <p style="font-size:.875rem;">Pas encore de pointage : ouvrez la feuille de présence ou importez le rapport Zoom.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Feuille de présence</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Matricule</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Nom</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Présence</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Mode</th>
                    <th scope="col" style="padding:.35rem 0;">Connexion / motif</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pointages as $p)
                    @php $d = $p->inscriptionPedagogique?->inscriptionAnnuelle?->auditeur?->candidature; @endphp
                    <tr>
                        <td style="{{ $cellule }}font-family:ui-monospace,monospace;">{{ $d?->matricule }}</td>
                        <td style="{{ $cellule }}">{{ trim(mb_strtoupper((string) $d?->nom).' '.$d?->prenom) }}</td>
                        <td style="{{ $cellule }}font-weight:600;">{{ \App\Models\Pointage::STATUTS[$p->statut] ?? $p->statut }}</td>
                        <td style="{{ $cellule }}">{{ $p->mode }}</td>
                        <td style="{{ $cellule }}padding-right:0;">{{ $p->duree_min !== null ? $p->duree_min.' min' : '' }}{{ $p->motif }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
