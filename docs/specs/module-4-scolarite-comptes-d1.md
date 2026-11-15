# Module 4 — Scolarité, sous-lot D1 : socle du compte de l'auditeur

**Date** : 2026-11-15
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026)
**Décision d'architecture** : [ADR-0013](../adr/0013-compte-de-l-auditeur.md) (précise l'ADR-0010, point 3)
**Proposition validée** : [lot D, comptes auditeur](module-4-scolarite-comptes-auditeur-proposition.md) (scénarios S1 à S5 ; décisions au §8)
**Specs de référence** : [lot 1](module-4-scolarite-lot1-2026-11.md) (§3, §4, §5, §9, §10) · [lot 4, reçus](module-4-scolarite-lot4-recus.md) · [lot A1, accès anticipé](module-4-scolarite-acces-anticipe.md) (§3, §4)

## 1. Objectif

D1 pose le socle du compte de l'auditeur, sans nouvel écran :

- les versements se rattachent à l'**auditeur**, et chaque part d'un versement qui règle une inscription est une **imputation** enregistrée ;
- ce qui dépasse le dû devient une **avance**, gardée au compte, qui s'impute d'elle-même quand un reste s'ouvre ;
- les versements existants sont **repris** : aucune situation affichée ne change pour un auditeur sans trop-perçu, et l'ancien trop-perçu devient l'avance.

Ce qui change, pour qui :

| Qui | Changement |
|---|---|
| Agents de scolarité | Le formulaire de versement annonce l'avance au lieu du trop-perçu. La fiche affiche « Avance au compte ». L'export Excel gagne une colonne « Avance ». |
| Service financier | L'objet des nouveaux reçus est lu dans les imputations. « Total versé pour l'année » ne compte plus l'avance. |
| Auditeurs | « Versé » est désormais ce qui est imputé sur l'année ; un excédent s'affiche en avance. L'accès FOAD ne change pas. |

D1 se déploie seul. Il ne crée aucune deuxième inscription : les arriérés et l'imputation d'une avance sur l'année suivante ne deviennent réels qu'avec D3. D1 les calcule pourtant déjà correctement, pour que D2 et D3 n'aient pas à toucher aux règles.

## 2. Feuille de route du lot D

| Sous-lot | Contenu | Prérequis |
|---|---|---|
| **D1 (cette spec)** | Socle : `auditeur_id` sur les versements, table `imputations_versements` et ses garde-fous en base, règles R1 à R5 et R7 à R9, verrou de l'auditeur, situation et scopes sur les imputations, objet des reçus, reprise vérifiée, contrôle `CPT_COHERENCE` | ADR-0013 ; le correctif « avance » de la décision 13, de préférence déjà en production |
| D2, compte visible | Onglet « Compte » de la fiche (relevé chronologique), colonnes et filtres « Arriérés » et « Avance » de la liste, export et listes PDF complets, variables de relance `{arrieres}`, `{reste_parcours}`, `{avance}`, rubrique « Mon compte » et relevé de compte PDF dans l'espace, versement pour un auditeur sans inscription (décision 12), arriérés pris en compte par `DocumentsOfficiels::enRegle` (décision 4) | D1 |
| D3, ouverture de l'année suivante | Réinscription en groupe par le responsable après les résultats, à la date qu'il fixe (décision 11), redoublant au barème complet (décision 5), imputation de l'avance et SMS d'accès, refus avant la date d'ouverture de l'année | D1, D2 ; la demande d'annulation sur plusieurs années de D4 |
| D4, affectation et réimputation | Affectation d'un versement à une année, accordée par le responsable (décision 2), réimputation manuelle (R6), demande d'autorisation pour annuler un versement imputé sur plusieurs années (décision 8), décision sur l'avance réservée d'un employeur (décision 7), contrôles `CPT_REIMPUTATION` et `CPT_AVANCE_DORMANTE`, branchement du lot A2 | D2 |
| D5, remboursements et autres frais | Remboursement d'une avance, décision du chef de la structure (décision 6) ; pénalité de l'autorisé à soutenir, paramétrable (décision 5) ; frais de rattrapage | D4 |

**Contrainte d'ordre.** En D1, un versement imputé sur plusieurs années ne peut pas être annulé (§6.5). Ce cas n'existe qu'à partir de D3. La demande d'autorisation de D4 qui le traite doit donc être livrée **avant D3 ou avec lui**.

## 3. Périmètre

### Dans D1

1. **Schéma** : `versements.auditeur_id`, table `imputations_versements`, clés étrangères composites, `CHECK`, index partiels, déclencheurs (immuabilité, cohérence), élargissement de `recus_versements.objet`.
2. **Services** : un planificateur pur, l'écrivain unique `ImputationsCompte` et l'objet de lecture `CompteAuditeur`.
3. **Écrivains existants** : tous ceux qui écrivent de l'argent passent par le verrou de l'auditeur et par `ImputationsCompte`.
4. **Calcul** : `SituationFinanciere` alimentée par les imputations, ainsi que les scopes et les préchargements.
5. **Reçus** : objet, total versé et reste des nouveaux reçus.
6. **Affichage minimal de l'avance** : fiche, formulaire de versement, espace et API, relevé PDF, export Excel, pied de la liste PDF « Situation financière ».
7. **Reprise** des versements existants, vérifiée dans la migration, et commande de vérification en lecture seule.
8. **Contrôle interne** `CPT_COHERENCE`.

### Hors périmètre

- Tout ce que D2 à D5 listent au §2 : relevé de compte, versement sans inscription, arriérés dans les documents LMD, réinscription, affectation, réimputation manuelle, remboursement, frais hors barème.
- Reprise des dettes d'ACMA (décision 10, au cas par cas, lot 15).
- Reçu par objet de frais (D7), clôture comptable, rapprochement bancaire des versements de scolarité, paiement en ligne (module 9).
- Nouveau droit, SMS ou écran : il n'y en a aucun en D1.

## 4. Définitions

Pour un auditeur A :

| Terme | Définition |
|---|---|
| Inscriptions de A | Ses inscriptions annuelles, rangées par `annee_academique` croissante. Il n'y en a qu'une par année (index unique existant). |
| Inscription courante | Celle de l'année la plus récente (`Auditeur::inscriptionCourante`, inchangé). |
| Dû d'une inscription, `du(i)` | `montant_inscription + montant_tranche1 + montant_tranche2 − remise_montant`. Il n'est jamais négatif (`CHECK` existant). |
| Imputation | Ligne de `imputations_versements` : telle part de tel versement règle telle inscription. Elle est **active** tant que `annule_at` est nul. |
| Imputé d'une inscription, `imp(i)` | Somme de ses imputations actives. |
| Reste d'une inscription, `reste(i)` | `du(i) − imp(i)`. Il n'est jamais négatif (R7). |
| Ordre chronologique | Pour les versements : `date_versement`, puis `id`. |
| Disponible d'un versement, `dispo(v)` | `v.montant −` la somme de ses imputations actives. Il est nul pour un versement annulé. |
| Versement réservé | Versement dont le payeur est `employeur` (décisions 2 et 7). Les autres (`auditeur`, `autre`) sont **libres**. |
| Inscription désignée | `versements.inscription_annuelle_id` : l'inscription sur laquelle le versement a été saisi, qui sert de référence à son reçu. En D1, elle ne commande l'imputation que pour un versement réservé. |
| Avance | Somme des disponibles des versements valides de A. Elle se divise en **avance libre** et **avance réservée**. Calculée, jamais stockée. |
| Arriérés | Somme des restes des inscriptions antérieures à l'inscription courante. En production, ils restent nuls pendant D1 : chaque auditeur n'a qu'une inscription. |
| Reste sur le parcours | Somme des restes de toutes les inscriptions. |

