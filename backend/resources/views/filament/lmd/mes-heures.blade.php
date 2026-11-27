@php
    use App\Models\EtatPaie;
    use App\Services\Scolarite\LibelleSituation;

    $enseignant = $this->enseignant();
    $heures = $this->espace()->heures($enseignant);
    $paie = $this->espace()->paie($enseignant);
    $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',').' h';
    $carte = 'padding:.75rem 0;';
    $grille = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(8.5rem,1fr));gap:.5rem 1rem;margin:.5rem 0 0;';
    $terme = 'font-size:.72rem;text-transform:uppercase;';
    $valeur = 'margin:0;font-weight:600;';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Mes heures par cours</x-slot>
        <x-slot name="description">Seules les heures validées par la Scolarité sont payées, dans la limite des heures allouées.</x-slot>
        @forelse ($heures as $l)
            <div class="border-b border-gray-200 dark:border-white/10" style="{{ $carte }}">
                <div style="font-weight:600;">{{ $l['ecue']->code }} — {{ $l['ecue']->intitule }}</div>
                <div class="text-gray-500 dark:text-gray-400" style="font-size:.875rem;">{{ $l['periode'] }}</div>
                <dl style="{{ $grille }}">
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Allouées</dt><dd style="{{ $valeur }}">{{ $h($l['alloue']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Programmées</dt><dd style="{{ $valeur }}">{{ $h($l['programme']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Validées</dt><dd style="{{ $valeur }}">{{ $h($l['valide']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">À valider</dt><dd style="{{ $valeur }}">{{ $h($l['a_valider']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Sur un état de paie</dt><dd style="{{ $valeur }}">{{ $h($l['en_paie']) }}</dd></div>
                    @if ($l['rejetes'] > 0)
                        <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Émargements rejetés</dt><dd style="{{ $valeur }}">{{ $l['rejetes'] }}</dd></div>
                    @endif
                </dl>
            </div>
        @empty
            <p style="font-size:.875rem;margin:0;">Aucune programmation ni aucun émargement à votre nom.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Mes paiements</x-slot>
        <x-slot name="description">États de paie arrêtés qui portent vos heures. Un état en préparation n'apparaît pas encore.</x-slot>
        @forelse ($paie as $l)
            <div class="border-b border-gray-200 dark:border-white/10" style="{{ $carte }}">
                <div style="display:flex;flex-wrap:wrap;gap:.25rem .75rem;align-items:center;">
                    <span style="font-weight:600;">{{ $l['etat']->numero }} — {{ $l['etat']->mois() }}</span>
                    <x-filament::badge :color="$l['etat']->statut === EtatPaie::PAYE ? 'success' : 'info'" style="display:inline-flex;">{{ EtatPaie::STATUTS[$l['etat']->statut] ?? $l['etat']->statut }}</x-filament::badge>
                </div>
                <dl style="{{ $grille }}">
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Heures</dt><dd style="{{ $valeur }}">{{ $h($l['heures']) }}</dd></div>
                    <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Net à percevoir</dt><dd style="{{ $valeur }}">{{ LibelleSituation::fcfa($l['net']) }}</dd></div>
                    @if ($l['etat']->paye_le !== null)
                        <div><dt class="text-gray-500 dark:text-gray-400" style="{{ $terme }}">Payé le</dt><dd style="{{ $valeur }}">{{ $l['etat']->paye_le->format('d/m/Y') }}</dd></div>
                    @endif
                </dl>
            </div>
        @empty
            <p style="font-size:.875rem;margin:0;">Aucun état de paie arrêté ne porte encore vos heures.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
