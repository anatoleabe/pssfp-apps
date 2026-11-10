@php
    $svc = app(\App\Services\Lmd\ControleGestion::class);
    $mensuel = $svc->tableauMensuel();
    $retards = $svc->dossiersEnRetard();
    $couts = $this->couts();
    $fcfa = fn (?int $m): string => $m === null ? '—' : \App\Services\Lmd\EtatsPaie::fcfa($m);
    $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ' '), '0'), ',');
    $date = fn (string $d): string => \Carbon\CarbonImmutable::parse($d)->format('d/m/Y');
    $moisLibelle = fn (string $m): string => \Carbon\CarbonImmutable::parse($m.'-01')->locale('fr')->isoFormat('MMM YYYY');
    $cellule = 'padding:.35rem .7rem .35rem 0;vertical-align:top;';
    $droite = $cellule.'text-align:right;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
    $entete = 'text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;';
    $ligne = 'border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Dossiers en retard ({{ count($retards) }})</x-slot>
        <x-slot name="description">Requêtes ouvertes depuis plus de {{ $svc->delaiRequetes() }} jours ; états de paie ni payés ni rejetés 30 jours après la fin de leur période.</x-slot>
        @if ($retards === [])
            <p style="font-size:.875rem;margin:0;">Aucun dossier en retard.</p>
        @else
            <div style="overflow-x:auto;font-size:.85rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Dossiers en retard</caption>
                    <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                        <th scope="col" style="{{ $cellule }}">Dossier</th><th scope="col" style="{{ $cellule }}">Numéro</th><th scope="col" style="{{ $cellule }}">Statut</th>
                        <th scope="col" style="{{ $cellule }}">Ouvert le</th><th scope="col" style="{{ $cellule }}">Échéance</th><th scope="col" style="{{ $droite }}">Retard</th><th scope="col" style="{{ $cellule }}">Responsable</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($retards as $r)
                            <tr style="{{ $ligne }}">
                                <td style="{{ $cellule }}">{{ $r['dossier'] }}</td>
                                <td style="{{ $cellule }}">
                                    @if ($r['etat_uuid'])
                                        <a href="{{ \App\Filament\Resources\EtatPaieResource::getUrl('view', ['record' => $r['etat_uuid']]) }}" class="text-primary-600 dark:text-primary-400" style="text-decoration:underline;">{{ $r['numero'] }}</a>
                                    @else
                                        {{ $r['numero'] }}
                                    @endif
                                </td>
                                <td style="{{ $cellule }}">{{ $r['statut'] }}</td>
                                <td style="{{ $cellule }}">{{ $date($r['ouvert_le']) }}</td>
                                <td style="{{ $cellule }}">{{ $date($r['echeance']) }}</td>
                                <td style="{{ $droite }}">{{ $r['jours'] }} j</td>
                                <td style="{{ $cellule }}">{{ $r['responsable'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Tableau de bord des 12 derniers mois</x-slot>
        <x-slot name="description">Heures validées au mois de la séance ; paie au mois de fin de période des états transmis, pris en charge ou payés (bruts).</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Activité mensuelle</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                    <th scope="col" style="{{ $cellule }}">Mois</th><th scope="col" style="{{ $droite }}">Heures validées</th><th scope="col" style="{{ $droite }}">Heures de CM payées</th>
                    <th scope="col" style="{{ $droite }}">Paie brute</th><th scope="col" style="{{ $droite }}">Retenues</th><th scope="col" style="{{ $droite }}">Enseignants payés</th>
                    <th scope="col" style="{{ $droite }}">Requêtes déposées</th><th scope="col" style="{{ $droite }}">Requêtes clôturées</th><th scope="col" style="{{ $droite }}">Délibérations publiées</th>
                </tr></thead>
                <tbody>
                    @foreach ($mensuel as $m)
                        <tr style="{{ $ligne }}">
                            <th scope="row" style="{{ $cellule }}font-weight:500;">{{ $moisLibelle($m['mois']) }}</th>
                            <td style="{{ $droite }}">{{ $h($m['heures_validees']) }}</td>
                            <td style="{{ $droite }}">{{ $h($m['heures_cm_payees']) }}</td>
                            <td style="{{ $droite }}">{{ $fcfa($m['paie_brute']) }}</td>
                            <td style="{{ $droite }}">{{ $fcfa($m['retenues']) }}</td>
                            <td style="{{ $droite }}">{{ $m['enseignants_payes'] }}</td>
                            <td style="{{ $droite }}">{{ $m['requetes_deposees'] }}</td>
                            <td style="{{ $droite }}">{{ $m['requetes_cloturees'] }}</td>
                            <td style="{{ $droite }}">{{ $m['deliberations_publiees'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Coûts de l'année</x-slot>
        <x-slot name="description">Réalisé de la paie des enseignants (états transmis, pris en charge ou payés), en brut.</x-slot>
        @if ($this->annees() === [])
            <p style="font-size:.875rem;margin:0;">Aucune maquette : pas encore de coûts à calculer.</p>
        @else
            <div style="margin-bottom:1rem;max-width:16rem;">
                <label for="annee-controle" style="font-size:.85rem;font-weight:500;">Année académique</label>
                <x-filament::input.wrapper style="margin-top:.25rem;">
                    <x-filament::input.select id="annee-controle" wire:model.live="annee">
                        @foreach ($this->annees() as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <h3 style="font-weight:600;margin:.5rem 0;">Par parcours</h3>
            <div style="overflow-x:auto;font-size:.85rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Coûts par parcours, {{ $annee }}</caption>
                    <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                        <th scope="col" style="{{ $cellule }}">Parcours</th><th scope="col" style="{{ $droite }}">UE</th><th scope="col" style="{{ $droite }}">Heures de CM payées</th>
                        <th scope="col" style="{{ $droite }}">CM</th><th scope="col" style="{{ $droite }}">Primes</th><th scope="col" style="{{ $droite }}">Total</th>
                        <th scope="col" style="{{ $droite }}">Auditeurs</th><th scope="col" style="{{ $droite }}">Coût par auditeur</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($couts['parcours'] as $p)
                            <tr style="{{ $ligne }}">
                                <th scope="row" style="{{ $cellule }}font-weight:500;">{{ $p['parcours'] }}</th>
                                <td style="{{ $droite }}">{{ $p['ues'] }}</td><td style="{{ $droite }}">{{ $h($p['heures_cm']) }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($p['cout_cm']) }}</td><td style="{{ $droite }}">{{ $fcfa($p['cout_primes']) }}</td>
                                <td style="{{ $droite }}font-weight:600;">{{ $fcfa($p['cout_total']) }}</td>
                                <td style="{{ $droite }}">{{ $p['auditeurs'] }}</td><td style="{{ $droite }}">{{ $fcfa($p['cout_par_auditeur']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="{{ $cellule }}">Aucune UE pour cette année.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p style="font-size:.8rem;margin:.5rem 0 1rem;">Hors UE (surveillances) : {{ $fcfa($couts['hors_ue']) }}.</p>

            <h3 style="font-weight:600;margin:.5rem 0;">Par UE</h3>
            <div style="overflow-x:auto;font-size:.85rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Coûts par UE, {{ $annee }}</caption>
                    <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                        <th scope="col" style="{{ $cellule }}">Parcours</th><th scope="col" style="{{ $cellule }}">UE</th><th scope="col" style="{{ $droite }}">Heures de CM payées</th>
                        <th scope="col" style="{{ $droite }}">CM</th><th scope="col" style="{{ $droite }}">Primes</th><th scope="col" style="{{ $droite }}">Total</th>
                        <th scope="col" style="{{ $droite }}">Inscrits</th><th scope="col" style="{{ $droite }}">Coût par inscrit</th><th scope="col" style="{{ $droite }}">Coût horaire</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($couts['ues'] as $u)
                            <tr style="{{ $ligne }}">
                                <td style="{{ $cellule }}">{{ $u['parcours'] }}</td><td style="{{ $cellule }}">{{ $u['ue'] }}</td>
                                <td style="{{ $droite }}">{{ $h($u['heures_cm']) }}</td><td style="{{ $droite }}">{{ $fcfa($u['cout_cm']) }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($u['cout_primes']) }}</td><td style="{{ $droite }}font-weight:600;">{{ $fcfa($u['cout_total']) }}</td>
                                <td style="{{ $droite }}">{{ $u['inscrits'] }}</td><td style="{{ $droite }}">{{ $fcfa($u['cout_par_inscrit']) }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($u['cout_horaire']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