Tous les montants sont des entiers de FCFA. Aucune règle ne divise : il n'y a **aucun arrondi**.

## 5. Données

### 5.1 `versements` (modifiée)

| Colonne ou contrainte | Règle |
|---|---|
| `auditeur_id` | **Nouvelle.** FK `auditeurs`, RESTRICT, indexée. La migration la renseigne depuis l'inscription, puis la passe en NOT NULL. |
| `inscription_annuelle_id` | **Inchangée et toujours NOT NULL en D1.** Son sens est précisé : c'est l'inscription désignée (§4). Elle deviendra facultative en D2, pour le versement sans inscription. |
| Unique `(id, auditeur_id)` | Nouvelle : cible de la clé composite des imputations. |
| FK composite `(inscription_annuelle_id, auditeur_id)` → `inscriptions_annuelles (id, auditeur_id)` | Nouvelle, RESTRICT : un versement ne désigne qu'une inscription de son propre auditeur. |
| Le reste | Inchangé : montant > 0, index unique `(mode, reference)` sur les lignes non annulées, `annule_*`, justificatif. |

Modèle `Versement` :
- `auditeur_id` rejoint `$fillable` : les services écrivent par `forceFill`, mais la fabrique passe par `fill` ;
- nouvelles relations `auditeur()`, `imputations()` et `imputationsActives()`.

### 5.2 `inscriptions_annuelles` (modifiée)

- Unique `(id, auditeur_id)`, cible des clés composites. Aucune autre colonne ne change.
- Nouvelle relation `imputationsActives()` (HasMany, `annule_at` nul).

### 5.3 `imputations_versements` (nouvelle)

| Colonne | Type | Règle |
|---|---|---|
| `id` | bigint | |
| `uuid` | uuid, unique | Seul identifiant exposé (règle 10 du projet) |
| `auditeur_id` | FK `auditeurs`, RESTRICT, indexée | Porte les deux clés composites |
| `versement_id` | bigint | FK composite `(versement_id, auditeur_id)` → `versements (id, auditeur_id)`, RESTRICT |
| `inscription_annuelle_id` | bigint | FK composite `(inscription_annuelle_id, auditeur_id)` → `inscriptions_annuelles (id, auditeur_id)`, RESTRICT |
| `montant` | integer | `CHECK (montant > 0)` |
| `origine` | varchar(10) | `CHECK` : `saisie`, `avance` ou `reprise`. D4 ajoutera `manuelle` en élargissant le `CHECK` (précédent du lot 14c). |
| `remplace_id` | FK `imputations_versements`, nullable, RESTRICT | Part conservée d'une imputation annulée en partie (R5). Index unique partiel : une ligne n'est remplacée qu'une fois. |
| `cree_par` | FK `users`, nullable, RESTRICT | L'agent du versement pour `saisie` ; nul pour `avance` et `reprise`, dont l'auteur de l'événement figure au journal |
| `annule_at` | timestamptz, nullable | |
| `annule_par` | FK `users`, nullable, RESTRICT | |
| `annule_motif` | varchar(500), nullable | |
| `created_at`, `updated_at` | timestamptz | |

Les clés vers `users` sont en RESTRICT, et non en SET NULL comme `versements.saisi_par`. Une mise à nul serait une modification de la ligne, que le déclencheur d'immuabilité refuserait. C'est aussi le choix fait pour les reçus (`emis_par`).

Contraintes et index en SQL brut (ADR-0013) :

```sql
ALTER TABLE imputations_versements ADD CONSTRAINT imputations_versements_check CHECK (
  montant > 0
  AND origine IN ('saisie', 'avance', 'reprise')
  AND (
    (annule_at IS NULL AND annule_par IS NULL AND annule_motif IS NULL)
    OR (annule_at IS NOT NULL AND annule_motif IS NOT NULL AND length(trim(annule_motif)) >= 5)
  )
);
CREATE INDEX imputations_versements_versement_actives ON imputations_versements (versement_id) WHERE annule_at IS NULL;
CREATE INDEX imputations_versements_inscription_actives ON imputations_versements (inscription_annuelle_id) WHERE annule_at IS NULL;
CREATE UNIQUE INDEX imputations_versements_remplace_unique ON imputations_versements (remplace_id) WHERE remplace_id IS NOT NULL;
```

Les colonnes `cree_par`, `annule_par` et `remplace_id` reçoivent aussi un index simple (convention du lot 1 sur les clés étrangères).

Modèle `ImputationVersement` (table `imputations_versements`) :
- `$fillable = []` : seul `ImputationsCompte` écrit, par `forceFill` ;
- garde-fous Eloquent, comme pour `RecuVersement` : `deleting` lève une exception, et `updating` aussi, sauf si seules `annule_at`, `annule_par`, `annule_motif` et `updated_at` changent sur une ligne encore active.

### 5.4 Déclencheurs

**Immuabilité** (avant modification ou suppression, ligne à ligne) : seule l'annulation est permise, et une seule fois.

```sql
CREATE OR REPLACE FUNCTION imputations_immuables() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    RAISE EXCEPTION 'Imputation immuable : annulez-la, ne la supprimez pas' USING ERRCODE = 'check_violation';
  END IF;
  IF OLD.annule_at IS NOT NULL OR NEW.annule_at IS NULL
     OR ROW(NEW.id, NEW.uuid, NEW.auditeur_id, NEW.versement_id, NEW.inscription_annuelle_id, NEW.montant,
            NEW.origine, NEW.remplace_id, NEW.cree_par, NEW.created_at)
        IS DISTINCT FROM ROW(OLD.id, OLD.uuid, OLD.auditeur_id, OLD.versement_id, OLD.inscription_annuelle_id, OLD.montant,
            OLD.origine, OLD.remplace_id, OLD.cree_par, OLD.created_at) THEN
    RAISE EXCEPTION 'Imputation immuable : seule son annulation, une fois, est permise' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER imputations_versements_immuables BEFORE UPDATE OR DELETE ON imputations_versements
  FOR EACH ROW EXECUTE FUNCTION imputations_immuables();
```

**Cohérence du compte** (déclencheur de contrainte, vérifié à la fin de chaque instruction). Cette spec tranche la question laissée ouverte par la proposition (§4.2) : un déclencheur porte aussi sur `inscriptions_annuelles`, puisque la remise y est écrite.

```sql
CREATE OR REPLACE FUNCTION compte_auditeur_coherent() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_id bigint; i_id bigint;
BEGIN
  IF TG_TABLE_NAME = 'imputations_versements' THEN
    v_id := NEW.versement_id; i_id := NEW.inscription_annuelle_id;
  ELSIF TG_TABLE_NAME = 'versements' THEN
    v_id := NEW.id;
  ELSE
    i_id := NEW.id;
  END IF;
  -- Versement : imputations actives au plus égales à son montant, et aucune s'il est annulé.
  IF v_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM versements v WHERE v.id = v_id
      AND (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x
             WHERE x.versement_id = v.id AND x.annule_at IS NULL)
          > CASE WHEN v.annule_at IS NULL THEN v.montant ELSE 0 END) THEN
    RAISE EXCEPTION 'Compte incohérent : versement % imputé au-delà de son montant, ou annulé avec des imputations actives', v_id
      USING ERRCODE = 'check_violation';
  END IF;
  -- Inscription : imputations actives au plus égales à son dû après remise.
  IF i_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM inscriptions_annuelles i WHERE i.id = i_id
      AND (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x
             WHERE x.inscription_annuelle_id = i.id AND x.annule_at IS NULL)
          > i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant) THEN
    RAISE EXCEPTION 'Compte incohérent : inscription % imputée au-delà de son dû', i_id USING ERRCODE = 'check_violation';
  END IF;
  RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER imputations_coherentes
  AFTER INSERT OR UPDATE ON imputations_versements
  DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
CREATE CONSTRAINT TRIGGER versements_compte_coherent
  AFTER UPDATE OF montant, annule_at ON versements
  DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
CREATE CONSTRAINT TRIGGER inscriptions_compte_coherent
  AFTER UPDATE OF montant_inscription, montant_tranche1, montant_tranche2, remise_montant ON inscriptions_annuelles
  DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION compte_auditeur_coherent();
```

