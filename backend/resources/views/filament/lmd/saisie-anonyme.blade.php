<x-filament-panels::page>
    <form wire:submit="enregistrer" style="display:grid;gap:1rem;">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">Enregistrer la note</x-filament::button>
        </div>
    </form>
    @php $attente = $this->enAttente(); @endphp
    @if (($this->donnees['epreuve'] ?? null) !== null)
        <x-filament::section>
            <x-slot name="heading">Numéros en attente pour cette correction ({{ count($attente) }})</x-slot>
            @if ($attente === [])
                <p style="font-size:.875rem;">Aucun numéro en attente.</p>
            @else
                <p style="font-family:ui-monospace,monospace;font-size:.875rem;line-height:1.8;">{{ implode('  ·  ', $attente) }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
