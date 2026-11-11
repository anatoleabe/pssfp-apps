@component('mail::message')
# Sécurité de votre compte

Bonjour {{ \App\Support\MailMarkdown::plain($nom) }},

**{{ \App\Support\MailMarkdown::plain($evenement) }}** sur votre compte du back-office PSSFP, le {{ $date }}@if ($adresse) depuis l'adresse {{ $adresse }}@endif.

Si c'est bien vous, il n'y a rien à faire.

**Si ce n'est pas vous**, quelqu'un connaît votre mot de passe : prévenez sans attendre l'USI (contact@pssfp.org) pour que votre double authentification soit réinitialisée et votre mot de passe changé.

@component('mail::button', ['url' => $lien])
Voir la sécurité de mon compte
@endcomponent

USI du PSSFP
@endcomponent
