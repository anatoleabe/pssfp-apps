# Reprise d'ACMA : procédure d'exploitation

Lot 15 du module 4. Spec : [`docs/specs/module-4-lot15-reprise-acma.md`](../specs/module-4-lot15-reprise-acma.md) (§10, §17). Décision : [ADR-0014](../adr/0014-reprise-acma-archive-lecture-seule.md).

L'export d'ACMA contient des données personnelles réelles. Rien n'en entre dans le dépôt, dans un ticket, dans une PR ou dans un courriel : ni l'export, ni les CSV, ni les classeurs. La console n'affiche que des comptes ; une erreur n'affiche que l'étape, le SQLSTATE, la table et la contrainte, jamais une valeur.

Trois règles pour les secrets :

- le mot de passe root du MariaDB jetable n'apparaît jamais sur une ligne de commande (ni `-p…`, ni `-e MARIADB_ROOT_PASSWORD=…`) : il vit dans un fichier, lu par `MARIADB_ROOT_PASSWORD_FILE` et par un fichier d'options du client ;
- la phrase de passe de l'archive (12 caractères au moins, tenue par l'USI hors du dépôt) se saisit par `read -rs`, puis passe par la variable `ACMA_PHRASE`, effacée après usage ; jamais en option, jamais dans un fichier versionné ;
- une variable transmise à un conteneur l'est par son nom seul (`-e ACMA_PHRASE`), jamais `-e ACMA_PHRASE=…`.

## 0. Calendrier

| Moment | Où | Étapes |
|---|---|---|
| Mise en ligne de 15a à 15d | serveur | déploiement : migrations (archive, compléments, permissions `archives.*`) |
| Reprise provisoire | poste de l'USI, puis serveur | export du 15/11 : §1 à §6. L'archive n'est **pas gelée** : elle est consultable tout de suite, les arbitrages et compléments commencent |
| Entre les deux | serveur | consultation, arbitrage, compléments (date de naissance, décision transcrite). **Pas de « note retenue »** sur une note en conflit avant le gel (§17 de la spec) |
| J0 | poste de l'USI, puis serveur | export final : §1 à §6 rejoués (un rejeu sur une archive vivante est le cas normal), puis §7 gel et purge |

Un rejeu sur un export plus récent insère les lignes nouvelles, met à jour les lignes modifiées et marque « retirées de la source » les lignes disparues, sans jamais les supprimer. Une ligne retirée n'est plus montrée ni comptée (fiche, notes, paiements, totaux, Historique ACMA de l'auditeur) ; un dossier retiré se retrouve par le filtre « Disparus de la source » de l'écran. Les arbitrages et les compléments restent.

## 1. Poste de l'USI : conteneur jetable, sans réseau

