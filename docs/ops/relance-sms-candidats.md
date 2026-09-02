# Relance SMS des candidats — mode d'emploi

> Campagne p14-2026, clôture le **18/09/2026**.
> Commande : `php artisan candidatures:relancer-brouillons`

## À quoi ça sert

Sur 108 dossiers ouverts, 44 seulement ont été soumis. Les 64 restants ne sont
pas des abandons : **60 sont récupérables**.

| Population | Nombre | Message envoyé |
|---|---|---|
| `ready` — dossier complet, jamais soumis | 26 | « votre dossier est complet mais pas encore soumis » |
| `photo_only` — seule la photo manque | 34 | « le problème de taille est résolu, réessayez » |
| Autres champs manquants | 4 | non relancés (le SMS ne suffirait pas) |

Ces candidats ont échoué il y a 16 jours en médiane. Sans relance, ils ne
reviendront pas d'eux-mêmes.

## Ce qu'il reste à faire avant le premier envoi

**Une seule information manque : l'URL de la passerelle SMS.** La documentation
Postman fournie décrit l'API mais son `BASE_URL` est un placeholder
(`http://localhost:8000/api`), et aucune URL réelle n'est configurée nulle part
sur le serveur.

Renseigner dans `/var/www/pssfp/app/backend/.env` :

```
SMS_PROVIDER=gateway_api
SMS_GATEWAY_BASE_URL=https://<hôte-de-la-passerelle>/api
SMS_GATEWAY_TOKEN=<le jeton Sanctum>
SMS_GATEWAY_FROM_TYPE=sender_id
SMS_GATEWAY_SENDER_ID=<id retourné par GET /api/sender-id>
```

Puis `php artisan config:clear && php artisan optimize`.

Pour connaître le `SENDER_ID` disponible :

```bash
curl -s -H "Authorization: Bearer <jeton>" -H "Accept: application/json" \
     https://<hôte>/api/sender-id
```

## Séquence d'envoi recommandée

Tout se joue sous l'utilisateur `pssfp` :

```bash
ssh open-claw-codabe
sudo -u pssfp bash -l
cd /var/www/pssfp/app/backend
```

**1. Simulation — ne envoie rien, montre qui serait relancé et avec quel texte.**

```bash
php artisan candidatures:relancer-brouillons
```

**2. Envoi de test sur un seul dossier, pour vérifier la chaîne de bout en bout.**

```bash
php artisan candidatures:relancer-brouillons --envoyer --limite=1
```

Vérifier que le SMS arrive réellement, que l'émetteur affiché est correct et que
le texte n'est pas tronqué. Ce dossier ne sera pas relancé une seconde fois.

**3. Envoi complet.**

```bash
php artisan candidatures:relancer-brouillons --envoyer
```

Ou par population, pour étaler :

```bash
php artisan candidatures:relancer-brouillons --envoyer --cause=ready
php artisan candidatures:relancer-brouillons --envoyer --cause=photo_only
```

## Garde-fous

- **Simulation par défaut.** Sans `--envoyer`, rien ne part.
- **Confirmation interactive** demandée en plus de `--envoyer`.
- **Refus si `SMS_PROVIDER=fake`.** Ce provider réussit sans rien envoyer : les
  candidats seraient marqués comme relancés et l'anti-doublon interdirait ensuite
  le vrai envoi. La commande s'arrête plutôt que de laisser passer ça.
- **Anti-doublon strict.** Un candidat déjà relancé pour une cause ne le sera
  jamais deux fois. Un échec, en revanche, autorise une nouvelle tentative.
- **Numéros masqués** dans toute sortie et tout log (`+237***4567`).
- **Aucun dossier soumis ou retiré** n'est relancé.
- **Échec isolé** : un envoi qui échoue est tracé et n'interrompt pas les autres.

## Vérifier après coup

```bash
# Répartition des relances envoyées
php artisan tinker --execute="
foreach (App\Models\CandidatureRelance::selectRaw('cause, statut, count(*) as n')
    ->groupBy('cause','statut')->get() as \$r) {
  echo \$r->cause.' / '.\$r->statut.' : '.\$r->n.PHP_EOL;
}"
```

Le tableau de bord admin (`/admin`) affiche en parallèle les compteurs
« Prêts à soumettre », « Bloqués par la photo » et « Autres champs manquants ».
Ces chiffres doivent baisser dans les jours qui suivent la relance.

## Rédaction des messages

Les textes vivent dans `backend/config/relance_sms.php`, pas dans le code.
Contraintes à respecter si on les modifie :

- **Moins de 160 caractères**, sinon l'opérateur facture deux SMS.
- **Alphabet GSM-7 uniquement.** Les accents `é è à ù ç` passent ; l'apostrophe
  typographique `’`, les guillemets `« »` et les tirets longs font basculer le
  message en UCS-2, qui plafonne à 70 caractères. Utiliser l'apostrophe droite.
- **Nommer le PSSFP en tête** : un SMS sans émetteur identifiable est pris pour
  du spam.
- Une seule action, une seule URL.

Un test (`RelanceBrouillonsTest`) vérifie automatiquement la limite de 160
caractères sur les messages réellement envoyés.
