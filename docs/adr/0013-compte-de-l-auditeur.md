# ADR-0013 — Compte de l'auditeur : versements rattachés à l'auditeur et imputations enregistrées

**Statut** : Proposé (à passer en « Accepté » à la fusion de la PR #122)
**Date** : 2026-11-15
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026 sur la proposition du lot D)
**Référence** : [proposition du lot D](../specs/module-4-scolarite-comptes-auditeur-proposition.md) (§3, §4 et §8) ; [spec du sous-lot D1](../specs/module-4-scolarite-comptes-d1.md) ; [ADR-0010](0010-vie-academique-auditeurs-phase-1.md), point 3, qu'il précise ; [ADR-0011](0011-lmd-pssfp-systeme-dans-la-plateforme.md), point 5 ; dossier « PSSFP Système », décisions D7 et D8

## Contexte

Depuis le lot 1 de la Scolarité (en production depuis le 05/11/2026), un versement appartient à **une** inscription annuelle (`versements.inscription_annuelle_id`, NOT NULL). La situation financière de l'inscription se recalcule à chaque lecture à partir de ses versements non annulés et de sa remise (ADR-0010, point 3). Il en découle trois limites, toutes constatées dans le code :

- **L'argent versé d'avance reste collé à l'année de sa saisie.** Au-delà du dû, il est affiché comme « trop-perçu » et n'est reporté nulle part. Un auditeur qui paie ses deux années en une fois devrait faire annuler son versement et le ressaisir en deux, ce que l'index unique des références bancaires refuse.
- **Une dette disparaît des écrans dès que l'année suivante s'ouvre.** Liste, relances, export, espace et accès FOAD ne lisent que l'inscription courante. Le dossier « PSSFP Système » (D7) demande au contraire un reste à payer sur tout le parcours.
- **Le verrou porte sur l'inscription.** Il ne protège donc pas une écriture qui toucherait deux années.

Contraintes :

- Le code d'argent est en production depuis dix jours.
- Les reçus sont des pièces immuables, protégées par des déclencheurs (lot 4).
- La séparation des tâches et le journal scellé (lot 14d-1) doivent être conservés.
- L'accès FOAD se déduit de la situation, y compris le déblocage anticipé du lot A1 (fusionné le 15/11/2026).
- Volume : environ 600 auditeurs et 2 000 versements par an. Chaque auditeur n'a aujourd'hui qu'une inscription.

Le 15/11/2026, le chef de projet a retenu les options recommandées de la proposition. Un versement solde d'abord les arriérés, puis l'année en cours, puis devient avance. L'avance s'impute à l'ouverture de l'année suivante, au barème de cette année. L'excédent d'un employeur se décide au cas par cas, et aucun document n'est émis à l'imputation automatique d'une avance (décisions 1 à 13, proposition §8).

## Décision

