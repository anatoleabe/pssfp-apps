<x-mail::message>
# Test de configuration

Cet e-mail confirme que la configuration d'envoi du site PSSFP fonctionne.

Il a été déclenché manuellement depuis l'administration à des fins de
vérification. **Aucune action n'est attendue de votre part.**

Envoyé le {{ now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH[h]mm') }}.

<x-mail::subcopy>
Si vous recevez ce message sans l'avoir demandé, vous pouvez l'ignorer.
</x-mail::subcopy>
</x-mail::message>
