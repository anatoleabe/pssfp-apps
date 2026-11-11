# Module 4 — LMD, lot 14d-2 : échecs de connexion, verrouillage et déverrouillage des comptes

**Date** : 2026-11-11. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D30 du 30/10/2026 (« journal des connexions (succès, échecs, adresse) et verrouillage du compte après 5 échecs consécutifs (paramètre) »), décision D31 (demande de déverrouillage d'un compte) ; `sql/19_schema_supervision.sql` (contrôle `SEC_CONNEXIONS`).

## 1. Objectif

Qu'un mot de passe du personnel ne puisse pas être deviné par essais répétés, et que toute tentative suspecte soit visible du contrôle interne.

## 2. Règles

1. **Journal** : les connexions, déconnexions et échecs au back-office sont déjà journalisés (journal `connexions` : identifiant saisi, adresse, navigateur ; jamais le mot de passe). S'y ajoutent le verrouillage, le refus d'un compte verrouillé et le déverrouillage.
2. **Compteur** : chaque échec sur un compte du personnel incrémente son compteur d'échecs consécutifs, et une connexion réussie le remet à zéro. Les comptes candidats n'entrent pas dans ce compteur : ils ont leur propre limite (lots candidature).
3. **Verrouillage progressif** : au 5e échec consécutif, le compte est verrouillé. Le seuil est un paramètre (`securite.echecs_avant_verrouillage`, de 3 à 20, 5 par défaut).
   - Les deux premiers verrous d'une période de 24 heures expirent seuls : 15 minutes, puis 1 heure. Le troisième attend un administrateur, qui est alors prévenu dans la messagerie.
   - Pourquoi (revue de sécurité) : avec un verrou définitif, n'importe qui connaissant l'adresse d'un administrateur bloquerait l'administration en cinq essais. Le verrou temporaire freine la recherche du mot de passe sans offrir ce levier.
   - Les échecs ne sont plus comptés pendant un verrou.
   - Un compte verrouillé n'ouvre plus le back-office. La connexion le refuse **avant** de vérifier le mot de passe, et en temps constant : sinon la réponse, plus rapide avec le bon mot de passe, permettrait de le deviner pendant le verrou. Le message reste celui d'un échec ordinaire.
   - Les sessions déjà ouvertes sont refusées à la requête suivante.
4. **Levée d'un verrou manuel** :
   - par un titulaire de `update_user`, depuis l'écran des comptes, avec un motif ;
   - ou par une demande d'autorisation (lot 14c, type « Déverrouillage d'un compte », 8 h, sans visa), qu'un collègue soumet pour le compte verrouillé ;
   - ou, en secours, par l'exploitant du serveur : `php artisan comptes:deverrouiller {email} --motif="…"`.

   Dans les trois cas, l'ancien mot de passe est remplacé, et un lien de réinitialisation part au titulaire. Un mot de passe deviné pendant le verrou ne sert donc plus, et les sessions ouvertes tombent.
5. **Contrôle `SEC_CONNEXIONS`** (majeur, 1 jour, administrateur) :
   - chaque compte verrouillé ;
   - chaque identifiant ou adresse avec au moins 10 échecs sur les dernières 24 heures (tentative suspecte, compte existant ou non). Au plus 20 anomalies par critère ; au-delà, une seule anomalie les regroupe.
6. **Routes HTTP de Fortify fermées** : aucune application ne les appelle. Ouvertes, elles offraient une inscription publique et une seconde page de connexion qui échappait au contrôle du verrou. La route `login` reste nommée, et redirige vers la connexion du back-office.
7. Le contrôle `SEC_COMPTES` (comptes inactifs depuis 90 jours) reste inchangé.
8. **Constat, hors de ce lot** : la double authentification n'est pas en service. Aucun compte du personnel ne l'a configurée en production, le back-office n'a pas d'écran pour le faire, et la connexion ne demande pas de code. Elle fait l'objet d'un lot à part (14d-4).

## 3. Modèle de données

`users` reçoit `echecs_connexion` (entier, 0 par défaut), `verrouille_le`, `verrouille_jusqua` (vide pour un verrou manuel) et `verrouille_motif`.

## 4. Tests

1. Le compteur monte à chaque échec et revient à zéro à la connexion ; un candidat n'est pas compté.
2. Verrouillage au seuil ; refus d'accès au panneau d'un compte verrouillé ; notification aux administrateurs.
3. Déverrouillage depuis l'écran (droit requis) et par demande d'autorisation.
4. `SEC_CONNEXIONS` : compte verrouillé, rafale d'échecs sur un identifiant inconnu.
