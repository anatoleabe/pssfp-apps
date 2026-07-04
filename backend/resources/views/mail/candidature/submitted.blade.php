@component('mail::message')
# {{ __('mail.candidature.submitted.greeting', ['name' => trim("{$candidature->prenom} {$candidature->nom}")]) }}

{{ __('mail.candidature.submitted.intro') }}

**{{ __('mail.candidature.submitted.numero_dossier') }} :** {{ $candidature->numero_dossier }}
**{{ __('mail.candidature.submitted.specialite') }} :** {{ $candidature->specialite }}

## {{ __('mail.candidature.submitted.fees_title') }}

{{ __('mail.candidature.submitted.fees_body', ['montant' => $fraisFcfa, 'numero' => $candidature->numero_dossier]) }}

{{ __('mail.candidature.submitted.recipisse_note') }}

@component('mail::button', ['url' => $dossierUrl])
{{ __('mail.candidature.submitted.cta_check') }}
@endcomponent

{{ __('mail.candidature.submitted.signature') }}
@endcomponent
