# Journaux d'activité — convention

Le Journal d'activité (admin › Administration) répond à « qui a fait quoi, quand, sur quel élément ». Pour qu'il reste lisible, chaque écriture suit ces règles. Source unique des noms : `App\Support\Journaux`.

## Un journal par domaine

| Journal | Domaine du filtre | Contenu |
|---|---|---|
| `candidatures`, `auth.candidat`, `envois` | Admissions | Dossiers, connexions des candidats, SMS et e-mails |
| `scolarite` | Scolarité | Auditeurs, versements, reçus, barèmes |
| `lmd` | Parcours LMD | Maquettes, notes, délibérations, requêtes, calendrier |
| `paie` | Enseignants et paie | Pointages, dossiers de paie, barème, états de paie, primes |
| `bibliotheque` | Bibliothèque | Documents, thésaurus, anciennes adresses |
| `comptes`, `roles`, `connexions` | Comptes, rôles et connexions | Comptes du personnel, rôles, connexions à l'admin |
| `admin`, `parametres` | Administration | Modifications faites dans l'admin, paramètres |

Plus aucune écriture dans `default`.

## Écrire une entrée

- **Modèle métier** : `LogsActivity` avec `LogOptions::defaults()->useLogName(Journaux::…)->logOnly([...])->logOnlyDirty()`. On liste les champs utiles ; jamais de secret (mot de passe, code d'accès, NIU ou compte en clair).
- **Action de service** (décision, étape d'un circuit, import) :

  ```php
  activity(Journaux::PAIE)
      ->causedBy($auteur)
      ->performedOn($etat)
      ->event('etat_paie_verifie')            // snake_case, en français
      ->withProperties(['avant' => $avant, 'apres' => $apres, 'motif' => $motif])
      ->log('État de paie vérifié');          // phrase lisible par un agent
  ```

- **Événement** : un verbe au participe, en snake_case (`recu_emis`, `deliberation_arretee`). Il sert au filtre « Type d'action ».
- **Propriétés** : l'avant et l'après de ce qui a changé, le motif quand il y en a un. Pas de copie d'objet entier.

## Historique

On ne réécrit jamais un journal d'audit. Les entrées écrites avant le 10/11/2026 gardent leur nom d'origine (`documents`, `default`, la paie sous `lmd`). Le filtre « Domaine » les rattache à leur domaine (`Journaux::DOMAINES`).

## Ajouter un domaine

1. Ajouter la constante, son libellé et son domaine dans `App\Support\Journaux`.
2. Déclarer le journal dans les modèles et les services du domaine.
3. Ajouter un cas au test `JournalActiviteTest` (« journalise chaque domaine dans son journal »).