- Ces déclencheurs garantissent R7 (§6.7) même en dehors de l'application : console, requête directe, script de reprise.
- Le contrôle est **reportable** (`DEFERRABLE`) mais **vérifié tout de suite** par défaut. Le service ordonne donc ses écritures pour que chaque instruction laisse un compte cohérent :
  - désimputer **avant** de relever une remise ;
  - annuler les imputations **avant** le versement ;
  - pour une désimputation partielle, annuler la ligne **avant** de créer la part conservée.

  Une erreur apparaît ainsi sur l'instruction fautive, et les tests, enveloppés dans la transaction de `RefreshDatabase`, n'ont rien de particulier à faire.
- Ils ne garantissent pas l'invariant d'avance (§6.7) : c'est le rôle du service, et `CPT_COHERENCE` le vérifie (§13).

### 5.5 `recus_versements` (modifiée)

- `objet` passe de varchar(255) à **varchar(500)**, parce qu'une imputation sur plusieurs années allonge le texte. Élargir un varchar ne réécrit aucune ligne et ne déclenche pas les déclencheurs ligne à ligne : les reçus émis ne changent pas.
- Rien d'autre ne change. `inscription_annuelle_id`, `annee_academique` et `niveau` restent NOT NULL en D1 ; ils deviendront facultatifs en D2, avec le versement sans inscription.

### 5.6 Migrations

| Fichier | Contenu |
|---|---|
| `2026_12_01_100000_create_imputations_versements_table.php` | Le schéma des §5.1 à §5.5, dans cet ordre (détail ci-dessous). |
| `2026_12_01_100100_reprise_comptes_auditeurs.php` | La reprise et sa vérification (§10), confiées à un service `RepriseComptes` appelé par `up()`, comme la reprise du lot 1. |

Ordre des opérations de la première migration :
1. `versements.auditeur_id`, nullable ;
2. renseignement : `UPDATE versements v SET auditeur_id = i.auditeur_id FROM inscriptions_annuelles i WHERE i.id = v.inscription_annuelle_id AND v.auditeur_id IS NULL` ;
3. passage en NOT NULL ;
4. uniques `(id, auditeur_id)`, puis clé composite des versements ;
5. table des imputations, `CHECK`, index, déclencheurs ;
6. élargissement de `recus_versements.objet`.

Règles communes :
- Chaque migration s'exécute dans une transaction (comportement de Laravel sous PostgreSQL) : un échec de la vérification annule tout.
- Le `down()` de la première refuse dès qu'il existe une imputation d'une autre origine que `reprise`, avec le message « Le compte a servi : revenez au code précédent sans annuler la migration. » (précédent des tables du lot 1). Sinon, il retire déclencheurs, table, clés, uniques et colonne, et ramène `objet` à 255 caractères ; il refuse si un objet plus long existe.
- Le `down()` de la seconde ne fait rien : les imputations de reprise disparaissent avec la table.

## 6. Règles d'imputation

### 6.1 R1 — Ordre des dus

L'argent va aux inscriptions qui ont un reste, de l'année académique la plus ancienne à la plus récente : les arriérés d'abord, puis l'année en cours (décision 1). Dans une inscription, l'ordre des paliers ne change pas (inscription, 1re tranche, 2e tranche) : `SituationFinanciere` le calcule toujours à partir de l'imputé.

### 6.2 Cibles d'un versement

- **Versement libre** : toutes les inscriptions de l'auditeur qui ont un reste, dans l'ordre R1.
- **Versement réservé (employeur)** : sa seule inscription désignée, si elle a un reste. Son excédent reste en avance réservée : il n'est jamais imputé d'office sur une autre année (décision 7). D4 donnera au responsable le moyen de décider.

### 6.3 R2 — À l'enregistrement d'un versement

1. Pour chaque cible, dans l'ordre : imputer `min(dispo(v), reste(i))` s'il est positif ; origine `saisie`, `cree_par` = l'agent.
2. Le disponible restant forme l'avance, sans aucune ligne écrite.
3. D4 ajoutera l'affectation accordée : l'inscription désignée passera alors en tête, même pour un versement libre.

### 6.4 R3 — Imputation de l'avance

R3 se déclenche après chaque événement qui ouvre un reste ou libère du disponible :
- création d'une inscription ;
- changement de remise (après R5) ;
- annulation d'un versement (après R4).

Elle procède en deux passes :
1. **Versements réservés**, dans l'ordre chronologique : chacun sur son inscription désignée, pour `min(dispo, reste)`. L'employeur règle d'abord l'année qu'il couvre, et l'argent de l'auditeur reste libre pour la suite.
2. **Versements libres**, dans l'ordre chronologique, le plus ancien étant consommé le premier : chacun sur ses cibles R1.

Ces imputations ont l'origine `avance` et un `cree_par` nul. Un événement sans avance ou sans reste n'écrit rien.

### 6.5 R4 — Annulation d'un versement

Dans la transaction de l'annulation :
1. **Refus en D1** si les imputations actives du versement portent sur plus d'une inscription. Message : « Ce versement est imputé sur plusieurs années (AAAA-AAAA, AAAA-AAAA) : son annulation passera par une demande d'autorisation (sous-lot D4). Prévenez l'USI. » Ce cas ne peut pas se produire avant D3 (§2). Une annulation qui ne touche qu'une année, quelle qu'elle soit, reste au responsable seul (décision 8).
2. Toutes ses imputations actives sont annulées, `annule_par` = l'auteur, motif « Versement annulé : <motif> ». Puis le versement lui-même est annulé.
3. On ne prélève **jamais** sur les imputations d'un autre versement.
4. R3 s'applique ensuite : une avance disponible comble les restes rouverts, du plus ancien au plus récent.
5. Le reçu en vigueur reçoit son écriture d'annulation, comme aujourd'hui.

### 6.6 R5 — Changement de remise

Une **hausse de remise** fait baisser le dû. Avant d'écrire la nouvelle remise, si `imp(i)` dépasse le nouveau dû, l'excédent `e` est désimputé. On part des imputations les plus récentes de l'inscription : versement le plus récent d'abord (`date_versement` puis `id` décroissants), puis `id` de l'imputation décroissant.
- Une imputation dont le montant ne dépasse pas `e` est annulée en entier.
- Sinon, elle est annulée, puis une nouvelle imputation reprend la part conservée : `montant − e`, même versement, même inscription, même origine, même `cree_par`, et `remplace_id` = la ligne annulée.
- Motif : « Remise portée à N FCFA : <motif de la remise> ».

Une **baisse de remise** fait monter le dû : il n'y a rien à désimputer.

Dans les deux cas, R3 s'applique ensuite. Par exemple, un disponible libéré sur le M1 va combler un reste de la M2.

### 6.7 R7 — Garde-fous et invariants

