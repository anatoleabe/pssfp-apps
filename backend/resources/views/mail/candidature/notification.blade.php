@component('mail::message')
# Bonjour {{ trim("{$candidature->prenom} {$candidature->nom}") }},

{{-- Le corps est rédigé par un agent : rendu tel quel, sans interprétation
     Markdown ni HTML. `nl2br(e(...))` échappe d'abord, met en forme ensuite —
     l'ordre inverse laisserait passer une injection. --}}
{!! nl2br(e($corps)) !!}

@component('mail::panel')
**Numéro de dossier :** {{ $candidature->numero_dossier }}
@if($candidature->specialite)
<br>**Spécialité demandée :** {{ $candidature->specialite }}
@endif
@endcomponent

Cordialement,
L'équipe des admissions du PSSFP

Pour toute assistance, écrivez à [{{ $supportEmail }}](mailto:{{ $supportEmail }}) en rappelant votre numéro de dossier.
@endcomponent
