# Module 4 — LMD, lot 17a : délibération de semestre, PV et relevé semestriels

**Date** : 2026-11-22. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : audit `docs/audits/lmd-gestion-academique-2026-11.md`, étape 3 du plan validé le 22/11/2026 (« année académique, semestres, PV et relevés semestriels »). L'étape est découpée : ce lot traite le semestre, le lot 17b l'objet « année académique ».

## 1. Objectif

Pouvoir délibérer sur un seul semestre, et en tirer un PV et un relevé de notes du semestre, sans attendre la fin de l'année.

## 2. Règles

1. **Portée.** Une délibération porte sur l'année entière (comme aujourd'hui) ou sur un semestre du niveau : 1 ou 2 en M1, 3 ou 4 en M2. Le semestre est enregistré par son rang dans le master (1 à 4) ; l'affichage suit la numérotation de la promotion (S1 ou S7).
2. **Population et UE.** Une délibération de semestre ne retient que les UE de ce semestre, et les auditeurs qui y sont inscrits. Préalables, scénarios, mesures du jury, séance, arrêt, signatures, publication et rectification sont inchangés.
3. **Résultat du semestre.** Crédits acquis, moyenne, MGP, rang et mention sont calculés sur les UE du semestre. La décision est « Semestre validé » quand tous les crédits du semestre sont acquis, sinon « Semestre non validé ». Elle ne décide ni du passage ni de l'autorisation de soutenir : seule la délibération annuelle le fait.
4. **Une UE acquise le reste.** La délibération de l'année reprend comme acquises, avec leur note, les UE acquises à ses délibérations de semestre ; la délibération de rattrapage reprend celles de la session normale de même portée. Une mesure du jury n'a donc pas à être répétée. Une délibération de session normale ne reprend jamais un acquis du rattrapage. Le PV montre l'écart avec l'application stricte des règles et cite le PV d'origine. Si une délibération reprise est arrêtée après le calcul du scénario retenu, l'arrêt exige un nouveau calcul.
5. **Une seule délibération arrêtée** par année, niveau, parcours, session et portée (semestre ou année).
6. **Gel des notes** (lot 16a). Une délibération de semestre ne gèle que les notes des UE de son semestre. Le marquage « à rectifier » par une requête et le contrôle `NOT_APRES_ARRET` suivent la même portée. Le gel se lève aussi pendant la rectification d'une délibération qui reprend celle-ci.
7. **Rectification.** Une délibération ne se rectifie plus quand une délibération arrêtée reprend ses résultats (l'année pour un semestre, le rattrapage pour la session normale) : la correction passe par la rectification de celle-ci. À son arrêt, le marquage « à rectifier » des délibérations reprises est levé.
8. **Documents.**
   - PV et liste d'affichage : le titre nomme le semestre, les décisions s'écrivent « Semestre validé / non validé ». Archivage et QR code comme au lot 16a.
   - Relevé de notes du semestre : même gabarit que le relevé annuel, titré « Relevé de notes du semestre N », numéroté, scellé et vérifiable.
   - L'attestation de réussite reste réservée à la délibération annuelle.
9. **Mémoires.** Seule une délibération annuelle du M2 ouvre les dossiers de mémoire.
10. **Espace auditeur.** Les résultats publiés d'un semestre s'affichent avec leur semestre, leur décision et le relevé du semestre.

## 3. Modèle de données

Migration additive et réversible : `deliberations.semestre` (entier, facultatif, 1 à 4, cohérent avec le niveau). Aucune autre table ne change : les résultats d'un semestre s'enregistrent comme ceux d'une année, avec les décisions `AD` (validé) et `AJ` (non validé).

## 4. Tests

- Une délibération de semestre ne calcule que les UE de son semestre ; décision validé / non validé.
- Un semestre hors du niveau est refusé ; deux délibérations arrêtées de même portée aussi.
- L'UE acquise au semestre par mesure du jury reste acquise à la délibération annuelle.
- Le gel ne touche que les ECUE du semestre délibéré.
- La rectification d'un semestre est refusée après l'arrêt de la délibération annuelle.
- Relevé du semestre délivré, attestation refusée, mémoires refusés.
- L'API de l'espace auditeur expose le semestre.

## 5. Hors périmètre

- Objet « année académique » et ses semestres datés (lot 17b).
- Validation ECUE par ECUE (question ouverte avec la Scolarité).
- Semestre 4 du M2 : la soutenance pèse 16 crédits sur 30, donc le semestre sort « non validé » pour tous avant la soutenance. À trancher avec la Scolarité (décider sur les crédits écrits, ou ne pas délibérer ce semestre seul).
- Test Playwright de l'affichage d'un résultat de semestre : le jeu d'essai du portail n'a pas de délibération de semestre.
- Correction à la baisse d'une UE acquise au semestre une fois l'année arrêtée.
