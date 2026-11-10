@php
    $user = auth()->user();
    $nonLus = $user instanceof \App\Models\User && \App\Filament\Resources\MessageResource::canAccess()
        ? app(\App\Services\Messagerie\Messagerie::class)->nonLus($user) : null;
@endphp
@if ($nonLus !== null)
    <x-filament::icon-button
        tag="a"
        :href="\App\Filament\Resources\MessageResource::getUrl('index')"
        icon="heroicon-o-envelope"
        color="gray"
        size="lg"
        :label="$nonLus > 0 ? $nonLus.' message(s) non lu(s)' : 'Messagerie'"
        :badge="$nonLus > 0 ? $nonLus : null"
        badge-color="primary"
        class="me-2"
    />
@endif
