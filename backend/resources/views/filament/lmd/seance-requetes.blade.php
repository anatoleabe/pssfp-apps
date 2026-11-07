@php
    $requetes = $getRecord()->requetes()->with(['auditeur.candidature', 'ecue'])->get();
    $cellule = 'padding:.4rem .6rem .4rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
@endphp
@if ($requetes->isEmpty())
    <p style="font-size:.875rem;">Aucune requête inscrite. Inscrivez les requêtes instruites depuis leur fiche.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Requêtes de la séance</caption>
            <thead>
                <tr style="text-align:left;font-size:.72rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">N°</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Requête</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Auditeur</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Objet</th>
                    <th scope="col" style="padding:.35rem .6rem .35rem 0;">Mention</th>
                    <th scope="col" style="padding:.35rem 0;">Étape</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($requetes as $r)
                    <tr>
                        <td style="{{ $cellule }}">{{ $r->ordre_seance }}</td>
                        <td style="{{ $cellule }}"><a href="{{ \App\Filament\Resources\RequeteResource::getUrl('view', ['record' => $r]) }}" style="text-decoration:underline;">{{ $r->numero }}</a></td>
                        <td style="{{ $cellule }}">{{ $r->auditeur?->candidature?->matricule }}</td>
                        <td style="{{ $cellule }}">{{ $r->objet() }}</td>
                        <td style="{{ $cellule }}">{{ $r->mention ? \App\Models\Requete::MENTIONS[$r->mention] : '—' }}{{ $r->note_retenue !== null ? ' ('.number_format((float) $r->note_retenue, 2, ',', ' ').')' : '' }}</td>
                        <td style="{{ $cellule }}padding-right:0;">{{ \App\Models\Requete::STATUTS[$r->statut] ?? $r->statut }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
