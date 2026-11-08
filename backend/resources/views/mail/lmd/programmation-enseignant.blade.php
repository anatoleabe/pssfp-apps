@component('mail::message')
# Votre programmation des enseignements

Bonjour {{ \App\Support\MailMarkdown::plain($enseignant) }},

La Scolarité du PSSFP vous confie les enseignements suivants pour la période « {{ \App\Support\MailMarkdown::plain($periode) }} », {{ $dates }}.

@foreach ($lignes as $l)
@component('mail::panel')
**{{ \App\Support\MailMarkdown::plain($l['cours']) }}**<br>
{{ $l['role'] }} · {{ $l['heures'] }}<br>
Intervention {{ $l['fenetre'] }} · {{ $l['modalite'] }}
@endcomponent
@endforeach

Merci d'accepter ou de décliner chaque programmation dans le back-office, rubrique « Mes programmations ». Pour décliner, indiquez le motif. Déclarez aussi vos indisponibilités de la période : aucune séance ne vous sera programmée pendant celles-ci.

@component('mail::button', ['url' => $adminUrl])
Répondre à ma programmation
@endcomponent

Scolarité du PSSFP
@endcomponent
