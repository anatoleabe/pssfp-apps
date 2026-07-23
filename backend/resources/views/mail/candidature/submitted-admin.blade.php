@component('mail::message')
# Nouvelle candidature déposée

Un nouveau dossier a été soumis sur la plateforme de candidature du PSSFP.

@component('mail::panel')
**Dossier :** {{ $candidature->numero_dossier }}<br>
**Candidat(e) :** {{ trim("{$candidature->prenom} {$candidature->nom}") }}<br>
**Adresse e-mail :** {{ $candidature->email }}<br>
**Téléphone :** {{ $candidature->indicatif1 }} {{ $candidature->telephone1 }}<br>
**Spécialité demandée :** {{ $candidature->specialite }}<br>
**Modalité :** {{ $candidature->type_etude === 'distanciel' ? 'Distanciel' : 'Présentiel' }}<br>
**Situation professionnelle :** {{ $statutLabel }}<br>
@if($candidature->employeur)
**Employeur / organisme :** {{ $candidature->employeur }}<br>
@endif
@if($candidature->fonction_actuelle)
**Fonction :** {{ $candidature->fonction_actuelle }}<br>
@endif
**Source de connaissance du PSSFP :** {{ $candidature->moyen_connaissance }}@if($candidature->moyen_connaissance_detail) — {{ $candidature->moyen_connaissance_detail }}@endif<br>
**Date et heure de dépôt :** {{ optional($candidature->submitted_at)->timezone(config('app.timezone'))->format('d/m/Y à H:i') }}
@endcomponent

La confirmation destinée au candidat lui a été envoyée séparément à **{{ $candidature->email }}**. Cet e-mail interne n’est pas une copie du message candidat.

@component('mail::button', ['url' => $adminUrl])
Ouvrir la gestion des candidatures
@endcomponent

Vous pouvez répondre directement à cet e-mail pour écrire au candidat.

Plateforme de candidature du PSSFP
@endcomponent