| Garde-fou | Garanti par |
|---|---|
| Aucun montant nul ou négatif | `CHECK` |
| Les imputations actives d'un versement ne dépassent pas son montant, et un versement annulé n'en a aucune | Déclencheur de cohérence |
| Les imputations actives d'une inscription ne dépassent pas son dû | Déclencheur de cohérence |
| Versement, inscription et imputation appartiennent au même auditeur | Clés étrangères composites |
| Aucune suppression ni modification, sauf l'annulation, une fois | Déclencheur d'immuabilité et modèle |
| **Invariant d'avance** : une avance libre positive implique qu'aucune inscription de l'auditeur n'a de reste ; une avance réservée positive implique que son inscription désignée n'a pas de reste | Service (R2 à R5), vérifié par `CPT_COHERENCE` |

### 6.8 R8 — Verrou unique

- Toute transaction qui écrit de l'argent commence par `SELECT … FROM auditeurs WHERE id = ? FOR UPDATE` sur l'auditeur concerné, et ne verrouille qu'un auditeur.
- Ordre fixe : l'auditeur ; puis les lignes modifiées de l'inscription (verrou implicite de l'`UPDATE`) ; puis le reçu et le compteur des reçus.
- Ce verrou remplace celui de l'inscription dans `RegistreVersements` et `RecusVersements::emettre`. C'est lui, désormais, qui garantit l'invariant « un seul reçu en vigueur par versement ».
- `StatutAuditeur` et `Etiquettes` prennent déjà ce verrou : ils attendent quelques millisecondes, sans risque d'interblocage.
- En D4, un règlement réparti entre plusieurs auditeurs les verrouillera par `id` croissant.

### 6.9 R9 — Déterminisme

La même suite d'événements produit les mêmes imputations :
- l'ordre est total partout : une seule année par auditeur et par inscription ; les versements par date puis `id` ; les imputations par `id` ;
- le calcul ne fait appel à aucun aléa ni à l'heure, sauf pour `created_at`.

La réimputation manuelle (R6) n'est pas dans D1 : elle arrive en D4.

### 6.10 Exemples de référence

Barème 2026-2027 : M1 = 185 000 + 500 000 + 500 000 = **1 185 000**. Pour les besoins des tests, une inscription 2027-2028 est créée à la main : M2 = 1 135 000, ou M1 redoublée = 1 185 000. Auditeurs fictifs. Ces cas servent de table de tests au planificateur (§14.1).

| Cas | Événements | Imputations actives | Avance, restes |
|---|---|---|---|
| **E1** (S1) | M1 ; versement libre de 2 320 000 | M1 : 1 185 000 (`saisie`) | Avance libre : 1 135 000 |
| **E2** (S1, suite) | E1, puis création de la M2 2027-2028 | M1 : 1 185 000 ; M2 : 1 135 000 (`avance`) | Avance : 0 ; M2 soldée |
| **E3** (S2) | M1 ; 185 000 ; 500 000 ; création de la M2 ; 300 000 | M1 : 185 000 + 500 000 + 300 000 (le dernier va sur la 2e tranche du M1) ; M2 : rien | Arriérés : 200 000 ; reste sur le parcours : 1 335 000 |
| **E4** (S3) | M1 ; 1 685 000 ; création du M1 2027-2028 (redoublement) | M1 2026-2027 : 1 185 000 ; M1 2027-2028 : 500 000 (`avance`) | Reste 2027-2028 : 685 000 |
| **E5** (S4) | M1 ; 185 000 (auditeur) ; 1 185 000 (employeur, M1 désigné) | M1 : 185 000 + 1 000 000 | Avance réservée : 185 000 |
| **E6** (S4, suite) | E5, puis annulation des 185 000 de l'auditeur | M1 : 1 000 000 + 185 000 (`avance`, versement de l'employeur, passe 1) | Avance : 0 ; M1 soldée |
| **E7** (S4, variante) | E5, puis création de la M2 | M1 : inchangé ; M2 : rien | Avance réservée : 185 000 ; M2 reste 1 135 000 (décision 7 : rien d'office) |
| **E8** (remise) | M1 ; 685 000 ; 500 000 ; remise de 200 000 | M1 : 685 000 + 300 000 (remplace la ligne de 500 000) | Avance libre : 200 000 |
| **E9** (remise retirée) | E8, puis remise ramenée à 0 | M1 : 685 000 + 300 000 + 200 000 (`avance`, même versement de 500 000) | Avance : 0 |
| **E10** (exonération) | M1 ; 185 000 ; remise de 1 185 000 | Aucune (la ligne de 185 000 est annulée) | Avance libre : 185 000 ; droits soldés par la remise |
| **E11** (S5) | E2, puis annulation du versement de 2 320 000 | Inchangées | **Refusée en D1** (deux années) ; en D4, par demande d'autorisation |

## 7. Services

### 7.1 Planificateur, pur

`App\Services\Scolarite\PlanificateurImputations` n'utilise ni la base ni l'horloge.

- **Entrée** : un `EtatCompte` et un événement (`versement(id)`, `avance()`, `annulation(id)`, `remise(inscriptionId, nouvelleRemise)`).
  - L'`EtatCompte` décrit les inscriptions (id, année, dû, imputations actives) et les versements valides (id, date, montant, payeur, inscription désignée, imputations actives).
- **Sortie** : un `PlanImputations`, liste ordonnée de désimputations (id, motif) et de nouvelles imputations (versement, inscription, montant, origine, ligne remplacée), avec l'état qui en résulte.
- **Usages** :
  - écrire, par `ImputationsCompte` ;
  - prévoir sans rien écrire : avertissements « l'annulation rebloquerait l'accès » et « la remise rebloquerait l'accès » (`RegistreVersements::annulationRebloque` et `remiseRebloque`), années touchées, montant d'avance annoncé dans le formulaire.

### 7.2 Écrivain unique, `ImputationsCompte`

C'est le seul code qui écrit dans `imputations_versements`. `RegistreVersements` reste le seul à écrire les versements et la remise : il appelle `ImputationsCompte` avant et après ses propres écritures, dans l'ordre exigé par le déclencheur (§5.4).

- `sousVerrou(int $auditeurId, Closure $geste): mixed` ouvre une transaction (ou un point de sauvegarde), verrouille l'auditeur (R8) et exécute le geste. Les autres méthodes lèvent une `LogicException` hors de ce cadre : l'instance mémorise l'auditeur verrouillé.
- Méthodes :

  | Méthode | Règle | Appelée |
  |---|---|---|
  | `apresVersement(Versement, User)` | R2 | Après la création du versement |
  | `avantAnnulation(Versement, motif, User)` | Refus R4.1, puis annulation des imputations du versement | Avant d'annuler le versement |
  | `avantRemise(InscriptionAnnuelle, nouvelleRemise, motif, User)` | R5 : désimputation de l'excédent | Avant d'écrire la nouvelle remise |
  | `imputerAvance(int $auditeurId, string $declencheur, ?User)` | R3 | Après une annulation, une remise ou une ouverture |
  | `reprendre(int $auditeurId)` | Reprise (§10.1) | Par `RepriseComptes`, depuis la migration seulement |

- Chaque méthode charge l'`EtatCompte` sous verrou, demande le plan, l'écrit, journalise (§12) et renvoie le plan appliqué.

### 7.3 Lecture, `CompteAuditeur`

C'est un objet en lecture seule, construit en trois requêtes au plus : les inscriptions avec leur barème et leur imputé, les versements valides avec leur imputé, le calcul du disponible.

- Méthodes : `situation(InscriptionAnnuelle)`, `avance()`, `avanceLibre()`, `avanceReservee()`, `arrieres()`, `resteParcours()` et `inscriptionsTouchees(Versement)`.
- C'est la source de l'avance pour la fiche, l'espace, le relevé et l'export. D2 l'étendra au relevé chronologique.
- Pour une liste, `Auditeur::scopeAvecAvance()` ajoute la colonne calculée `avance` :

```sql
(SELECT COALESCE(SUM(v.montant), 0) FROM versements v WHERE v.auditeur_id = auditeurs.id AND v.annule_at IS NULL)
- (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x WHERE x.auditeur_id = auditeurs.id AND x.annule_at IS NULL)
```

Le résultat est exact grâce au déclencheur : un versement annulé n'a aucune imputation active.

### 7.4 Points d'appel : tous les écrivains d'argent

| Code | Changement |
|---|---|
| `RegistreVersements::enregistrer` | Sous le verrou de l'auditeur, au lieu de celui de l'inscription : contrôle du doublon, création du versement avec `auditeur_id` et `inscription_annuelle_id` = l'inscription reçue (la courante), puis `apresVersement`. La signature ne change pas. |
| `RegistreVersements::annuler` | Sous le verrou de l'auditeur : `avantAnnulation`, annulation du versement, `imputerAvance('annulation')`, puis écriture d'annulation du reçu. |
| `RegistreVersements::accorderRemise` | Sous le verrou de l'auditeur : `avantRemise`, écriture de la remise, puis `imputerAvance('remise')`. Les contrôles (motif, plafond, dossier retiré) ne changent pas. |
| `RegistreVersements::annulationRebloque`, `remiseRebloque` | Calculés par le planificateur. Même résultat qu'aujourd'hui pour une seule inscription, avec en plus la liste des années touchées. |
| `RecusVersements::emettre` | Verrou de l'auditeur du versement, au lieu de l'inscription ; objet lu dans les imputations (§9.2). |
| `RecusVersements::raisonRefus`, `enAttente` | Passent par `versement.auditeur.candidature` au lieu de `versement.inscription.auditeur.candidature`. |
| `OuvertureAuditeur::creerInscription` | Sous le verrou de l'auditeur : contrôle d'existence (un double clic attend le verrou, puis retrouve l'inscription), création de l'inscription, `reprendrePointage` (dont le versement passe par `apresVersement`), puis `imputerAvance('ouverture')`. L'inscription pédagogique (`InscriptionsPedagogiques::inscrire`) reste appelée après, comme aujourd'hui. **Aucun envoi nouveau** : l'ouverture ne prévient toujours pas l'auditeur, et la reprise du lot 1 en dépend. D3 ajoutera l'envoi après l'imputation de l'avance. |
| `ImportAuditeurs` | Inchangé : il crée un auditeur neuf et son inscription, sans versement. |
| `DeblocagesAcces::accorder` | Verrouille l'auditeur avant l'inscription (ordre R8). Le contrôle « droits déjà soldés » lit l'imputé. |
| `Auditeur::versements()` | Devient un `HasMany` sur `auditeur_id` (fin du `HasManyThrough`). `historique()` lit les versements par `auditeur_id`. |
| Fabrique `VersementFactory` | Renseigne `auditeur_id` depuis l'inscription et appelle `apresVersement` après la création, sous verrou. Un état `sansImputation()` sert aux tests de la reprise. |
| Assistant de test `annulerVersements()` (`tests/Pest.php`) | Annule d'abord les imputations actives des versements visés (motif « Annulation de test »), puis les versements. |

## 8. Concurrence et erreurs

- Deux écritures sur le même auditeur se suivent, grâce au verrou. L'index unique `(mode, reference)` continue de trancher les doublons de bordereau, avec le même message.
- Le déclencheur de cohérence lève une erreur `check_violation` (SQLSTATE 23514), qui annule toute la transaction. Elle n'est jamais montrée telle quelle à l'agent. `RegistreVersements` la journalise (canal `single`, avec l'`uuid` de l'auditeur) et la convertit en `InvalidArgumentException` : « Le compte de l'auditeur n'a pas pu être mis à jour : rien n'a été enregistré. Prévenez l'USI. » Le cas relève d'un défaut du code, et `CPT_COHERENCE` ne le verra pas, puisque rien n'a été écrit : le journal applicatif est la seule trace.
- Les messages destinés à l'agent restent des `InvalidArgumentException`, comme au lot 1.

## 9. Effets sur l'existant

### 9.1 Situation financière et scopes

- `SituationFinanciere::calculer` est **inchangée** : signature, paliers et tests unitaires.
- `InscriptionAnnuelle::situation()` : `verse` devient l'imputé, lu dans `imputations_actives_sum_montant` s'il est préchargé, sinon par `imputationsActives()->sum('montant')`. `tropPercu` vaut donc toujours 0, et plus aucun écran ne l'affiche : l'avance du compte le remplace.
- Dans les scopes, seule la sous-requête `VERSE` change :

  ```php
  private const VERSE = '(SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x WHERE x.inscription_annuelle_id = inscriptions_annuelles.id AND x.annule_at IS NULL)';
  ```

  `DU_*`, `scopeInscriptionSoldee`, `scopeInscriptionNonSoldee`, `scopeEntierementSoldee`, `scopeEnRetard` et `scopeAccesOuvert` ne changent pas.
- Préchargements : `withSum('versementsValides', 'montant')` devient `withSum('imputationsActives', 'montant')` dans `AuditeurResource`, `CandidatureResource`, `ListesAuditeurs` et `MessagesAuditeurs`. La relation `versementsValides()` reste : elle donne les versements qui désignent l'inscription, affichés dans l'espace.

**Pourquoi rien ne change pour les cas existants.** Après la reprise, `imp(i) = min(V(i), du(i))`, où `V(i)` est la somme des versements valides de l'inscription. Or tous les seuils que comparent les paliers et les scopes (dû des droits, dû jusqu'à la 1re tranche, dû total) sont au plus égaux à `du(i)`. Pour un seuil `T ≤ du(i)`, `min(V, du) ≥ T` équivaut à `V ≥ T`.

Paliers, reste, « soldé », « en retard », « droits soldés » et accès sont donc identiques. Pour une inscription en trop-perçu, seuls changent `verse`, ramené au dû, et `tropPercu`, qui passe à 0 ; l'excédent devient avance. Le test de propriété du §14.1 vérifie cette équivalence sur des suites aléatoires.

### 9.2 Reçus

À l'émission (`RecusVersements::emettre`, sous le verrou de l'auditeur) :

