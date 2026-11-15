@php
    /*
     * Styles en ligne : la feuille de style de Filament ne contient pas les classes
     * utilitaires arbitraires. Couleurs prises dans les variables du thème Filament,
     * pour rester lisibles en mode sombre.
     */
    $inscription = $getRecord()->inscriptionCourante;
    $situation = $inscription?->situation();
    // Avance du compte (ADR-0013) : versements valides non imputés, toutes années.
    $compte = \App\Services\Scolarite\CompteAuditeur::pour($getRecord());
    $fcfa = fn (int $m): string => \App\Services\Scolarite\LibelleSituation::fcfa($m);
    $cellule = 'padding:0.55rem 1rem 0.55rem 0;border-bottom:1px solid rgb(var(--gray-200));';
    $nombre = $cellule.'text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;';
    $entete = 'padding:0.4rem 1rem 0.4rem 0;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:rgb(var(--gray-500));font-weight:600;';
@endphp
@if ($situation === null)
    <p style="font-size:.875rem;">
        Aucune inscription : le barème M1 de l'année de cette promotion n'existe pas encore. Un responsable de scolarité le saisit dans Vie académique &gt; Barèmes, puis « Créer l'inscription ».
    </p>
@else
    <div class="fi-scolarite-situation" style="overflow-x:auto;">
        <style>.dark .fi-scolarite-situation td, .dark .fi-scolarite-situation th { border-color: rgb(var(--gray-700)) !important; } .dark .fi-scolarite-situation [style*='--success-600'] { color: rgb(var(--success-400)) !important; } .dark .fi-scolarite-situation [style*='--warning-600'] { color: rgb(var(--warning-400)) !important; } .dark .fi-scolarite-situation thead th { color: rgb(var(--gray-400)) !important; }</style>
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Paliers de paiement de l'inscription {{ $inscription->annee_academique }}</caption>
            <thead>
                <tr>
                    <th scope="col" style="{{ $entete }}text-align:left;">Palier</th>
                    <th scope="col" style="{{ $entete }}text-align:right;">Dû</th>
                    <th scope="col" style="{{ $entete }}text-align:right;">Payé</th>
                    <th scope="col" style="{{ $entete }}text-align:right;">Reste</th>
                    <th scope="col" style="{{ $entete }}text-align:left;padding-left:1.5rem;">Échéance</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($situation->paliers as $palier)
                    <tr>
                        <th scope="row" style="{{ $cellule }}text-align:left;font-weight:500;">{{ $palier->libelle() }}</th>
                        <td style="{{ $nombre }}">{{ $fcfa($palier->du) }}</td>
                        <td style="{{ $nombre }}">{{ $fcfa($palier->paye) }}</td>
                        <td style="{{ $nombre }}">
                            @if ($palier->solde())
                                <span style="font-weight:600;color:rgb(var(--success-600));">Soldé</span>
                            @else
                                {{ $fcfa($palier->reste) }}
                            @endif
                        </td>
                        <td style="{{ $cellule }}padding-left:1.5rem;">
                            {{ $palier->cle === 'inscription' ? ($inscription->bareme?->inscription_echeance ?? 'Non fixée') : ($palier->echeance?->format('d/m/Y') ?? 'Non fixée') }}
                            @if ($palier->enRetard)
                                <span style="margin-left:.35rem;font-weight:600;color:rgb(var(--warning-600));">En retard</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="font-weight:600;">
                    <th scope="row" style="{{ $cellule }}text-align:left;border-bottom:none;">Total</th>
                    <td style="{{ $nombre }}border-bottom:none;">{{ $fcfa($situation->duTotal) }}</td>
                    <td style="{{ $nombre }}border-bottom:none;">{{ $fcfa($situation->verse) }}</td>
                    <td style="{{ $nombre }}border-bottom:none;">{{ $fcfa($situation->reste) }}</td>
                    <td style="border-bottom:none;"></td>
                </tr>
            </tfoot>
        </table>
    </div>
    @if ($situation->aConfirmer > 0)
        <p style="margin-top:.75rem;font-size:.875rem;font-weight:600;color:rgb(var(--warning-600));">Dont {{ $fcfa($situation->aConfirmer) }} à confirmer par la scolarité sur le reçu bancaire original : cet argent ne compte pas encore pour l'accès à eCampus ni pour la situation « à jour ».</p>
    @endif
    @if ($situation->remise > 0)
        <p style="margin-top:.75rem;font-size:.875rem;">Remise accordée : <strong>{{ $fcfa($situation->remise) }}</strong>@if ($inscription->remise_motif) — {{ $inscription->remise_motif }}@endif</p>
    @endif
    @if ($compte->avance() > 0)
        <p style="margin-top:.5rem;font-size:.875rem;font-weight:600;color:rgb(var(--warning-600));">Avance au compte : {{ $fcfa($compte->avance()) }}</p>
        @if ($compte->avanceReservee() > 0)
            <p style="margin-top:.25rem;font-size:.8125rem;">dont {{ $fcfa($compte->avanceReservee()) }} versés par l'employeur, non imputés d'office sur une autre année (décision du responsable)</p>
        @endif
    @endif
@endif
