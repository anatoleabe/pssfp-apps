@component('mail::message')
# {{ __('mail.candidature.refused.greeting', ['name' => trim("{$candidature->prenom} {$candidature->nom}")]) }}

{{ __('mail.candidature.refused.intro') }}

**{{ __('mail.candidature.refused.numero_dossier') }} :** {{ $candidature->numero_dossier }}

## {{ __('mail.candidature.refused.motif_title') }}

> {{ $motif }}

{{ __('mail.candidature.refused.encouragement') }}

{{ __('mail.candidature.refused.signature') }}
@endcomponent
