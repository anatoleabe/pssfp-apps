# Module 4 — LMD, lot 5 : notes

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : décisions D10, D11, D12 et D16 ; RG-010 à RG-017 ; critères SAI-01 à SAI-07 du dossier « PSSFP Système ».

## 1. Objectif

L'USI et les enseignants saisissent les notes ECUE par ECUE et étape par étape. Une note ne change plus une fois son étape verrouillée, et chaque modification est tracée. La plateforme calcule les moyennes et l'éligibilité au rattrapage. Les auditeurs ne voient leurs notes qu'après la publication des résultats (lots 6 et 7).

## 2. Modèle

| Table | Contenu |
|---|---|
| `etapes_saisie` | Une ligne par ECUE, session et étape. Étapes : CC, assiduité, examen ; le rattrapage n'a que l'examen. États : fermée, ouverte, verrouillée. Verrouillage et réouverture tracés (auteur, date, motif). |
| `notes_ecue` | Une ligne par inscription pédagogique, ECUE et session : CC, assiduité, examen (entre 0 et 20), présence à l'examen, moyenne calculée |
| `historique_notes` | Ajout seul, garanti par la base : champ, ancienne et nouvelle valeur, motif, auteur, date |
| `derogations_rattrapage` | Autorité, référence et date de l'acte, motif |
| `attributions_ecue` | ECUE confiés à un enseignant (compte du back-office) : responsable ou intervenant |

## 3. Règles

1. **Moyenne d'un ECUE** = CC × pondération CC + assiduité × pondération assiduité + examen × pondération examen. Les pondérations sont celles de l'UE dans la maquette (RG-010) ; une note manquante compte pour 0 dans la moyenne affichée, signalée « incomplète ».
2. **Note d'une UE** = moyenne de ses ECUE pondérée par leurs crédits. Même calcul pour l'examen de l'UE, qui porte la note éliminatoire (règle de l'année).
3. **UE acquise** : note au moins égale au seuil (12) et pas de note éliminatoire (examen sous 7). Calcul provisoire jusqu'à la délibération (lot 6), qui arrête les acquis.
4. **Rattrapage**, selon la règle de l'année. La note de rattrapage d'un ECUE :
   - remplace sa moyenne (2026-2027) ;
   - ou remplace seulement son examen ;
   - ou n'est retenue que si elle est meilleure.

   Un ECUE sans note de rattrapage garde sa note de session normale.
5. **Éligibilité au rattrapage** : UE non acquise en session normale, avec CC, examen et assiduité renseignés pour tous ses ECUE (quand l'assiduité est pondérée). À défaut, une dérogation consignée est nécessaire (D11).
6. **Saisie par étapes** (D11) :
   - l'étape doit être ouverte ;
   - une étape verrouillée refuse toute modification ;
   - une valeur déjà saisie ne se change qu'avec un motif ;
   - chaque changement entre à l'historique.
7. **Verrouillage** (permission `lmd.notes_verrouiller`) : refusé tant qu'un auditeur inscrit n'a pas de note pour l'étape. Pour l'examen, l'absence se saisit (« absent justifié » ou « absent non justifié ») au lieu d'une note.
8. **Réouverture** (permission `lmd.notes_rouvrir`, responsable de la scolarité) : avec un motif, journalisée. Elle passera par les demandes d'autorisation au lot 14 (D31).
9. **Enseignant** : il saisit le CC et l'assiduité des seuls ECUE qui lui sont attribués (permission `lmd.notes_enseignant`). L'examen est saisi par la scolarité, puis, au lot 9, sur numéros d'anonymat.
10. **Import Excel** : modèle généré par la plateforme pour un ECUE, une session et une étape, avec matricule, nom et note actuelle. Au retour :
    - les lignes sont contrôlées : matricule inscrit, note entre 0 et 20, motif si la valeur change ;
    - l'import se fait en essai à blanc, puis s'applique avec les mêmes règles qu'à l'écran.

## 4. Écrans

- **Saisie des notes** : choix de l'année, de la maquette, de l'ECUE, de la session et de l'étape. Grille des auditeurs inscrits, avec matricule et nom, la note et la présence à l'examen. Actions :
  - ouvrir, verrouiller, rouvrir ;
  - télécharger le modèle Excel, importer.
- **Suivi** : par maquette, état de chaque étape de chaque ECUE et nombre de notes manquantes.
- **Fiche auditeur** : notes de l'année par UE et ECUE, moyennes, acquis provisoires et éligibilité au rattrapage.
- **Attributions** : ECUE par enseignant.

## 5. Permissions

| Permission | Rôle |
|---|---|
| `lmd.notes_saisir` | Agent et responsable de scolarité : saisie de toutes les notes d'une étape ouverte |
| `lmd.notes_verrouiller` | Responsable : ouverture et verrouillage des étapes |
| `lmd.notes_rouvrir` | Responsable : réouverture motivée |
| `lmd.notes_enseignant` | Nouveau rôle `enseignant` : CC et assiduité de ses ECUE |
| `lmd.derogation_rattrapage` | Responsable : dérogation à l'éligibilité au rattrapage |

## 6. Critères d'acceptation

1. SAI-01 et SAI-02 : une modification du CC dans une étape ouverte est acceptée et historisée avec son motif.
2. SAI-03 et SAI-04 : une modification refusée quand l'étape est verrouillée ou fermée, avec un message clair.
3. L'historique ne se modifie ni ne se supprime, même directement en base.
4. Les moyennes d'ECUE et d'UE suivent les règles 1 et 2. La note éliminatoire et les trois modes de rattrapage sont testés.
5. Un enseignant ne voit et ne saisit que ses ECUE, et jamais l'examen.
6. L'import refuse un matricule non inscrit, une note hors bornes, ou un changement sans motif, sans rien écrire.