1. Les imputations actives du versement V sont regroupées par inscription, par année croissante.
2. Pour chaque inscription I touchée, on calcule :
   - `avant(I)` : l'imputé actif sur I par les versements qui précèdent V (date, puis `id`) ;
   - `part(I)` : l'imputé actif de V sur I.

   La part est découpée sur les paliers de I, de `avant(I)` à `avant(I) + part(I)`, comme aujourd'hui.
3. `avance(V) = V.montant − Σ part(I)`.
4. Objet :
   - si V ne touche que son inscription désignée, ou aucune, le **format actuel** est conservé : `Frais de scolarité 2026-2027 : droits d'inscription (185 000 FCFA), 1re tranche (500 000 FCFA), 2e tranche (500 000 FCFA), avance sur la scolarité à venir (1 135 000 FCFA)` ;
   - si V touche une autre année, l'objet donne une partie par année, avec le niveau, séparées par « ; » : `Frais de scolarité 2026-2027 (M1) : 2e tranche (300 000 FCFA) ; 2027-2028 (M2) : droits d'inscription (135 000 FCFA) ; avance sur la scolarité à venir (40 000 FCFA)` ;
   - au-delà de 500 caractères, chaque année est résumée par son total : `2026-2027 (M1) : 300 000 FCFA`.
5. `total_verse = avant(R) + part(R)` pour l'inscription désignée R, et `reste_a_payer = du(R) − total_verse`. Le PDF les affiche sous « Total versé pour l'année » et « Reste à payer » : l'avance n'y est plus comptée.
6. **Les reçus déjà émis gardent leurs valeurs**, y compris le trop-perçu compris dans leur total. Ils sont figés, et le PDF se régénère depuis les colonnes stockées.
7. L'imputation automatique d'une avance ne produit ni nouveau reçu ni autre document (décision 9).
8. Pour le téléchargement par l'auditeur (`ScolariteController::recu`), le contrôle d'appartenance passe par `versement.auditeur_id`, au lieu de l'inscription du reçu. Le résultat est le même en D1, et D2 en aura besoin.

Le libellé « avance sur la scolarité à venir » est celui du correctif de la décision 13. D1 réécrit `RecusVersements::imputation` et le reprend.

### 9.3 Accès à eCampus et accès anticipé

- La règle ne change pas : `AccesEcampus::accesOuvert` exige les droits de l'inscription courante soldés, ou un déblocage en cours. Elle lit désormais l'imputé, avec le même résultat (§9.1).
- Après un versement ou une remise, la régularisation du déblocage (`DeblocagesAcces::regulariser`) et la notification (`NotificationAcces::siDisponible`) partent, comme aujourd'hui, hors de la transaction et sans jamais faire échouer l'écriture de l'argent.
- Une annulation ne peut pas solder des droits : avant elle, l'invariant d'avance garantit qu'aucune avance libre ne côtoie un reste. Elle ne déclenche donc ni régularisation ni notification, comme aujourd'hui.
- Le SMS « accès disponibles » reste unique par inscription (`acces_notifie_at`).

### 9.4 Espace de l'auditeur et API

`GET /v1/applications/me/scolarite` (`ScolariteResource`) :
- `verse` : l'imputé de l'inscription courante. Il ne change que pour une inscription en trop-perçu, ramené au dû ;
- `avance` : **nouveau**, entier positif ou nul, avance du compte (libre et réservée) ;
- `trop_percu` : conservé jusqu'à D2 et égal à `avance`, pour que le portail déployé avant D1 affiche encore le bon montant ; retiré en D2 ;
- le reste ne change pas : `paliers`, `versements` de l'inscription courante, `acces`.

Portail `candidature` :
- le type `MaScolarite` reçoit `avance` ;
- la carte de scolarité lit `avance`, avec le libellé posé par le correctif de la décision 13 (clé next-intl `avance`, en français et en anglais).

Relevé PDF (`releve-scolarite`) : la ligne « Trop-perçu » devient « Avance sur la scolarité à venir : X », lue dans `avance`.

### 9.5 Administration

- **Fiche, bloc « Situation »** (`filament/scolarite/situation.blade.php`) : « Trop-perçu : X » devient « Avance au compte : X », lue dans `CompteAuditeur`. S'il y a lieu, la ligne précise : « dont Y versés par l'employeur, non imputés d'office sur une autre année (décision du responsable) ».
- **Formulaire « Enregistrer un versement »** :
  - la description annonce le reste à payer sur le parcours (`resteParcours`), égal en D1 au reste de l'année courante ;
  - la case `confirmer_trop_percu` devient `confirmer_avance`, avec le libellé « Je confirme : Z FCFA dépassent le reste à payer et iront en avance sur la scolarité à venir. » Pour un payeur employeur : « … et resteront en avance, en attente de la décision du responsable. » Elle reste obligatoire dès que le montant dépasse le reste.
- **Onglet « Versements »** : inchangé, la colonne « Année » montre l'inscription désignée. La répartition par année arrive en D2.
- **Export Excel** (`SituationFinanciereExcel`) : une colonne **L « Avance »** est ajoutée après « Situation » ; les colonnes A à K ne bougent pas. « Versé » y est l'imputé de l'année.
- **Liste PDF « Situation financière »** (`ListesAuditeurs`) : colonnes inchangées. Sous le total, une ligne « Avances au compte, non comprises dans le versé : X FCFA » apparaît si X est positif.
- **Liste des auditeurs, relances, documents LMD** : inchangés en D1 (D2).

### 9.6 Ce qui ne change pas

- Barèmes et montants copiés ; remise unique par inscription ; paliers.
- Règle d'accès ; création d'inscription sans SMS.
- Reçus : numérotation, contenu figé, écriture d'annulation ; séparation des tâches.
- Unicité des références bancaires ; droits ; variable `{reste}` des relances.
- `DocumentsOfficiels::enRegle` : les arriérés y entrent en D2 (décision 4).

## 10. Reprise des versements existants

### 10.1 Algorithme (`RepriseComptes::executer()`)

Pour chaque auditeur qui a au moins un versement, par `id` croissant et sous son verrou :

1. **Idempotence** : un auditeur qui a déjà une imputation, active ou annulée, est ignoré.
2. Pour chaque inscription, par année croissante, `restant = du(i)`. Pour chaque versement valide qui la désigne, dans l'ordre chronologique : imputer `min(montant, restant)` s'il est positif, origine `reprise`, `cree_par` nul, puis diminuer `restant` d'autant.
3. Ce qui dépasse devient avance, sans ligne.
4. R3 s'applique ensuite (§6.4), origine `avance`. Elle est sans effet pour un auditeur qui n'a qu'une inscription, ce qui est le cas de tous en production (P14 en M1, P13 admis en M2). Le bilan compte ces imputations : 0 attendu.
5. Un événement `compte_repris` est journalisé (sujet : l'auditeur) seulement si l'avance est positive (§12).

Les versements annulés ne reçoivent aucune imputation. La reprise n'envoie aucun SMS ni e-mail et n'émet aucun reçu ; les reçus émis restent tels quels.

### 10.2 Vérification dans la migration, bloquante

Après la reprise, dans la même transaction :

1. **Équivalence par inscription**, pour toute inscription d'un auditeur qui n'a pas reçu d'imputation `avance` à l'étape 4 : `SituationFinanciere::calculer` donne le même résultat avec l'**ancienne** somme (versements valides de l'inscription) et avec l'imputé. Sont comparés : les paliers (dû, payé, reste, retard), le dû total, le reste, « droits soldés » et « soldé ».
2. **Sommes par auditeur** :
   - la somme des anciens trop-perçus est égale à l'avance ;
   - les versements valides sont égaux à l'imputé plus l'avance ;
   - un auditeur sans trop-perçu n'a pas d'avance (total imputé = total versé).
3. **Effectifs des scopes** : `inscriptionSoldee`, `entierementSoldee`, `enRetard` et `accesOuvert` comptent les mêmes inscriptions avec l'ancienne sous-requête, sur `versements` (recopiée dans la migration), et avec la nouvelle.
4. **Invariant d'avance** (§6.7) respecté pour tous les auditeurs.

Un écart lève une exception qui nomme les inscriptions par leur `uuid`, jamais par un nom : la migration est alors annulée en entier.

### 10.3 Bilan

Le bilan est écrit au journal applicatif, par `Log::info('Reprise des comptes', $bilan)` :
- auditeurs traités et ignorés ;
- imputations créées et montant imputé ;
- avance totale, dont réservée, et nombre d'auditeurs avec avance ;
- imputations d'avance de l'étape 4 (0 attendu) ;
- durée.

### 10.4 Commande de vérification

`php artisan scolarite:verifier-comptes [--equivalence]` s'exécute en **lecture seule** : en production, dans une transaction ouverte en `READ ONLY` ; en test, déjà dans une transaction, en n'émettant que des `SELECT`.

- **Toujours** :
  - les garde-fous refaits en SQL : sommes par versement et par inscription ;
  - versements valides = imputé + avance, par auditeur ;
  - invariant d'avance ;
  - totaux : versé valide, imputé, avance.
- **Avec `--equivalence`** : équivalence par inscription, effectifs des scopes, et aucune avance sans ancien trop-perçu (contrôles 1, 3 et la dernière partie du 2 au §10.2). Ces contrôles restent valables tant qu'aucune inscription n'a reçu l'argent d'un versement qui ne la désigne pas, donc jusqu'à D3.
- **Sortie** : effectifs et montants seulement, plus les `uuid` des inscriptions en écart. Le code de retour est non nul en cas d'écart.

## 11. Droits

Il n'y a aucun nouveau droit. Les gestes gardent leurs permissions : `scolarite.versement_saisir`, `scolarite.versement_annuler`, `scolarite.remise` et `scolarite.recu_emettre`. Les imputations automatiques relèvent du droit du geste qui les déclenche, et aucune action ne les écrit directement. Il n'y a donc ni migration de permissions, ni `permission:cache-reset`.

## 12. Journal

Journal `scolarite`, scellé par le lot 14d-1. Les propriétés ne contiennent que des montants et des `uuid`.

| Événement | Sujet | Quand | Propriétés |
|---|---|---|---|
| `versement_enregistre` (existant) | Versement | R2 | Ajout de `imputations` (liste `{inscription, annee, montant}`) et de `avance` |
| `versement_annule` (existant) | Versement | R4 | Ajout de `imputations_annulees` (même forme) |
| `remise_accordee` (existant) | Inscription | R5 | Ajout de `desimpute` (montant) |
| `avance_imputee` (nouveau) | Auditeur | R3, quand une ligne est écrite | `declencheur` (`ouverture`, `remise` ou `annulation`) et `imputations` |
| `compte_repris` (nouveau) | Auditeur | Reprise, si l'avance est positive | `imputations`, `impute`, `avance` |

Ces sujets (auditeur, inscription, versement) figurent déjà dans l'historique de la fiche (`Auditeur::historique`) : il n'y a rien à y ajouter.

## 13. Contrôle interne `CPT_COHERENCE`

Classe `App\Services\Supervision\Controles\CptCoherence`, inscrite au catalogue de `Supervision` avant `SysControles`.

- Criticité **bloquant**, délai d'un jour, responsable `admin` (USI).
- Cas, par clé stable :
  - `versement:<uuid>` : imputations actives supérieures au montant, ou imputation active sur un versement annulé ;
  - `inscription:<uuid>` : imputé supérieur au dû ;
  - `auditeur:<uuid>` : invariant d'avance rompu (avance libre à côté d'un reste ouvert, ou avance réservée alors que son inscription désignée a un reste).
- Résumé : le matricule et les montants, sans nom, comme `DEB_ECHUS`.
- Les deux premiers cas ne peuvent naître qu'en contournant les déclencheurs. Le troisième signale un oubli de R3 dans le code.

## 14. Tests attendus

### 14.1 Planificateur (unitaires, sans base)

- La table des exemples E1 à E11 (§6.10).
- R1 : arriérés avant l'année courante ; paliers dans l'ordre.
- Versement réservé : l'excédent d'un employeur n'est jamais imputé sur une autre année (E7).
- R3 : deux passes ; le versement libre le plus ancien est consommé le premier.
- R5 : désimputation partielle avec `remplace` ; ordre « le plus récent d'abord ».
- R4.1 : refus d'une annulation qui touche deux années.
- **Propriétés**, sur des suites aléatoires de versements, annulations, remises et créations d'inscription (au moins 1 000 suites, graine journalisée pour rejouer un échec) :
  - les invariants R7 et l'invariant d'avance tiennent après chaque événement ;
  - **déterminisme** : rejouer une suite donne le même plan ;
  - **équivalence à une seule inscription** : pour un auditeur à une inscription, après toute suite de versements, d'annulations et de remises, `imp = min(Σ versements valides, dû)` et `avance = Σ − imp`, comme l'ancienne formule ;
  - **conservation** : `Σ versements valides = Σ imputé + avance`.

### 14.2 Base de données

Chaque tentative se fait dans son point de sauvegarde (`DB::transaction`), comme le test d'immuabilité des reçus.

- **Immuabilité** : modifier le montant, l'origine ou le versement d'une imputation, l'annuler deux fois ou la supprimer est refusé, y compris par une requête directe.
- **Cohérence** : sont refusés, chacun sur l'instruction fautive, la sur-imputation d'un versement, l'annulation d'un versement qui a encore des imputations actives, et la hausse de remise posée sans désimputation.
- **Clés composites** : une imputation ou un versement à cheval sur deux auditeurs est refusé.
- **`CHECK`** : montant nul, origine inconnue ou annulation sans motif sont refusés.

### 14.3 Services

- `RegistreVersements` :
  - versement au-delà du reste : imputation, avance, et case `confirmer_avance` obligatoire ;
  - annulation : imputations annulées, avance d'un autre versement réimputée, reçu annulé ;
  - hausse puis baisse de remise (E8, E9) ; exonération totale (E10) ;
  - versement d'un employeur (E5, E6) ;
  - refus R4.1 sur deux inscriptions 2026-2027 et 2027-2028 créées à la main.
- **Ordre des verrous** : avec `DB::listen`, la première requête de chaque écriture d'argent est le `SELECT … FOR UPDATE` sur `auditeurs`, et le verrou de l'inscription a disparu de `RegistreVersements` et `RecusVersements`. Un essai réel à deux sessions simultanées est fait en recette (§15.2).
- `OuvertureAuditeur::creerInscription` avec une avance existante : imputation `avance` à l'ouverture, aucun SMS.
- `DeblocagesAcces::accorder` : le refus « droits déjà soldés » lit l'imputé ; la régularisation après un versement ne change pas.
- **Tests existants** des lots 1, 4 et A1 : ils restent verts sans changer leurs attentes, sauf ceux qui portent sur un trop-perçu :
  - `RecusVersementsTest` : libellé « avance » et `total_verse` sans l'excédent ;
  - `AuditeurResourceTest` : case `confirmer_avance` ;
  - `ModeleScolariteTest`, s'il y a lieu : le cas « trop-perçu » du filtre en base.

  Les tests unitaires de `SituationFinanciere` ne changent pas.

### 14.4 Reçus

- Objet au format actuel pour une seule année, avec « avance sur la scolarité à venir » ; format par année si une autre année est touchée ; résumé par année au-delà de 500 caractères.
- `total_verse` et `reste_a_payer` portent sur l'année désignée, avance exclue.
- Une seconde émission pour le même versement est refusée sous le verrou de l'auditeur : un seul reçu en vigueur.
- Un reçu émis avant D1 se régénère avec le même texte.
- Téléchargement par l'auditeur via `versement.auditeur_id` ; un autre auditeur reçoit 404.

### 14.5 Reprise

- Équivalence (§10.2) sur un jeu de cas : M1 et M2, remise partielle, exonération, trop-perçu, versements annulés, versement de reprise du pointage, dossier retiré, déblocage en cours, versement d'un employeur.
- L'avance est égale à l'ancien trop-perçu, et il n'y a pas d'avance sans trop-perçu.
- Idempotence : une seconde exécution n'écrit rien.
- Les mêmes auditeurs gardent leur accès : `AccesEcampus::etat` est identique avant et après.
- La reprise n'envoie ni SMS ni e-mail et n'émet aucun reçu.
- Le `down()` est refusé dès qu'une imputation `saisie` existe.
- `scolarite:verifier-comptes` rend 0 sur une base saine. Il rend un code non nul sur une incohérence injectée dans un point de sauvegarde (déclencheurs reportés par `SET CONSTRAINTS ALL DEFERRED`, commande lancée avant le retour arrière). Il n'émet que des `SELECT` (vérifié par `DB::listen`).

### 14.6 Lecture et affichage

- Scopes et PHP : le test existant « filtre en base comme le calcul en PHP » passe sur les imputations, avec des cas de trop-perçu et de remise.
- `Auditeur::scopeAvecAvance` donne le même montant que `CompteAuditeur::avance()`.
- API : `avance` présent, `trop_percu` égal à `avance`, `verse` ramené au dû ; un auditeur ne lit jamais un autre.
- Fiche : « Avance au compte » et mention de l'employeur ; libellé de la case du formulaire.
- Export Excel : colonne L ; liste PDF : ligne des avances.
- `CPT_COHERENCE` : chacun des trois cas est détecté sur une incohérence injectée dans un point de sauvegarde du test (déclencheurs reportés par `SET CONSTRAINTS ALL DEFERRED`, contrôle exécuté avant le retour arrière). Aucun cas n'est trouvé sur une base saine.
- Playwright (portail) : la carte affiche l'avance, en français et en anglais.

### 14.7 Outillage et relectures

- `VersementFactory` impute après la création ; `annulerVersements()` annule aussi les imputations.
- Relectures par les sous-agents : `security-reviewer`, `filament-reviewer`, `candidature-reviewer` (espace) et `i18n-reviewer` (portail).

## 15. Déploiement

### 15.1 Avant : requêtes en lecture seule, sur la production telle qu'elle est

```sql
-- a) Aucun auditeur n'a plus d'une inscription (attendu : 0).
SELECT count(*) FROM (
  SELECT auditeur_id FROM inscriptions_annuelles GROUP BY auditeur_id HAVING count(*) > 1) t;

