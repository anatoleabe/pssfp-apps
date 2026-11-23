# Module 4 — LMD, lot 24c : jury d'admission du Master 2

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : décisions du 23/11/2026 et PV finaux signés de la promotion 11 (« PV finaux des résultats - P11. M2 ») : après les soutenances, un jury prononce l'admission sur les 60 crédits ; la note de soutenance est celle de l'UE « Stage pratique professionnel et mémoire » (16 crédits).

## 1. Objectif

Prononcer « Admis » en Master 2 après les soutenances, sans ressaisir de note : la note du PV de soutenance devient celle de l'UE de soutenance.

## 2. Règles

1. **Une délibération à part.** Le jury d'admission est une délibération annuelle du M2, de session « Jury d'admission ». Elle n'existe ni en M1 ni pour un semestre. Une seule arrêtée par année et par parcours ; une correction passe par sa rectification.
2. **Préalable.** Une délibération annuelle des écrits (session normale ou rattrapage) doit être arrêtée pour la même année et le même parcours.
3. **Population.** Les auditeurs « autorisés à soutenir » par une délibération annuelle arrêtée des écrits. Les ajournés, les abandons et les exclus n'y figurent pas.
4. **UE écrites.** Reprises comme acquises, avec leur note, des délibérations arrêtées de l'année (règle 4 du lot 17a). Rien n'est recalculé.
5. **UE de soutenance.** Sa note est celle du résultat de soutenance enregistré au dossier de mémoire (lot 24a). Elle est acquise à partir du seuil de validation de l'année. Sans résultat enregistré, ou dossier suspendu ou abandonné, l'UE n'est pas acquise.
6. **Décision.** « Admis » (AD) à 60 crédits ; sinon l'auditeur reste « Autorisé à soutenir » (AS) et le préalable le signale. Moyenne, MGP, mention et rang portent sur l'année entière, soutenance comprise.
7. **Gel.** Une fois le jury d'admission arrêté, le résultat de soutenance ne se modifie plus ; les délibérations des écrits ne se rectifient plus (la correction passe par la rectification du jury d'admission).
8. **Documents.** PV titré « Procès-verbal du jury d'admission » ; relevé et attestation de réussite délivrés sur cette délibération ; l'espace auditeur affiche « Jury d'admission ». Elle n'ouvre pas de dossier de mémoire.

## 3. Modèle de données

Migration additive et réversible : la session `admission` est admise sur `deliberations` (M2, année entière seulement) et sur `acquis_ue`.

## 4. Tests

- Création refusée en M1 et pour un semestre.
- Préalable bloquant sans délibération des écrits arrêtée.
- Un autorisé à soutenir avec résultat de soutenance sort admis à 60 crédits ; l'UE de soutenance porte la note du jury ; les UE écrites gardent leur note.
- Sans résultat de soutenance : reste AS, signalé. Un ajourné ne figure pas.
- Après l'arrêt : résultat de soutenance figé, rectification des écrits refusée.

## 5. Hors périmètre, à décider

- PV de synthèse par UE (une colonne par UE, total des points, crédits, moyenne, décision) : lot 24d.
- Moyenne : les PV ACMA divisent le total des points par le nombre d'UE ; la plateforme pondère par les crédits (ADR-0011). À trancher.
- Soutenance tardive après l'arrêt du jury d'admission : par rectification.
