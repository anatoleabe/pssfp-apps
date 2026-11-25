@php($raccourcis = \App\Filament\Widgets\RaccourcisWidget::raccourcis())
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Raccourcis</x-slot>
        <x-slot name="description">Pour retrouver un auditeur, un dossier ou un compte : la recherche en haut de l'écran (Ctrl+K).</x-slot>
        <ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.75rem;margin:0;padding:0;list-style:none;">
            @foreach ($raccourcis as $r)
                <li>
                    <a href="{{ $r['url'] }}" class="flex h-full items-start gap-3 rounded-lg p-3 ring-1 ring-gray-950/10 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:ring-white/10 dark:hover:bg-white/5">
                        <x-filament::icon :icon="$r['icone']" class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
                        <span>
                            <span class="block font-semibold text-gray-950 dark:text-white">{{ $r['libelle'] }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $r['description'] }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
