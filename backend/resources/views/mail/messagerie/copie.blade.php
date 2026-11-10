@component('mail::message')
# Un message vous attend

Vous avez reçu un message dans la messagerie de la plateforme PSSFP :

@component('mail::panel')
**{{ \App\Support\MailMarkdown::plain($objet) }}**
@endcomponent

Pour le lire et y répondre, connectez-vous au back-office.

@component('mail::button', ['url' => $lien])
Lire le message
@endcomponent

Ce courriel ne reprend pas le contenu du message, qui reste dans la plateforme.
@endcomponent
