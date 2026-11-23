# Module 4 — LMD, lot 18 : passage en M2 sur « admis », redoublants, écran des inscriptions

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011, 0013.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, point bloquant B4 et étape 4 du plan validé le 22/11/2026.

## 1. Objectif

Que le passage en Master 2 repose sur la décision du jury, se fasse en une fois pour toute la promotion, et que la Scolarité voie les inscriptions de l'année sur un écran.

## 2. Règles

1. **M2 sur « admis ».** Un auditeur ne s'inscrit en M2 que s'il est admis en M1 : décision « Admis » d'une délibération annuelle arrêtée du M1 sur la plateforme, ou M1 admis dans l'archive ACMA liée. À défaut, la dérogation motivée existante (droit « dérogation M2 », référence de la pièce) reste la seule voie. Avoir seulement suivi le M1 ne suffit plus.
2. **Passage en groupe.** Pour l'année en cours, la Scolarité voit la liste des admis du M1 de l'année précédente qui ne sont pas encore inscrits, et les inscrit en M2 en un geste.
   - L'option de M2 est celle de la spécialité du dossier. Un admis sans option reconnue n'est pas inscrit : il figure dans le bilan, à traiter depuis sa fiche.
   - Sont écartés : dossier retiré, auditeur qui n'est pas « actif », auditeur déjà inscrit dans l'année.
   - Chaque inscription suit le chemin habituel (barème de l'année, avance imputée, inscription pédagogique, journal). L'échec d'une inscription n'annule pas les autres ; le bilan donne les comptes et les motifs.
   - Un aperçu précède toujours le geste. Même droit que la réinscription d'un auditeur.
3. **Redoublant.** À l'inscription pédagogique, une UE déjà acquise une année antérieure au même niveau est posée « Acquise (capitalisée) » : l'auditeur n'apparaît plus dans les listes de notes de cette UE, et la délibération reprend la note acquise (comportement déjà en place au calcul).
4. **Écran « Inscriptions ».** Liste en lecture seule des inscriptions annuelles : matricule, nom, année, niveau, parcours, nombre d'UE (dont capitalisées), statut de l'auditeur. Filtres : année (l'année en cours par défaut), niveau, parcours, « sans UE ». Le passage en groupe se lance depuis cet écran.
5. **Année en cours.** Depuis l'écran « Inscriptions », le responsable de la scolarité change l'année en cours (réglage `scolarite.annee_en_cours`, aujourd'hui modifiable seulement par la configuration du serveur). Le geste demande une confirmation, n'accepte que l'année suivante ou précédente, exige un barème saisi pour la nouvelle année, et est journalisé.

## 3. Modèle de données

Aucune migration.

## 4. Tests

- M2 refusé à un M1 suivi mais ajourné ou non délibéré ; accepté à un admis ; accepté sur dérogation.
- Une délibération de semestre ne vaut pas admission.
- Passage en groupe : aperçu, inscription des admis, bilan des écartés, rejouable sans doublon.
- Redoublant : UE acquises capitalisées, absentes de la liste de saisie.
- Écran : visible de la scolarité, refusé aux autres rôles.

## 5. Hors périmètre, à décider

- Effet des statuts « abandon » et « exclu » sur les listes de notes et les délibérations : aujourd'hui informatifs. À trancher avec la Scolarité (les sortir des listes, ou les garder au PV comme défaillants).
- Équivalences, VAE, transferts.
