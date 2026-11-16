# Reprise d'ACMA : procédure d'exploitation

Lot 15 du module 4. Spec : [`docs/specs/module-4-lot15-reprise-acma.md`](../specs/module-4-lot15-reprise-acma.md) (§10). Décision : [ADR-0014](../adr/0014-reprise-acma-archive-lecture-seule.md).

L'export d'ACMA contient des données personnelles réelles. Rien n'en entre dans le dépôt, dans un ticket, dans une PR ou dans un courriel : ni l'export, ni les CSV, ni les classeurs. La console n'affiche que des comptes.

## 1. Poste de l'USI : conteneur jetable

1. Dossier privé, hors du dépôt :

   ```bash
   mkdir -p ~/acma/{source,extraction} && chmod 700 ~/acma ~/acma/*
   ```

2. MariaDB jetable, sans réseau sortant, à l'écoute de la seule boucle locale :

   ```bash
   docker run -d --name acma_transit -p 127.0.0.1:33061:3306 \
     -e MARIADB_ROOT_PASSWORD="$(openssl rand -hex 16 | tee ~/acma/.mdp)" mariadb:11
   docker exec -i acma_transit mariadb -uroot -p"$(cat ~/acma/.mdp)" -e 'CREATE DATABASE gestionacademic'
   docker exec -i acma_transit mariadb -uroot -p"$(cat ~/acma/.mdp)" gestionacademic < ~/acma/source/acma-AAAA-MM-JJ.sql
   ```

3. Connexion `acma_source`, définie sur ce poste seulement (variables du processus, jamais dans un fichier versionné) :

   ```bash
   export ACMA_DB_HOST=127.0.0.1 ACMA_DB_PORT=33061 ACMA_DB_USERNAME=root ACMA_DB_PASSWORD="$(cat ~/acma/.mdp)"
   ```

## 2. Extraction chiffrée

La phrase de passe (12 caractères au moins) est tenue par l'USI hors du dépôt. Elle se donne par la variable `ACMA_PHRASE` ou se saisit à l'invite, jamais en option de la ligne de commande.

```bash
php artisan acma:extraire --sortie=$HOME/acma/extraction \
  --export=$HOME/acma/source/acma-AAAA-MM-JJ.sql --date-export=AAAA-MM-JJ
```

- Sortie : `acma-transit-AAAAMMJJ-HHMMSS.chiffre`, un zip des CSV et du manifeste, chiffré en AES-256-GCM (clé dérivée par PBKDF2-SHA256, 600 000 itérations). Le dossier en clair est supprimé, même après un échec.
- Ne sortent jamais d'ACMA : la table `utilisateur`, les colonnes FTP et la clé de la plateforme d'`etablissement`, les coordonnées et la région des auditeurs (`etudiant`), le groupware (`gpw*`), le journal (`tachesutili`) et les tables hors du §3.1. La liste est figée dans `ExtractionAcma`.
- Le manifeste porte, par table, les colonnes, leur type MySQL, le nombre de lignes et l'empreinte SHA-256 du CSV ; et, pour la source, le nom, l'empreinte et la date de l'export.

## 3. Transfert

```bash
scp ~/acma/extraction/acma-transit-*.chiffre pssfp@<serveur>:/home/pssfp/acma/
ssh pssfp@<serveur> 'chmod 700 /home/pssfp/acma && chmod 600 /home/pssfp/acma/*.chiffre'
```

## 4. Chargement du transit (serveur, utilisateur `pssfp`)

```bash
cd /var/www/pssfp/app/backend
ACMA_PHRASE='…' php artisan acma:charger /home/pssfp/acma/acma-transit-….chiffre --auteur=<e-mail admin>
php artisan acma:inventaire
```

- Le déchiffrement se fait dans `storage/app/private/acma/tmp/`, supprimé dans tous les cas.
- Une empreinte, un nombre de lignes ou une colonne exclue qui ne concorde pas avec le manifeste : chargement refusé, le transit précédent reste intact.
- Le schéma `acma_transit` est remplacé en entier (toutes les colonnes en `text`, plus `_ligne`, le rang de la ligne). Le chargement est journalisé (`archives`, `transit_charge`) avec l'empreinte du manifeste, sans aucune donnée.
- `acma:inventaire` reproduit les comptes du §3 de la spec, pour tout export.

Préférer `read -s ACMA_PHRASE && export ACMA_PHRASE` à une phrase écrite sur la ligne de commande, puis `unset ACMA_PHRASE`.

## 5. Purge et conservation

- Le transit entre dans les sauvegardes quotidiennes tant qu'il existe : la fenêtre entre chargement et purge reste courte.
- Après le gel de l'archive : `acma:purger-transit` (sous-lot 15c).
- L'archive chiffrée et son manifeste sont conservés hors ligne par l'USI (Q14). Supprimer de l'hôte les copies en clair, le conteneur jetable et son volume :

  ```bash
  docker rm -f -v acma_transit && rm -f ~/acma/.mdp
  ```
