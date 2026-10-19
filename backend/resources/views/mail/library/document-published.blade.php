@component('mail::message')
# {{ count($documents) === 1 ? 'Votre document est publié' : 'Vos documents sont publiés' }}

{{ count($documents) === 1 ? 'Le document que vous avez déposé a été validé et publié' : 'Les documents que vous avez déposés ont été validés et publiés' }} dans la bibliothèque virtuelle.

@foreach($documents as $document)
@component('mail::panel')
**Titre :** {{ \App\Support\MailMarkdown::plain($document['title']) }}<br>
**Type :** {{ \App\Support\MailMarkdown::plain($document['type']) }}<br>
[Ouvrir la fiche]({{ $document['url'] }})
@endcomponent
@endforeach

Merci pour votre contribution.

Bibliothèque virtuelle du PSSFP
@endcomponent
