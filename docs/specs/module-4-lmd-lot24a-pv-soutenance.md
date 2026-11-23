# Module 4 — LMD, lot 24a : jury et procès-verbal de soutenance

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : décisions du 23/11/2026 et modèle de PV de soutenance remis le même jour (PV ACMA d'avril 2026).

## 1. Ce que dit le Chef USI

- L'UE « Mémoire et stage pratique professionnel » vaut 16 crédits ; sa note est attribuée par le jury de soutenance, à la soutenance.
- Les PV de soutenance des auditeurs autorisés à soutenir sont produits avant la soutenance ; le jury y porte la note, la mention et ses observations, puis signe.
- La présentation peut être allégée par rapport au modèle ACMA.

## 2. Règles

1. **Jury de soutenance.** Le dossier de mémoire porte les membres du jury : président, rapporteur (directeur de recherche), examinateur, encadreur professionnel. Le rapporteur est proposé depuis l'encadreur académique.
2. **PV de soutenance.** Un PV par auditeur, au format A4 : en-tête bilingue de l'Université de Yaoundé II-Soa, Faculté des sciences économiques et de gestion, PSSFP ; civilité, nom et matricule ; diplôme et option ; thème ; année académique ; date de soutenance ; jury avec une case de signature par membre ; note sur 20, mention, observations.
   - Avant la saisie du résultat, note, mention et observations sont des lignes à remplir à la main.
   - Après la saisie, le PV les reprend, pour l'archive.
   - Il se télécharge depuis la fiche du dossier, ou pour plusieurs dossiers à la fois depuis la liste (un PV par page).
3. **Conditions.** Le PV exige le thème, la date de soutenance, le président et le rapporteur. Un dossier suspendu ou abandonné n'a pas de PV. Le dossier n'existe que pour un auditeur autorisé à soutenir.
4. **Résultat.** La scolarité saisit la note du jury (0 à 20) et ses observations ; l'étape « Soutenance » du dossier est validée avec cette note et le dossier passe « Soutenu ». Le résultat exige le jury et la date. Il se corrige par une nouvelle saisie, journalisée.
5. **Mention.** Calculée depuis la note par la grille des mentions de l'année (12 Assez bien, 14 Bien, 16 Très bien, 18 Très bien avec félicitations du jury). Sous 12, aucune mention.
6. **Droits.** Jury, PV et résultat : droit « suivre les mémoires », déjà en place.

## 3. Modèle de données

Migration additive et réversible sur `dossiers_memoire` : `jury_president`, `jury_rapporteur`, `jury_examinateur` (textes facultatifs). La note reste sur l'étape « Soutenance », déjà existante.

## 4. Tests

- PV refusé sans thème, date ou jury ; produit ensuite, vierge avant le résultat, renseigné après.
- Résultat : note hors bornes refusée, étape validée, dossier « Soutenu », mention calculée, geste journalisé.
- PV groupé depuis la liste ; refus pour un compte sans le droit.

## 5. Hors périmètre (lot 24b)

- Report de la note de soutenance sur l'UE de 16 crédits et jury d'admission (AS → AD à 60 crédits).
- Dépôt du PV signé numérisé.
- En-tête des autres PV : ils gardent l'en-tête actuel (Ministère des finances) ; les modèles ACMA portent celui de l'Université. À trancher.