1. **Le compte, c'est l'auditeur.** Un versement se rattache à l'auditeur (`versements.auditeur_id`, NOT NULL). Il n'y a pas de table `comptes` : la ligne `auditeurs` identifie le compte et porte le verrou de toute écriture d'argent.
2. **Le dû reste porté par les inscriptions annuelles.** Les montants copiés du barème, la remise unique et les paliers (inscription, 1re tranche, 2e tranche) ne changent pas.
3. **Chaque part d'un versement qui règle une inscription est une imputation enregistrée** (`imputations_versements`). Une imputation est un fait (« telle part de tel versement règle telle inscription »), et non un total en cache. Elle n'est jamais modifiée ni supprimée : on l'annule, comme un versement.
4. **L'avance est calculée, jamais stockée :** c'est la différence entre les versements valides et leurs imputations actives. L'avance d'un versement d'employeur est **réservée** : elle ne s'impute d'office que sur l'année que ce versement désigne.
5. **La situation d'une inscription reste calculée,** par le même calcul qu'aujourd'hui, avec pour cumul la somme de ses imputations actives. C'est la précision apportée au point 3 de l'ADR-0010 : la situation se recalcule à partir des imputations actives et de la remise. Les versements et les imputations sont des faits enregistrés ; rien d'autre n'est stocké.
6. **Un seul écrivain, des règles déterministes.** Les imputations sont posées par un seul service, `ImputationsCompte`. Il applique un planificateur pur, sans base ni horloge. Les règles sont les arriérés d'abord, puis l'année en cours, puis l'avance. L'avance s'impute à chaque événement qui ouvre un reste : création d'inscription, changement de remise, annulation. Le planificateur sert aussi à prévoir, sans rien écrire, l'effet d'un geste sur l'accès.
7. **La base garantit ce qu'elle seule peut garantir.** En plus des exceptions des ADR-0010 et 0011, le SQL brut est autorisé pour :
   - les contraintes `CHECK` des imputations ;
   - leurs index partiels ;
   - un déclencheur d'immuabilité (seule l'annulation est permise, une fois) ;
   - un déclencheur de contrainte, qui vérifie à la fin de chaque instruction qu'aucun versement n'est imputé au-delà de son montant ou après son annulation, et qu'aucune inscription ne l'est au-delà de son dû. Il est reportable (`DEFERRABLE INITIALLY IMMEDIATE`) mais vérifié tout de suite par défaut : le service ordonne ses écritures pour que chaque instruction laisse un compte cohérent ;
   - les expressions SQL de l'imputé et de l'avance utilisées par les filtres et les listes.

   Des clés étrangères composites (`auditeur_id` avec le versement, avec l'inscription) interdisent d'imputer un versement sur l'inscription d'un autre auditeur. Elles s'expriment avec le Blueprint de Laravel, sans SQL brut.
8. **Les reçus restent immuables.** L'objet d'un nouveau reçu est lu dans les imputations du versement au moment de l'émission, et l'excédent y est libellé « avance sur la scolarité à venir ». Les reçus déjà émis ne changent pas.
9. **Livraison par sous-lots déployables seuls :**
   - D1 : socle et reprise des versements existants ;
   - D2 : compte visible ;
   - D3 : ouverture de l'année suivante ;
   - D4 : affectation, réimputation et annulations sur plusieurs années ;
   - D5 : remboursements et autres frais.

## Conséquences

### Positives

- L'argent versé d'avance n'est plus dénaturé. Il s'impute de lui-même quand l'année suivante s'ouvre, sans geste humain qu'on pourrait oublier.
- Une dette d'une année passée reste lisible et comptée : arriérés, reste sur le parcours.
- Chaque mouvement est daté, a une origine et, s'il y a lieu, un auteur. Le relevé de compte (D2) se lit directement dans les tables.
- Le résultat est **stable dans le temps** : une remise ou une annulation sur une année ancienne ne déplace pas d'argent sans événement tracé (contrairement à une imputation recalculée à la volée).
- Le changement de `SituationFinanciere` et des scopes est minimal : seule la source du cumul change. Pour les données existantes, la situation de chaque inscription reste identique, ce qui se démontre (spec D1, §9.1) et se vérifie dans la migration.
- Le modèle s'étend sans refonte : affectation accordée, réimputation manuelle, remboursement d'une avance ou frais hors barème deviennent de nouvelles cibles ou de nouvelles origines d'imputation.

### Négatives ou trade-offs

- Une table d'argent de plus doit rester cohérente avec les versements et les inscriptions. Tout écrivain d'argent doit passer par `ImputationsCompte` : registre, ouverture d'inscription, reprise du pointage, et aussi fabriques et assistants de test. Un écrivain oublié laisserait un versement sans imputation.
- « Versé » change de sens pour une inscription en trop-perçu : c'est désormais l'imputé, au plus le dû, et l'excédent s'affiche à part en avance. Les reçus émis avant D1 comptaient le trop-perçu dans leur « total versé pour l'année », les suivants non.
- Le service doit respecter un ordre d'écriture : désimputer avant de baisser un dû, annuler les imputations avant le versement. Un ordre inversé échoue aussitôt, ce qui est voulu.
- Toutes les écritures d'argent d'un même auditeur sont sérialisées par son verrou. C'est négligeable aux volumes du PSSFP, mais ce verrou doit être pris le premier, partout.
- Le SQL brut s'étend : deux fonctions PL/pgSQL et quatre déclencheurs de plus, à maintenir avec le schéma. Un contrôle vérifié à chaque instruction coûte deux sommes indexées par écriture : c'est négligeable à ces volumes.

### Neutres ou à surveiller

- « L'année en cours » reste l'inscription de l'année la plus récente. Une inscription créée trop tôt deviendrait courante et pourrait fermer l'accès de l'année réelle : D3 refusera toute création avant la date d'ouverture de l'année. À terme, le calendrier académique (lot 12a) devra définir l'année en cours.
- L'avance réservée d'un employeur attend une décision du responsable (D4). D'ici là, elle reste au compte et le contrôle interne la rend visible.
- Une annulation qui touche plusieurs années est refusée tant que la demande d'autorisation de D4 n'existe pas. Ce cas n'apparaît qu'avec D3 : D4 doit donc précéder D3, ou l'accompagner.
- Les avances dormantes (abandon, exclusion, diplôme) devront être suivies (contrôle prévu en D4) puis remboursées (D5).

## Alternatives envisagées

- **Grand livre en partie double par auditeur** (écritures de dû et de crédit, lettrage) : c'est la cible comptable idéale et la lecture littérale de D7. Mais cette option réécrit d'un coup du code d'argent en production et double les montants déjà portés par l'inscription (lettre d'admission, LMD, barèmes). L'apport du lettrage est repris par les imputations, à la granularité de l'inscription.
- **Avoir reportable d'une inscription à la suivante :** c'est la plus petite modification, mais elle ne règle ni les arriérés, ni le redoublement. Elle dépend d'un report bien déclenché, et une annulation après report rend le cumul de l'année source négatif.
- **Imputation calculée à la volée** (rien de stocké, premier versé, premier imputé) : élégante, mais **instable dans le temps**. Une remise accordée tard sur une année ancienne déplacerait de l'argent vers l'année suivante et pourrait fermer un accès sans que personne l'ait voulu. Un reçu figé pourrait alors contredire la situation, et une affectation demandée par le payeur ne pourrait pas s'y exprimer.
- **Stocker la situation de chaque inscription** (cumul, reste) : écarté par l'ADR-0010, car ce serait une donnée en double sur de l'argent.
- **Créer l'inscription de l'année suivante dès le paiement d'avance :** elle deviendrait aussitôt « courante », fermerait l'accès de l'année en cours et ferait basculer le parcours et l'emploi du temps de l'espace. L'argent attend donc en avance.

## Références

- `docs/specs/module-4-scolarite-comptes-auditeur-proposition.md` : besoin, scénarios S1 à S5, comparaison des approches, décisions du 15/11/2026.
- `docs/specs/module-4-scolarite-comptes-d1.md` : schéma, règles R1 à R9, reprise, tests, déploiement.
- `docs/specs/module-4-scolarite-lot1-2026-11.md` (§3, §4, §9, §10), `docs/specs/module-4-scolarite-lot4-recus.md`, `docs/specs/module-4-scolarite-acces-anticipe.md`.
- `docs/specs/module-4-lmd-feuille-de-route.md`, §4 (écarts D7 et D8).
