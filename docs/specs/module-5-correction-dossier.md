# Correction des informations d'un dossier par l'administrateur

> Demande du chef de projet du 24/11/2026 : l'administrateur doit pouvoir corriger les informations d'un auditeur (e-mail, date et lieu de naissance, etc.).

## 1. Constat

Le dossier d'un auditeur est « accepté ». Le formulaire « Modifier » des candidatures refuse tout dossier décidé, sauf au super administrateur, et y verrouille l'e-mail pour tout le monde. Le téléphone de connexion ne se corrigeait que pour les retenus ajoutés depuis l'admin.

## 2. Décisions

| Question | Décision |
|---|---|
| Qui corrige | L'administrateur et le super administrateur (`candidature.dossier_corriger`, migration additive). |
| eCampus | Le compte eCampus suit la correction du nom, du prénom et de l'e-mail. |
| Téléphone de connexion | Corrigeable par l'administrateur pour tous les dossiers, y compris ceux déposés sur le portail. |

## 3. « Modifier les informations »

Le bouton se trouve sur la fiche auditeur (menu Dossier), sur la fiche candidature et sur la ligne de la liste des candidatures. La fenêtre a deux étapes.

1. **Informations**, préremplies :
   - identité : civilité, nom, prénom, nom de naissance, date et lieu de naissance, genre, situation matrimoniale, nationalité ;
   - contact : e-mail, téléphones secondaires ;
   - résidence : pays d'origine et de résidence, région et département (Cameroun), ville, adresse.
2. **Vérification** : un tableau « avant / après » des seuls champs changés, puis le motif, obligatoire.

Restent hors de ce formulaire : le matricule, le statut, les choix pédagogiques, les diplômes et les pièces. Le téléphone de connexion se corrige par « Corriger le téléphone ».

## 4. Règles (`CorrectionDossier`)

- **Validation** :
  - nom, prénom, date et lieu de naissance, e-mail obligatoires ;
  - personne majeure ;
  - pays, région et département pris dans les référentiels ;
  - hors du Cameroun, la région et le département sont vidés.
- **Refus** : sans motif, ou si aucune information n'a changé.
- **Journal** (`dossier_corrige`) : le motif, et pour chaque champ l'ancienne et la nouvelle valeur ; les téléphones secondaires sont masqués.
- **Compte de connexion** : il reçoit le nouveau nom affiché et la nouvelle date de naissance, sauf s'il porte d'autres rôles que celui de candidat. La date de naissance corrigée sert aussitôt au contrôle du lien de la lettre d'accès.
- **eCampus** (`IdentiteEcampus`, `core_user_update_users`) :
  - il n'est appelé que pour un dossier qui a un matricule et dont le nom, le prénom ou l'e-mail a changé ;
  - seul le compte qui porte l'identifiant de l'auditeur est touché, et seulement s'il porte aussi le matricule, ou l'ancien ou le nouvel e-mail du dossier ;
  - un e-mail déjà pris par un autre compte eCampus est signalé, et le compte reste tel quel ;
  - en cas de refus ou de panne, la correction reste faite dans le portail et l'administrateur en est averti. Le résultat est journalisé (`dossier_corrige_ecampus`).
- **Documents déjà remis** (lettre d'admission signée, reçus, attestations) : ils ne changent pas. Ceux produits ensuite portent les nouvelles informations.

## 5. Téléphone de connexion

`AjoutRetenu::corrigerTelephone` accepte désormais tout dossier pour un titulaire de `candidature.dossier_corriger`. Le seul droit `candidature.ajouter_retenu` reste limité aux retenus ajoutés depuis l'admin. Le mécanisme ne change pas : l'ancien numéro perd l'accès à l'espace, et le matricule comme le mot de passe FOAD restent les mêmes.