-- b) Versements valides, nombre et total. Après la migration : imputé + avance = ce total.
SELECT count(*), COALESCE(sum(montant), 0) FROM versements WHERE annule_at IS NULL;

-- c) Effectifs par état et trop-perçus. Après : mêmes effectifs, aucun trop-perçu,
--    et une avance totale égale au total des trop-perçus.
WITH s AS (
  SELECT i.id,
    (SELECT COALESCE(sum(v.montant), 0) FROM versements v
       WHERE v.inscription_annuelle_id = i.id AND v.annule_at IS NULL) AS verse,
    GREATEST(0, i.montant_inscription - GREATEST(0, i.remise_montant - i.montant_tranche1 - i.montant_tranche2)) AS du_inscription,
    i.montant_inscription + i.montant_tranche1 + i.montant_tranche2 - i.remise_montant AS du
  FROM inscriptions_annuelles i)
SELECT count(*) AS inscriptions,
       count(*) FILTER (WHERE verse >= du_inscription) AS droits_soldes,
       count(*) FILTER (WHERE verse >= du) AS soldees,
       count(*) FILTER (WHERE verse > du) AS trop_percus,
       COALESCE(sum(verse - du) FILTER (WHERE verse > du), 0) AS total_trop_percus
FROM s;
```

Le nombre de trop-perçus dit combien d'auditeurs verront leur « Versé » ramené au dû et une avance apparaître. Le responsable de scolarité en est prévenu avant la bascule.

### 15.2 Séquence

1. Vérifier que le correctif « avance » de la décision 13 est en production ; sinon, D1 l'inclut.
2. Choisir une fenêtre courte, hors saisie : mode maintenance (`php artisan down`), file arrêtée.
3. **Sauvegarder la base** (`pg_dump`, en plus de la sauvegarde quotidienne) et la garder jusqu'à la stabilisation.
4. Pousser le code, puis lancer `php artisan config:clear` et `php artisan migrate --force` : schéma, reprise, vérification. Un écart annule tout, et le code précédent peut repartir tel quel.
5. Lancer `php artisan scolarite:verifier-comptes --equivalence` : code 0 attendu, et totaux comparés aux requêtes b) et c) du §15.1.
6. Lancer `php artisan optimize`, redémarrer la file, puis `php artisan up`.
7. Exécuter une fois le contrôle `CPT_COHERENCE` : aucun cas attendu.
8. Reconstruire le portail apply (champ `avance`).
9. Ne pas relancer `RolePermissionSeeder` : il n'y a aucune permission à ajouter.
10. Recette avec un agent et un membre du service financier, sur le prochain versement réel :
    - fiche, reçu, espace de l'auditeur ;
    - puis deux saisies lancées en même temps sur deux fiches différentes, et deux sur la même fiche.
11. Le lendemain : `scolarite:verifier-comptes`, sans `--equivalence`, et revue du contrôle interne.

### 15.3 Retour arrière

- Tant que D2 et D3 ne sont pas livrés, le code précédent fonctionne sur la base migrée : il lit `versements.inscription_annuelle_id`, toujours renseigné, et ignore les imputations. Les versements saisis entre-temps restent comptés sur leur inscription désignée, ce qui revient au même tant que chaque auditeur n'a qu'une inscription.
- Le schéma ne se défait plus une fois que le compte a servi (`down()` refusé) : on revient au code précédent, pas au schéma précédent.

## 16. Points restant à trancher

1. **Avance réservée de l'employeur** (§6.2). Elle applique la décision 7. Il reste à décider si le payeur « autre » se traite comme un employeur (réservé) ou comme l'auditeur (libre, proposé ici).
2. **Reçu.** « Total versé pour l'année » ne compte plus l'avance (§9.2, point 5), alors que les reçus antérieurs en trop-perçu la comptaient. À valider avec le service financier.
3. **Listes et exports.** « Versé » y devient l'imputé de l'année. L'avance s'affiche à part : colonne dans l'Excel, pied de liste dans le PDF. À valider avec le service financier pour ses rapprochements.
4. **Ordre de livraison.** La demande d'autorisation d'annulation sur plusieurs années (D4) doit être livrée avant D3 ou avec lui (§2).
5. **Pénalité de l'autorisé à soutenir** (500 000 FCFA, décision 5). À confirmer avant D5.
6. **Responsable du contrôle `CPT_COHERENCE`.** `admin` (USI) est proposé.

## 17. Critères d'acceptation

1. **Versement de 2 320 000 sur un M1 à 1 185 000** :
   - le M1 est soldé et l'avance vaut 1 135 000 ;
   - le reçu porte « … 2e tranche (500 000 FCFA), avance sur la scolarité à venir (1 135 000 FCFA) » et un total versé pour l'année de 1 185 000.
2. **Création d'une inscription suivante** (cas de test) : l'avance s'impute d'elle-même, au barème de cette inscription, sans SMS en D1.
3. **Annulation** :
   - annuler un versement annule ses imputations et réimpute l'avance disponible des autres versements ;
   - annuler un versement imputé sur deux années est refusé, avec un message clair.
4. **Après la migration** :
   - toutes les inscriptions gardent les mêmes paliers, les mêmes états et les mêmes accès ;
   - l'avance est égale à l'ancien trop-perçu ;
   - la vérification en lecture seule ne trouve aucun écart.
5. **Garde-fous** : aucune suite d'événements ne produit d'imputation négative, de sur-imputation, d'imputation sur un versement annulé, ni d'avance libre à côté d'un reste ouvert.
6. **Envois** : la migration n'envoie ni SMS ni e-mail et n'émet aucun reçu.
