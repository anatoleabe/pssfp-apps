@php($effectif = $this->effectif())
<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section>
        <x-slot name="heading">Éditer la liste</x-slot>
        <x-slot name="description">En-tête officiel du PSSFP, critères rappelés en tête, date d'édition et pagination. Document interne : ni téléphone ni e-mail.</x-slot>

        <p style="font-size:.875rem;margin:0 0 1rem;" aria-live="polite">
            @if ($effectif === null)
                Choisissez une promotion et au moins un statut.
            @elseif ($effectif === 0)
                Aucun auditeur ne correspond à ces critères.
            @elseif ($effectif === 1)
                1 auditeur correspond à ces critères.
            @else
                {{ $effectif }} auditeurs correspondent à ces critères.
            @endif
        </p>

        <div style="display:flex;flex-wrap:wrap;gap:.75rem;">
            {{ $this->situationFinanciereAction }}
            {{ $this->listeNominativeAction }}
        </div>
    </x-filament::section>
</x-filament-panels::page>
