@php
    $lignes = $this->lignes();
    $totaux = \App\Services\Scolarite\ListeSolvabilite::totaux($lignes);
    $affichees = $lignes->where('categorie', $this->onglet)->values();
    $options = $this->options();
    $fcfa = fn (int $m): string => \App\Services\Scolarite\LibelleSituation::fcfa($m);
    $cellule = 'padding:.4rem .7rem .4rem 0;vertical-align:top;';
    $droite = $cellule.'text-align:right;white-space:nowrap;';
    $ligne = 'border-top:1px solid rgba(120,113,108,.2);font-variant-numeric:tabular-nums;';
    $champ = 'display:flex;flex-direction:column;gap:.25rem;font-size:.8rem;';
    $select = 'border-radius:.5rem;border:1px solid rgba(120,113,108,.4);padding:.35rem .5rem;font-size:.85rem;background:transparent;';
    $entete = 'text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;';
    $masque = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);';
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Périmètre</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            <label style="{{ $champ }}">Promotion
                <select wire:model.live="campagne" style="{{ $select }}">
                    <option value="">Toutes</option>
                    @foreach ($options['campagnes'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
            </label>
            <label style="{{ $champ }}">Niveau (inscription courante)
                <select wire:model.live="niveau" style="{{ $select }}">
                    <option value="">Tous</option>
                    @foreach ($options['niveaux'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
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
            Sur tout le parcours de l'auditeur, arriérés compris ; les versements à confirmer comptent comme payés. Échu impayé : droits d'inscription restants et tranches dont l'échéance est passée.
            En règle : rien d'échu impayé. Partiellement insolvable : un échu impayé malgré au moins un versement. Totalement insolvable : un échu impayé sans aucun versement.
        </p>
    </x-filament::section>

    <x-filament::tabs label="Situation">
        @foreach (\App\Services\Scolarite\Solvabilite::LIBELLES as $cle => $libelle)
            <x-filament::tabs.item :active="$this->onglet === $cle" wire:click="$set('onglet', '{{ $cle }}')"
                :badge="$totaux[$cle]['effectif']" :badge-color="\App\Services\Scolarite\Solvabilite::couleur($cle)">
                {{ $libelle }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <x-filament::section>
        <x-slot name="heading">{{ \App\Services\Scolarite\Solvabilite::LIBELLES[$this->onglet] }} : {{ $affichees->count() }} auditeur(s)</x-slot>
        <x-slot name="description">Échu impayé : {{ $fcfa($totaux[$this->onglet]['echu_impaye']) }} · reste à payer : {{ $fcfa($totaux[$this->onglet]['reste']) }} · versé : {{ $fcfa($totaux[$this->onglet]['verse']) }}</x-slot>
        @if ($affichees->isEmpty())
            <p style="font-size:.85rem;" class="text-gray-500 dark:text-gray-400">Aucun auditeur dans cette situation pour ce périmètre.</p>
        @else
            <div style="overflow-x:auto;font-size:.85rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <caption style="{{ $masque }}">Auditeurs — {{ \App\Services\Scolarite\Solvabilite::LIBELLES[$this->onglet] }}</caption>
                    <thead><tr class="text-gray-500 dark:text-gray-400" style="{{ $entete }}">
                        <th scope="col" style="{{ $cellule }}">Matricule</th>
                        <th scope="col" style="{{ $cellule }}">Auditeur</th>
                        <th scope="col" style="{{ $cellule }}">Promo.</th>
                        <th scope="col" style="{{ $cellule }}">Niveau</th>
                        <th scope="col" style="{{ $droite }}">Dû</th>
                        <th scope="col" style="{{ $droite }}">Versé</th>
                        <th scope="col" style="{{ $droite }}">Échu impayé</th>
                        <th scope="col" style="{{ $droite }}">Reste à payer</th>
                        <th scope="col" style="{{ $cellule }}"><span style="{{ $masque }}">Actions</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($affichees as $l)
                            <tr style="{{ $ligne }}" wire:key="solv-{{ $l['uuid'] }}">
                                <td style="{{ $cellule }}white-space:nowrap;">{{ $l['matricule'] }}</td>
                                <th scope="row" style="{{ $cellule }}font-weight:500;">
                                    <a href="{{ \App\Filament\Resources\AuditeurResource::getUrl('view', ['record' => $l['uuid']]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $l['nom'] }} {{ $l['prenom'] }}</a>
                                </th>
                                <td style="{{ $cellule }}">{{ $l['promotion'] }}</td>
                                <td style="{{ $cellule }}">{{ $l['niveau'] }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($l['du']) }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($l['verse']) }}@if ($l['a_confirmer'] > 0)<br><span style="font-size:.72rem;" class="text-gray-500 dark:text-gray-400">dont {{ $fcfa($l['a_confirmer']) }} à confirmer</span>@endif</td>
                                <td style="{{ $droite }}">{{ $fcfa($l['echu_impaye']) }}</td>
                                <td style="{{ $droite }}">{{ $fcfa($l['reste']) }}</td>
                                <td style="{{ $cellule }}white-space:nowrap;">
                                    <a href="{{ route('filament.admin.auditeurs.extrait-compte', $l['uuid']) }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">Extrait de compte</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
