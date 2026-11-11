@php
    $u = $this->utilisateur();
    $active = $u->hasEnabledTwoFactorAuthentication();
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Double authentification</x-slot>
        <x-slot name="description">
            Après votre mot de passe, la plateforme vous demande un code à 6 chiffres affiché par une application
            d'authentification sur votre téléphone (Google Authenticator, Microsoft Authenticator, FreeOTP…).
        </x-slot>

        @if ($active)
            <p class="text-sm"><x-filament::badge color="success">Active</x-filament::badge>
                depuis le {{ $u->two_factor_confirmed_at?->setTimezone('Africa/Douala')->format('d/m/Y') }}.</p>
            @if ($this::obligatoire())
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Elle est obligatoire pour le personnel. Téléphone perdu : demandez une réinitialisation à un super administrateur.</p>
            @endif
        @elseif ($this->enConfirmation())
            <ol class="list-decimal space-y-2 ps-5 text-sm">
                <li>Dans votre application, ajoutez un compte en scannant ce QR code :</li>
            </ol>
            <div class="my-4 inline-block rounded-lg bg-white p-3">{!! $u->twoFactorQrCodeSvg() !!}</div>
            <p class="text-sm">Ou saisissez cette clé : <code class="select-all rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-white/10">{{ decrypt($u->two_factor_secret) }}</code></p>
            <p class="mt-3 text-sm">Puis confirmez avec le code affiché par l'application.</p>
        @else
            <p class="text-sm"><x-filament::badge color="warning">Inactive</x-filament::badge>
                Votre compte n'est protégé que par son mot de passe.</p>
            @if ($this::obligatoire())
                <p class="mt-2 text-sm font-medium text-danger-600 dark:text-danger-400">Elle est obligatoire : activez-la pour accéder au reste du back-office.</p>
            @endif
        @endif

        <div class="mt-4 flex flex-wrap gap-3">
            {{ $this->activerAction }}
            {{ $this->confirmerAction }}
            {{ $this->annulerAction }}
            {{ $this->nouveauxCodesAction }}
            {{ $this->desactiverAction }}
        </div>
    </x-filament::section>

    @php($codesAffiches = $this->codesAffiches())
    @if ($codesAffiches)
        <x-filament::section>
            <x-slot name="heading">Vos codes de secours</x-slot>
            <x-slot name="description">Chacun ne sert qu'une fois, si votre téléphone n'est pas disponible. Notez-les ou imprimez-les maintenant : ils ne seront plus affichés.</x-slot>
            <ul class="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                @foreach ($codesAffiches as $c)
                    <li class="select-all rounded bg-gray-100 px-2 py-1 dark:bg-white/10">{{ $c }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

</x-filament-panels::page>
