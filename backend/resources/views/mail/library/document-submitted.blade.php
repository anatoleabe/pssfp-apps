@component('mail::message')
# Un document attend votre validation

Un document de la bibliothèque virtuelle a été soumis en revue.

@component('mail::panel')
**Titre :** {{ \App\Support\MailMarkdown::plain($documentTitle) }}<br>
**Type :** {{ \App\Support\MailMarkdown::plain($typeLabel) }}
@endcomponent

Ouvrez la fiche pour la relire, puis publiez-la ou renvoyez-la en brouillon avec un motif.

@component('mail::button', ['url' => $adminUrl])
Ouvrir la fiche
@endcomponent

Bibliothèque virtuelle du PSSFP
@endcomponent
