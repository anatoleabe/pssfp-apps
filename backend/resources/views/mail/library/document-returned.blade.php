@component('mail::message')
# Votre document est renvoyé en brouillon

La bibliothèque a relu votre document et vous demande de le reprendre avant publication.

@component('mail::panel')
**Titre :** {{ \App\Support\MailMarkdown::plain($documentTitle) }}<br>
**Type :** {{ \App\Support\MailMarkdown::plain($typeLabel) }}
@endcomponent

**Motif :**

{{ \App\Support\MailMarkdown::plain($reason) }}

Modifiez la fiche, puis soumettez-la de nouveau en revue.

@component('mail::button', ['url' => $adminUrl])
Ouvrir la fiche
@endcomponent

Bibliothèque virtuelle du PSSFP
@endcomponent
