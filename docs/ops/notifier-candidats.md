# Notifier un groupe de candidats — mode d'emploi

> Admin → **Module 5 — Admissions → Candidatures**
> Bouton **« Notifier les candidats sélectionnés »**

## À qui c'est réservé

`admin` et `super_admin` uniquement. Ni le comité d'admission, ni la scolarité,
ni les éditeurs ne voient ce bouton : écrire à des dizaines de candidats engage
l'institution.

## Comment s'en servir

1. **Filtrer la liste** avec les filtres existants — campagne, statut, région,
   frais payés, dossier papier reçu, ou « Brouillons à relancer » (prêts à
   soumettre / bloqués par la photo / autres champs manquants).
2. **Cocher** les dossiers voulus, ou tout sélectionner.
3. Ouvrir **« Notifier les candidats sélectionnés »**.
4. Choisir le **canal** : SMS seul, e-mail seul, ou les deux.
5. Charger un **modèle** puis le modifier, ou écrire un message libre.
6. **Lire l'aperçu** — il donne le nombre de destinataires réellement
   joignables, le coût en SMS et le message tel que le premier candidat le
   recevra.
7. Envoyer.

## Variables disponibles

`{prenom}` `{nom}` `{numero_dossier}` `{specialite}` `{date_cloture}` `{url}`

Elles sont remplacées pour chaque destinataire. Un message reste donc
personnalisé même envoyé à cent personnes.

## Ce que l'aperçu vous dit, et pourquoi le lire

- **Destinataires joignables** — un candidat sans numéro exploitable ou sans
  e-mail est compté à part, il ne recevra rien.
- **Coût en SMS.** La longueur est mesurée sur le message **une fois les
  variables remplacées** : un gabarit de 140 caractères passe à 165 avec un nom
  long, et l'opérateur facture alors deux SMS par destinataire.
- **Numéros hors Cameroun.** La passerelle Echo SMS ne dessert que le +237.
  Lors de la relance du 3 septembre, 7 envois ont échoué pour cette seule
  raison — tous vers le Tchad et la RDC. L'aperçu les signale désormais avant
  l'envoi : pour ces candidats, préférer l'e-mail.

## Modèles proposés

Ils vivent dans `backend/config/notification_candidats.php` et sont modifiables
sans toucher au code :

| Clé | Usage |
|---|---|
| `dossier_incomplet` | Rappel général aux dossiers non finalisés |
| `rappel_cloture` | Approche de la date de clôture |
| `frais_non_payes` | Frais de dossier en attente |
| `depot_papier_attendu` | Soumis en ligne, papier non déposé |
| `convocation` | Information générale |

Contraintes de rédaction pour les modèles SMS : moins de 160 caractères une
fois les variables remplacées, et alphabet GSM-7 — les accents `é è à ù ç`
passent, mais l'apostrophe typographique et les guillemets `« »` font basculer
le message en UCS-2, qui plafonne à 70 caractères. Utiliser l'apostrophe droite.

## Traçabilité

Chaque envoi enregistre le canal, le statut, **le texte exact**, et **qui l'a
envoyé**, dans la table `candidature_relances` et dans l'`activity_log`.

```bash
# Qui a notifié qui, et quand
php artisan tinker --execute="
foreach (App\Models\CandidatureRelance::where('cause','manuelle')
    ->with(['candidature','auteur'])->latest('sent_at')->limit(20)->get() as \$r) {
  echo \$r->sent_at.' | '.(\$r->auteur->email ?? '?').' | '
    .\$r->candidature->numero_dossier.' | '.\$r->canal.' | '.\$r->statut.PHP_EOL;
}"
```

## Différence avec la relance automatique

| | Relance automatique | Notification manuelle |
|---|---|---|
| Déclenchement | commande `candidatures:relancer-brouillons` | bouton dans l'admin |
| Critères | figés (dossier complet / photo manquante) | tous les filtres de la liste |
| Message | figé en configuration | modèle modifiable ou libre |
| Anti-doublon | **oui**, strict par cause | **non** — un agent peut réécrire |
| Texte conservé | non (figé ailleurs) | oui, pour audit |

L'absence d'anti-doublon est volontaire : réécrire aux mêmes personnes est un
geste légitime. C'est la traçabilité qui prend le relais.

## Limite connue

L'envoi est **synchrone** : la page reste en attente le temps de l'envoi. À
l'échelle d'une campagne (quelques centaines de dossiers) c'est acceptable et
cela permet de rendre un compte rendu immédiat. Au-delà, il faudra passer par la
file Redis.
