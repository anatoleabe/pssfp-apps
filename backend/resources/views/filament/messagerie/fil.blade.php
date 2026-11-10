@php
    $moi = auth()->id();
    $fuseau = 'Africa/Douala';
@endphp
<x-filament-panels::page>
    <div class="space-y-4">
        @foreach ($this->getFil() as $m)
            <x-filament::section @class(['ring-2 ring-primary-500/40' => $m->is($this->record)])>
                <x-slot name="heading">
                    {{ $m->expediteur?->name ?? 'Système' }}
                </x-slot>
                <x-slot name="description">
                    {{ $m->envoye_le->setTimezone($fuseau)->format('d/m/Y à H:i') }}
                    · À : {{ $m->destinataires->map(fn ($d) => $d->user?->name)->filter()->join(', ') }}
                </x-slot>
                <x-slot name="headerEnd">
                    <div class="flex gap-2">
                        @if ($m->categorie !== \App\Models\Message::MESSAGE)
                            <x-filament::badge color="info">{{ \App\Models\Message::CATEGORIES[$m->categorie] }}</x-filament::badge>
                        @endif
                        @if ($m->priorite !== \App\Models\Message::NORMALE)
                            <x-filament::badge :color="$m->priorite === \App\Models\Message::URGENTE ? 'danger' : 'warning'">
                                {{ \App\Models\Message::PRIORITES[$m->priorite] }}
                            </x-filament::badge>
                        @endif
                    </div>
                </x-slot>

                <div class="prose max-w-none text-sm dark:prose-invert">{!! nl2br(e($m->corps)) !!}</div>

                @if ($m->pieces->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($m->pieces as $p)
                            <x-filament::button size="sm" color="gray" icon="heroicon-o-paper-clip" wire:click="telecharger({{ $p->id }})">
                                {{ $p->nom_fichier }} ({{ \Illuminate\Support\Number::fileSize($p->taille_octets, 1) }})
                            </x-filament::button>
                        @endforeach
                    </div>
                @endif

                @if ($m->expediteur_id === $moi)
                    <div class="mt-4 border-t border-gray-200 pt-3 text-xs text-gray-600 dark:border-white/10 dark:text-gray-400">
                        <span class="font-medium">Accusés de lecture :</span>
                        @foreach ($m->destinataires as $d)
                            <span class="ms-2 whitespace-nowrap">
                                {{ $d->user?->name ?? 'Compte supprimé' }} —
                                {{ $d->lu_le ? 'lu le '.$d->lu_le->setTimezone($fuseau)->format('d/m/Y à H:i') : 'non lu' }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
