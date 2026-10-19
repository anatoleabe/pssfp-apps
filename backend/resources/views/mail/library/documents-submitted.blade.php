@component('mail::message')
# {{ count($documents) }} documents attendent votre validation

Plusieurs documents de la bibliothèque virtuelle ont été soumis en revue.

@foreach($listed as $document)
@component('mail::panel')
**Titre :** {{ \App\Support\MailMarkdown::plain($document['title']) }}<br>
**Type :** {{ \App\Support\MailMarkdown::plain($document['type']) }}<br>
[Ouvrir la fiche]({{ $document['url'] }})
@endcomponent
@endforeach

@if($remaining > 0)
… et {{ $remaining }} autre(s) document(s).
@endif

Relisez-les, puis publiez-les ou renvoyez-les en brouillon avec un motif.

@component('mail::button', ['url' => $queueUrl])
Ouvrir la file « À valider »
@endcomponent

Bibliothèque virtuelle du PSSFP
@endcomponent
