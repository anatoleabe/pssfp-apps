@component('mail::message')
# {{ __('mail.candidature.submitted.greeting', ['name' => trim("{$candidature->prenom} {$candidature->nom}")]) }}

{{ __('mail.candidature.submitted.intro') }}

@component('mail::panel')
**{{ __('mail.candidature.submitted.numero_dossier') }} :** {{ $candidature->numero_dossier }}<br>
**{{ __('mail.candidature.submitted.specialite') }} :** {{ $candidature->specialite }}
@endcomponent

## Prochaines étapes

1. Vérifiez que toutes les pièces déposées sont complètes et parfaitement lisibles.
2. Réglez les frais de dossier de **{{ $fraisFcfa }} FCFA** en agence CREMINCAM en indiquant le numéro **{{ $candidature->numero_dossier }}**.
3. Conservez le reçu de paiement et consultez régulièrement votre espace candidat.
4. Surveillez cette adresse e-mail : les communications officielles du PSSFP y seront envoyées.

{{ __('mail.candidature.submitted.recipisse_note') }}

@component('mail::button', ['url' => $dossierUrl])
{{ __('mail.candidature.submitted.cta_check') }}
@endcomponent

{{ __('mail.candidature.submitted.signature') }}

Pour toute assistance, écrivez à [{{ $supportEmail }}](mailto:{{ $supportEmail }}) en rappelant votre numéro de dossier.
@endcomponent
