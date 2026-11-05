@php
    /* Description, auteur et date seulement : les propriétés du journal ne sont pas affichées. */
    // Les entrées du dossier de candidature ne s'affichent qu'à qui peut ouvrir ce dossier.
    $dossier = $getRecord()->candidature;
    $entrees = $getRecord()->historique(100, $dossier !== null && \App\Filament\Resources\CandidatureResource::canAccess() && \App\Filament\Resources\CandidatureResource::canView($dossier));
    $cellule = 'padding:0.45rem 1rem 0.45rem 0;border-bottom:1px solid rgb(var(--gray-200));vertical-align:top;';
@endphp
@if ($entrees->isEmpty())
    <p style="font-size:.875rem;">Aucune entrée.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Historique de l'auditeur</caption>
            <thead>
                <tr style="text-align:left;font-size:.75rem;text-transform:uppercase;color:rgb(var(--gray-500));">
                    <th scope="col" style="padding:.4rem 1rem .4rem 0;">Date</th>
                    <th scope="col" style="padding:.4rem 1rem .4rem 0;">Événement</th>
                    <th scope="col" style="padding:.4rem 0;">Par</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entrees as $e)
                    <tr>
                        <td style="{{ $cellule }}white-space:nowrap;">{{ $e->created_at?->timezone('Africa/Douala')->format('d/m/Y H:i') }}</td>
                        <td style="{{ $cellule }}">{{ $e->description }}</td>
                        <td style="{{ $cellule }}padding-right:0;">{{ $e->causer?->name ?? 'Système' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
