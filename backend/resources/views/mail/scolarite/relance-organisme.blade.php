@component('mail::message')
# Prise en charge de vos agents : règlement attendu

Madame, Monsieur,

Par votre lettre {{ \App\Support\MailMarkdown::plain($lettre) }}, {{ \App\Support\MailMarkdown::plain($organisme) }} s'est engagé à prendre en charge, pour l'année académique {{ $annee }}, {{ mb_strtolower($portee) }} des auditeurs ci-dessous. Le règlement était attendu au plus tard le {{ $echeance }}.

À ce jour, le PSSFP n'a pas reçu les montants suivants :

@component('mail::table')
| Matricule | Auditeur | Reste à régler |
|:--|:--|--:|
@foreach ($lignes as $l)
| {{ $l['matricule'] }} | {{ \App\Support\MailMarkdown::plain($l['nom']) }} | {{ $l['reste'] }} |
@endforeach
| | **Total** | **{{ $total }}** |
@endcomponent

Merci de bien vouloir procéder au règlement en rappelant la référence de votre lettre, ou de nous indiquer la date prévue en répondant à ce message.

Scolarité du PSSFP
@endcomponent
