# ADR-0011 — Le LMD complet (dossier « PSSFP Système ») construit dans la plateforme Laravel

**Statut** : Accepté
**Date** : 2026-11-07
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (demande du 07/11/2026 : « un vrai logiciel de LMD accessible à tous »)
**Référence** : dossier `NewACMA/PSSFP_Systeme_package_2026-11-07` (cahier des charges du 07/11/2026, décisions D1 à D38, registre V-01 à V-21) ; ADR-0001 ; ADR-0010

## Contexte

Le dossier « PSSFP Système » spécifie le remplacement d'ACMA (WinDev) : inscriptions, maquettes, notes par étapes, anonymat, délibérations, requêtes, documents vérifiables, mémoires, assiduité, calendrier, paie des enseignants, supervision. Il livre un schéma PostgreSQL de 136 tables, 288 fonctions et 100 tests, mais pas de serveur d'application ni d'écrans. Il suppose une pile NestJS et React, un schéma multi-établissement cloisonné par RLS, et la logique métier dans des fonctions PL/pgSQL.

La plateforme pssfp.org a déjà, depuis les lots 1 à 3 du module 4, les auditeurs, leurs inscriptions annuelles, leurs versements, le back-office Filament et l'espace de l'auditeur. Les 297 auditeurs de la P13 et les 239 de la P14 y sont.

## Décision

1. **Le dossier « PSSFP Système » est le cahier des charges fonctionnel du LMD.** Ses règles (RG-001 et suivantes), ses décisions D1 à D38 et ses paramètres par défaut (V-01 à V-14) s'appliquent tels quels, sauf quand le Chef USI a donné une règle plus récente (voir la feuille de route, section 3).
2. **Il est construit dans la pile existante (ADR-0001), pas en NestJS :** API et back-office Laravel 11 et Filament 3, espace de l'auditeur dans l'application `candidature`. Un deuxième serveur doublerait l'authentification, les droits, les déploiements et l'espace auditeur.
3. **Un seul établissement :** pas de colonne `etablissement_id` ni de RLS. Le cloisonnement passe par les modules et les permissions Spatie, comme ailleurs.
4. **La logique métier est en PHP, dans `App\Services\Lmd`,** et testée en Pest. Les fonctions PL/pgSQL du dossier servent de spécification.
5. **La base garde ce qu'elle seule peut garantir.** SQL brut autorisé, en plus des exceptions de l'ADR-0010 :
   - contraintes `CHECK` (notes entre 0 et 20, pondérations à 100 %, états) ;
   - index uniques partiels ;
   - déclencheurs qui refusent la modification et la suppression des tables en ajout seul : historique des notes, résultats arrêtés, PV, événements des requêtes, journal des décisions.
6. **Livraison par lots** déployables l'un après l'autre (feuille de route : `docs/specs/module-4-lmd-feuille-de-route.md`).

## Conséquences

### Positives

- Un seul compte, une seule fiche et un seul espace pour l'auditeur, du paiement aux résultats.
- Les décisions déjà validées par la Scolarité sont reprises sans être rediscutées.

### Négatives ou trade-offs

- Le portage des 288 fonctions demande du temps : il se fait lot par lot, avec les tests du dossier comme critères d'acceptation.
- Les tests SQL du dossier ne se rejouent pas tels quels. Ils sont réécrits en Pest.

### Neutres ou à surveiller

- La reprise de l'historique d'ACMA (copie MySQL) n'entre pas dans les premiers lots. Elle exige la copie de la base et les arbitrages de la Scolarité (36 redoublants, 406 décisions, 16 doublons).
- Les textes marqués « NON VÉRIFIÉ » (règlement des études, textes LMD, taux de paie) restent à fournir. Les valeurs sont des paramètres modifiables, journalisés.

## Alternatives envisagées

- **Installer le schéma PostgreSQL tel quel et écrire un serveur NestJS :** fidèle au dossier, mais deux applications, deux authentifications et deux espaces pour l'auditeur.
- **Installer le schéma tel quel sous Laravel et appeler ses fonctions :** la logique resterait hors de portée des tests Pest et des politiques d'accès. Le cloisonnement RLS par établissement serait inutile ici.

## Références

- `NewACMA/PSSFP_Systeme_package_2026-11-07/1_documents_principaux/CAHIER_DES_CHARGES.pdf`
- `NewACMA/PSSFP_Systeme_package_2026-11-07/2_registres/DECISIONS_REGLES.md`, `REGISTRE_DECISIONS_A_VALIDER.md`
- `docs/specs/module-4-lmd-feuille-de-route.md`
