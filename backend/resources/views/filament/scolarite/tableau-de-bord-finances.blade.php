@php
    $svc = app(\App\Services\Scolarite\IndicateursFinanciers::class);
    $lignes = $this->lignes();
    $ind = $svc->indicateurs($lignes);
    $options = $this->options();
    $fcfa = fn (?int $m): string => $m === null ? '—' : \App\Services\Scolarite\LibelleSituation::fcfa($m);
    $pct = fn (?float $t): string => $t === null ? '—' : number_format($t, 1, ',', ' ').' %';
    $moisLibelle = fn (string $m): string => \Carbon\CarbonImmutable::parse($m.'-01')->locale('fr')->isoFormat('MMM YYYY');
    $cellule = 'padding:.35rem .7rem .35rem 0;vertical-align:top;';
    $droite = $cellule.'text-align:right;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
    $entete = 'text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;';
    $ligne = 'border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;';
    $champ = 'display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;';
    $select = 'border-radius:.5rem;border:1px solid rgba(120,113,108,.4);padding:.35rem .5rem;font-size:.85rem;background:transparent;';
    $versements = \App\Filament\Resources\VersementResource::getUrl('index');
    $auditeurs = \App\Filament\Resources\AuditeurResource::getUrl('index');
    $tuiles = [
        ['Total dû', $fcfa($ind['du']), 'Barème moins les remises', $auditeurs],
        ['Total versé', $fcfa($ind['verse']), 'dont '.$fcfa($ind['a_confirmer']).' à confirmer', $versements],
        ['Reste à recouvrer', $fcfa($ind['reste']), 'Dû moins versé', $auditeurs],
        ['Taux de recouvrement', $pct($ind['taux']), 'Confirmé : '.$pct($ind['taux_confirme']), $versements],
        ['Impayé échu', $fcfa($ind['impaye_echu']), 'Sur '.$fcfa($ind['exigible']).' exigibles', $auditeurs],
        ['Inscrits', (string) $ind['inscrits'], $ind['acces_ouvert'].' avec l\'accès eCampus ouvert', $auditeurs],
        ['À jour ou soldés', (string) $ind['a_jour_ou_soldes'], $ind['soldes'].' soldés', $auditeurs],
        ['En retard', (string) $ind['en_retard'], $ind['faute_confirmation'].' faute de confirmation', $auditeurs],
    ];
    $colonnes = ['inscrits' => 'Inscrits', 'du' => 'Dû', 'verse' => 'Versé', 'a_confirmer' => 'Dont à confirmer', 'reste' => 'Reste',
        'taux' => 'Taux', 'a_jour' => 'À jour', 'en_retard' => 'En retard', 'soldes' => 'Soldés'];
    $valeur = fn (string $k, $v): string => match (true) {
        $k === 'taux' => $pct($v),
        in_array($k, ['inscrits', 'a_jour', 'en_retard', 'soldes'], true) => (string) $v,
        default => $fcfa($v),
    };
    $mensuel = $svc->mensuel($lignes);
    $max = max(1, ...array_map(fn (array $m): int => $m['encaisse'], $mensuel));
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Périmètre</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            <label style="{{ $champ }}">Année académique
                <select wire:model.live="annee" style="{{ $select }}">
                    <option value="">Toutes</option>
                    @foreach ($options['annees'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Promotion
                <select wire:model.live="campagne" style="{{ $select }}">
                    <option value="">Toutes</option>
                    @foreach ($options['campagnes'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Niveau
                <select wire:model.live="niveau" style="{{ $select }}">
                    <option value="">Tous</option>
                    @foreach ($options['niveaux'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Parcours
                <select wire:model.live="parcours" style="{{ $select }}">
                    <option value="">Tous</option>
                    @foreach ($options['parcours'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Statut de l'auditeur
                <select wire:model.live="statut" style="{{ $select }}">
                    <option value="">Tous</option>
                    @foreach ($options['statuts'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="display:flex;gap:.4rem;align-items:center;font-size:.8rem;">
                <input type="checkbox" wire:model.live="retires"> Inclure les dossiers retirés
            </label>
        </div>
        <p style="font-size:.78rem;margin:.75rem 0 0;" class="text-gray-500 dark:text-gray-400">
            Les montants comptent tout l'argent enregistré, avec la part que la scolarité doit encore confirmer. Les effectifs (à jour, en retard, soldés) et l'accès ne comptent que l'argent confirmé, comme les fiches et les listes.
        </p>
    </x-filament::section>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem;">
        @foreach ($tuiles as [$titre, $chiffre, $detail, $lien])
            <a href="{{ $lien }}" class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="display:block;padding:1rem;text-decoration:none;">
                <span style="display:block;font-size:.78rem;" class="text-gray-500 dark:text-gray-400">{{ $titre }}</span>
                <span style="display:block;font-size:1.35rem;font-weight:600;font-variant-numeric:tabular-nums;">{{ $chiffre }}</span>
                <span style="display:block;font-size:.75rem;" class="text-gray-500 dark:text-gray-400">{{ $detail }}</span>
            </a>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Argent et effectifs</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Indicateurs détaillés</caption>
                <tbody>
                    @foreach ([
                        'Dû brut (barème)' => $fcfa($ind['du_brut']), 'Remises accordées' => $fcfa($ind['remises']), 'Total dû' => $fcfa($ind['du']),
                        'Total versé' => $fcfa($ind['verse']), 'dont confirmé' => $fcfa($ind['confirme']), 'dont à confirmer' => $fcfa($ind['a_confirmer']),
                        'Reste à recouvrer' => $fcfa($ind['reste']), 'Taux de recouvrement' => $pct($ind['taux']), 'Taux de recouvrement confirmé' => $pct($ind['taux_confirme']),
                        'Exigible à ce jour' => $fcfa($ind['exigible']), 'Impayé échu' => $fcfa($ind['impaye_echu']), 'Taux de recouvrement de l\'exigible' => $pct($ind['taux_exigible']),
                        'Avance au compte des auditeurs du périmètre (toutes années)' => $fcfa($ind['avance']),
                        'Inscrits' => $ind['inscrits'], 'Inscription non soldée' => $ind['non_soldes'], 'À jour' => $ind['a_jour'], 'En retard' => $ind['en_retard'],
                        'Soldés' => $ind['soldes'], 'Années suspendues (reste non réclamé)' => $ind['suspendues'].' · '.$fcfa($ind['reste_suspendu']), 'En retard faute de confirmation' => $ind['faute_confirmation'], 'Avec un versement à confirmer' => $ind['avec_a_confirmer'],
                        'Accès eCampus ouvert' => $ind['acces_ouvert'],
                    ] as $libelle => $v)
                        <tr style="{{ $ligne }}"><th scope="row" style="{{ $cellule }}font-weight:400;">{{ $libelle }}</th><td style="{{ $droite }}">{{ $v }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @foreach ($this->dimensions() as $dimension => $titre)
        <x-filament::section collapsible>
            <x-slot name="heading">Par {{ mb_strtolower($titre) }}</x-slot>
            <div style="overflow-x:auto;font-size:.85rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Ventilation par {{ mb_strtolower($titre) }}</caption>
                    <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                        <th scope="col" style="{{ $cellule }}">{{ $titre }}</th>
                        @foreach ($colonnes as $libelle)<th scope="col" style="{{ $droite }}">{{ $libelle }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($svc->ventilation($lignes, [$dimension]) as $groupe)
                            <tr style="{{ $ligne }}">
                                <th scope="row" style="{{ $cellule }}font-weight:400;">{{ $groupe['cles'][0] }}</th>
                                @foreach ($colonnes as $k => $libelle)<td style="{{ $droite }}">{{ $valeur($k, $groupe['indicateurs'][$k]) }}</td>@endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section collapsible>
        <x-slot name="heading">Argent versé par payeur</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Argent imputé par payeur des versements</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                    <th scope="col" style="{{ $cellule }}">Payeur</th><th scope="col" style="{{ $droite }}">Versé</th><th scope="col" style="{{ $droite }}">Dont confirmé</th>
                </tr></thead>
                <tbody>
                    @foreach ($svc->parPayeur($lignes) as $payeur => $m)
                        <tr style="{{ $ligne }}"><th scope="row" style="{{ $cellule }}font-weight:400;">{{ \App\Models\Versement::PAYEURS[$payeur] ?? $payeur }}</th>
                            <td style="{{ $droite }}">{{ $fcfa($m['verse']) }}</td><td style="{{ $droite }}">{{ $fcfa($m['confirme']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Évolution sur 12 mois</x-slot>
        <x-slot name="description">Encaissé à la date du versement (versements valides) ; enregistré, annulé et confirmé à la date du geste.</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Évolution mensuelle des encaissements</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                    <th scope="col" style="{{ $cellule }}">Mois</th><th scope="col" style="{{ $cellule }}width:30%;">Encaissé</th><th scope="col" style="{{ $droite }}">Encaissé</th>
                    <th scope="col" style="{{ $droite }}">Enregistré</th><th scope="col" style="{{ $droite }}">Annulé</th><th scope="col" style="{{ $droite }}">Confirmé</th>
                    <th scope="col" style="{{ $droite }}">Corrections</th><th scope="col" style="{{ $droite }}">Cumul</th>
                </tr></thead>
                <tbody>
                    @foreach ($mensuel as $m)
                        <tr style="{{ $ligne }}">
                            <th scope="row" style="{{ $cellule }}font-weight:400;">{{ $moisLibelle($m['mois']) }}</th>
                            <td style="{{ $cellule }}" aria-hidden="true"><span class="bg-primary-500" style="display:block;height:.6rem;border-radius:.2rem;width:{{ round(100 * $m['encaisse'] / $max, 1) }}%;"></span></td>
                            <td style="{{ $droite }}">{{ $fcfa($m['encaisse']) }}</td><td style="{{ $droite }}">{{ $fcfa($m['enregistre']) }}</td>
                            <td style="{{ $droite }}">{{ $fcfa($m['annule']) }}</td><td style="{{ $droite }}">{{ $fcfa($m['confirme']) }}</td>
                            <td style="{{ $droite }}">{{ $m['corrections'] }}</td><td style="{{ $droite }}">{{ $fcfa($m['cumul']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p style="font-size:.78rem;margin:.5rem 0 0;" class="text-gray-500 dark:text-gray-400">Cumul des 12 mois : {{ $fcfa(end($mensuel)['cumul'] ?? 0) }}, pour un total dû de {{ $fcfa($ind['du']) }}.</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Ancienneté des impayés</x-slot>
        <x-slot name="description">Droits d'inscription exigibles dès la création de l'inscription, tranches à leur échéance. Un auditeur compte dans la classe de son impayé le plus ancien.</x-slot>
        <div style="overflow-x:auto;font-size:.85rem;">
            <table style="width:100%;border-collapse:collapse;">
                <caption style="{{ $masque }}">Ancienneté des impayés</caption>
                <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                    <th scope="col" style="{{ $cellule }}">Retard</th><th scope="col" style="{{ $droite }}">Montant impayé</th><th scope="col" style="{{ $droite }}">Auditeurs</th>
                </tr></thead>
                <tbody>
                    @foreach ($svc->anciennete($lignes) as $classe => $a)
                        <tr style="{{ $ligne }}"><th scope="row" style="{{ $cellule }}font-weight:400;">{{ \App\Services\Scolarite\IndicateursFinanciers::ANCIENNETES[$classe] }}</th>
                            <td style="{{ $droite }}">{{ $fcfa($a['montant']) }}</td><td style="{{ $droite }}">{{ $a['auditeurs'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
