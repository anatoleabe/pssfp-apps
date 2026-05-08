@component('mail::message')
# {{ __('mail.candidature.accepted.greeting', ['name' => trim("{$candidature->prenom} {$candidature->nom}")]) }}

{{ __('mail.candidature.accepted.intro') }}

**{{ __('mail.candidature.accepted.numero_dossier') }} :** {{ $candidature->numero_dossier }}
**{{ __('mail.candidature.accepted.specialite') }} :** {{ $candidature->specialite }}

## {{ __('mail.candidature.accepted.next_steps_title') }}

{{ __('mail.candidature.accepted.next_steps_body') }}

@if($internalComment)
## {{ __('mail.candidature.accepted.comment_title') }}

> {{ $internalComment }}
@endif

@component('mail::button', ['url' => url("/v1/c/{$candidature->uuid}/qr")])
{{ __('mail.candidature.accepted.cta_check') }}
@endcomponent

{{ __('mail.candidature.accepted.signature') }}
@endcomponent