1. Dossier privé, hors du dépôt, et mot de passe root dans un fichier :

   ```bash
   umask 077
   mkdir -p ~/acma/{source,extraction,secrets} && chmod 700 ~/acma ~/acma/*
   openssl rand -hex 16 > ~/acma/secrets/mdp
   chmod 644 ~/acma/secrets/mdp   # lu par l'utilisateur mysql du conteneur ; ~/acma/secrets reste en 700
   printf '[client]\nuser=root\npassword=%s\n' "$(cat ~/acma/secrets/mdp)" > ~/acma/secrets/client.cnf
   ```

   (`printf` est une commande interne du shell : le mot de passe n'apparaît pas dans la liste des processus.)

2. MariaDB jetable **sans aucun réseau** (`--network none`) : ni port publié, ni accès sortant.

   ```bash
   docker run -d --name acma_transit --network none \
     -v ~/acma/secrets:/run/secrets:ro \
     -e MARIADB_ROOT_PASSWORD_FILE=/run/secrets/mdp mariadb:11.8
   until docker exec acma_transit healthcheck.sh --connect --innodb_initialized; do sleep 2; done
   docker exec acma_transit mariadb --defaults-extra-file=/run/secrets/client.cnf -e 'CREATE DATABASE gestionacademic'
   docker exec -i acma_transit mariadb --defaults-extra-file=/run/secrets/client.cnf gestionacademic < ~/acma/source/acma-AAAA-MM-JJ.sql
   ```

   Attendre le contrôle de santé : le serveur d'initialisation annonce aussi « ready for connections » avant que le mot de passe soit posé.

## 2. Extraction chiffrée (poste de l'USI)

Le conteneur n'ayant pas de réseau, l'extraction tourne dans un conteneur PHP qui partage son espace réseau (`--network container:acma_transit` : la seule boucle locale du MariaDB).

1. Image PHP, construite une fois (avec réseau, sans aucune donnée) :

   ```bash
   docker build -t pssfp-acma-php - <<'EOF'
   FROM php:8.3-cli
   RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl && rm -rf /var/lib/apt/lists/*
   EOF
   ```

2. Extraction (dépôt à jour, `composer install` fait dans `backend/`) :

   ```bash
   read -rs ACMA_PHRASE && export ACMA_PHRASE
   export ACMA_DB_PASSWORD="$(cat ~/acma/secrets/mdp)"
   docker run --rm --network container:acma_transit -u "$(id -u):$(id -g)" \
     -v "$PWD/backend":/app -v ~/acma:/acma -w /app \
     -e ACMA_DB_HOST=127.0.0.1 -e ACMA_DB_PORT=3306 -e ACMA_DB_USERNAME=root -e ACMA_DB_PASSWORD -e ACMA_PHRASE \
     pssfp-acma-php php -d memory_limit=512M artisan acma:extraire --sortie=/acma/extraction \
       --export=/acma/source/acma-AAAA-MM-JJ.sql --date-export=AAAA-MM-JJ
   unset ACMA_PHRASE ACMA_DB_PASSWORD
   ```

   Sur un poste **Linux**, on peut aussi partager la socket du MariaDB (`-v ~/acma/socket:/run/mysqld` au `docker run` du §1) et lancer `php artisan acma:extraire` sur l'hôte avec `ACMA_DB_SOCKET=$HOME/acma/socket/mysqld.sock` (chemin de moins de 104 caractères). Sous macOS (Docker Desktop), une socket partagée n'est pas joignable depuis l'hôte (« Connection refused », constaté le 15/11/2026) : utiliser le conteneur PHP.

- Sortie : `acma-transit-AAAAMMJJ-HHMMSS.chiffre` (0600), un zip des CSV et du manifeste, chiffré en AES-256-GCM (clé dérivée par PBKDF2-SHA256, 600 000 itérations). Le dossier en clair et le zip intermédiaire (0600) sont supprimés, même après un échec.
- **Liste blanche** (`ExtractionAcma::COLONNES`, format `pssfp-acma-transit/3` depuis le sous-lot 15i) : 16 tables ; toutes les colonnes de la fiche de l'auditeur (`etudiant`, coordonnées comprises : décision du 16/11/2026, [spec](../specs/module-4-lot15-reinscription-anciens.md) §4), `inscription_academic.parcours`, et dans les autres tables les seules colonnes que la reprise, l'inventaire et le rapport des écarts lisent. Une colonne de la liste absente d'ACMA fait échouer l'extraction. Ne sortent jamais : la table `utilisateur` et les autres tables hors liste (dont `etablissement`, `categorie`, `filiere`, `niveau`, `phasepayement`), les identifiants du personnel d'ACMA (`du.username`, `du.stan`, `notes.IDUtilisateur`, `notes_hist.usercon`, `notes_hist.IDutilisateur`), les copies de l'identité dans les autres tables, le groupware, le journal. Une archive `/2` est refusée au chargement : refaire l'extraction.
- Le manifeste porte, par table, les colonnes, leur type MySQL, le nombre de lignes, l'empreinte SHA-256 du CSV et le nom des colonnes non extraites ; et, pour la source, le nom, l'empreinte et la date de l'export.

## 3. Transfert

```bash
scp ~/acma/extraction/acma-transit-*.chiffre pssfp@<serveur>:/home/pssfp/acma/
ssh pssfp@<serveur> 'chmod 700 /home/pssfp/acma && chmod 600 /home/pssfp/acma/*.chiffre'
```

## 4. Chargement du transit (serveur, utilisateur `pssfp`)

```bash
cd /var/www/pssfp/app/backend
read -rs ACMA_PHRASE && export ACMA_PHRASE
php artisan acma:charger /home/pssfp/acma/acma-transit-….chiffre --auteur=<e-mail admin>
unset ACMA_PHRASE
php artisan acma:inventaire
```

- Le déchiffrement se fait dans `storage/app/private/acma/tmp/` (fichiers en 0600), vidé au démarrage de chaque chargement (reste d'un chargement interrompu) et supprimé à la fin dans tous les cas. Une archive dont l'en-tête annonce plus de 5 000 000 d'itérations est refusée.
- Le manifeste doit porter exactement la liste blanche : une colonne en trop ou en moins, une empreinte ou un nombre de lignes qui ne concorde pas fait refuser le chargement ; le transit précédent reste intact.
- Le schéma `acma_transit` est remplacé en entier (toutes les colonnes en `text`, plus `_ligne`, le rang de la ligne). Le chargement est journalisé (`archives`, `transit_charge`) avec l'empreinte du manifeste, sans aucune donnée.
- `acma:inventaire` reproduit les comptes du §3 de la spec, pour tout export.

## 5. Reprise dans l'archive et contrôles

```bash
php artisan acma:reprendre --auteur=<e-mail admin>               # à blanc : tout est fait puis annulé
php artisan acma:reprendre --auteur=<e-mail admin> --appliquer   # validée si aucun contrôle bloquant n'échoue
php artisan acma:controler                                        # contrôles sur l'archive en place, lecture seule
```

- Étapes, dans l'ordre : `referentiels`, `dossiers`, `rapprochement`, `inscriptions`, `resultats`, `versions_notes`, `baremes`, `paiements`, `soutenances`, `documents`. Une étape seule : `--etapes=resultats` (les précédentes doivent être en place). Une décimale dans un montant bloque l'étape et annule tout.
- **Exclusion mutuelle** : `acma:charger`, `acma:reprendre`, `acma:geler` et `acma:purger-transit` prennent le même verrou consultatif PostgreSQL. Une commande lancée pendant une autre attend qu'elle se termine, puis revérifie l'état (gel, transit).
- Rejouée sur le même export, la reprise n'écrit rien (C09). Sur un export plus récent : voir §0. C09 et C10 comptent les lignes réellement écrites par la reprise (statistiques PostgreSQL de la transaction).
- Sorties dans le stockage privé (`storage/app/private/acma/`) : `controles-AAAAMMJJ.md` (comptes seulement, à joindre à la décision de gel) et `rapprochement-AAAAMMJJ.xlsx` (**nominatif** : cas à arbitrer, absents, exclusions, fusions, collisions, actifs 2026-2027 hors plateforme). Seul le classeur le plus récent de chaque type est gardé.
- Durée mesurée sur l'export du 15/11/2026, sur un poste de développement : moins d'une minute pour la première application, un quart de minute pour un rejeu.

## 6. Rapport des écarts 2026-2027

```bash
php artisan acma:ecarts-2026 --auteur=<e-mail admin>   # stockage privé : acma/ecarts-2026-2027-AAAAMMJJ.xlsx
```

Lecture seule sur le transit et la plateforme. Le classeur est **nominatif** : il se télécharge depuis l'écran « Archives ACMA » (permission `scolarite.export`, téléchargement journalisé), jamais en pièce jointe d'un courriel. Seul le plus récent est gardé. La console n'affiche que la synthèse par promotion et le nombre de lignes de chaque feuille.

## 7. Gel, puis purge du transit (J0 seulement)

```bash
php artisan acma:geler --auteur=<e-mail admin>            # demande confirmation ; irréversible par l'application
php artisan acma:purger-transit --auteur=<e-mail admin>   # demande confirmation ; refusé avant le gel
```

- Le gel exige la dernière reprise appliquée sans contrôle bloquant en échec, et rejoue les contrôles : il se fait donc avant la purge, transit en place.
- Après le gel, plus aucune reprise ; seul le lien d'un dossier vers un auditeur reste modifiable, et seulement par un arbitrage enregistré (15d). Défaire un gel demande une migration motivée.
- Avant le gel, la reprise provisoire laisse le transit en base jusqu'au J0 (la purge est refusée tant que l'archive n'est pas gelée) : il entre dans les sauvegardes quotidiennes. Garder cette fenêtre courte.

## 8. Conservation et nettoyage du poste

- L'archive chiffrée et son manifeste sont conservés hors ligne par l'USI (Q14). Supprimer de l'hôte les copies en clair, le conteneur jetable, son volume et les secrets :

  ```bash
  docker rm -f -v acma_transit && rm -rf ~/acma/secrets
  ```

## 9. Rejeu de la reprise provisoire au format /3 (sous-lot 15i)

Après le déploiement de 15i (migration `2026_12_10_100000_add_coordonnees_to_acma_archive`), l'archive provisoire n'a pas encore les coordonnées. Tant qu'elle n'est pas gelée :

1. Poste de l'USI : §1 et §2 sur l'export du 15/11 (conservé chiffré), avec le code de 15i : l'archive produite est au format `/3`.
2. Serveur : §3, §4 (`acma:charger`, puis `acma:inventaire`, qui ajoute la section « Coordonnées des fiches » : des comptes seulement), §5 à blanc puis `--appliquer`, et `acma:controler`.
3. Attendu : tous les dossiers et toutes les inscriptions « modifiés » (l'empreinte de la source change) ; **aucun** lien remis à arbitrer (`liens_remis_a_arbitrer` = 0) ; arbitrages et compléments conservés ; contrôles bloquants verts.
4. Les coordonnées se lisent dans la section « Coordonnées (ACMA) » de la fiche d'archive (`archives.consulter`). Elles ne vont ni au journal, ni aux classeurs, et ne servent à aucun envoi.
