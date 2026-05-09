Nouveau message de contact depuis pssfp.net
=============================================

Nom         : {{ $payload['nom'] }}
Email       : {{ $payload['email'] }}
@if (!empty($payload['phone']))
Téléphone   : {{ $payload['phone'] }}
@endif
@if (!empty($payload['organisation']))
Organisation: {{ $payload['organisation'] }}
@endif
@if (!empty($payload['subject']))
Sujet       : {{ $payload['subject'] }}
@endif
IP émettrice: {{ $payload['ip'] ?? 'inconnue' }}

Message
-------

{{ $payload['message'] }}

—
Cet email a été généré automatiquement depuis le formulaire de contact pssfp.net.
Pour répondre, utilisez l'adresse email ci-dessus (Reply-To).
