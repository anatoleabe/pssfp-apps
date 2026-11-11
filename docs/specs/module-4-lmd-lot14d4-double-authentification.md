# Lot 14d-4 : double authentification (TOTP) du back-office

**Date** : 2026-11-11. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI.
**Sources** : CLAUDE.md et CDC (« sessions Laravel pour Filament admin avec 2FA TOTP obligatoire ») ; revue de sécurité du lot 14d-2.

## 1. Constat

La double authentification n'est pas en service :
- aucun compte du personnel ne l'a configurée en production ;
- le back-office n'a pas d'écran pour la configurer ;
- la connexion ne demande jamais de code ;
- l'obligation (`FILAMENT_REQUIRE_2FA`) refusait en bloc tout compte sans double authentification, si bien qu'aucun compte n'aurait pu la configurer. Elle est donc désactivée en production.

## 2. Règles

1. **Écran « Ma sécurité »**, dans le menu du compte, pour tout le personnel :
   - **activer** : le mot de passe actuel est demandé. La plateforme affiche un QR code et la clé, à saisir dans une application d'authentification (Google Authenticator, Microsoft Authenticator, FreeOTP…) ;
   - **confirmer** avec un premier code à 6 chiffres. L'activation ne vaut qu'après cette confirmation ;
   - **codes de secours** : 8 codes à usage unique, affichés une seule fois, à conserver hors ligne. On peut en tirer de nouveaux, mot de passe à l'appui, ce qui annule les anciens ;
   - **désactiver**, mot de passe à l'appui, sauf quand la double authentification est obligatoire. La réinitialisation par un super administrateur (téléphone perdu) existe déjà.

   Activation, désactivation et nouveaux codes sont journalisés (journal `comptes`).
2. **Connexion en deux étapes** : après un mot de passe juste, un compte qui a confirmé sa double authentification doit saisir un code de l'application, ou un code de secours, avant d'entrer.
   - L'étape « code » dure 5 minutes au plus ; au-delà, il faut recommencer.
   - Un code faux compte comme un échec de connexion : le verrou progressif du lot 14d-2 s'applique.
   - Un code de secours utilisé ne resservira plus.
   - La session ne s'ouvre qu'après le code.
   - Pas de « se souvenir de moi » sur le back-office : le cookie de rappel (400 jours) rouvrirait la session sans code.
   - La session change d'identifiant avant l'étape du code, puis à l'ouverture ; un seul code à la fois est vérifié par compte.
3. **Alertes** : à l'activation, au renouvellement des codes de secours, à la désactivation, à la réinitialisation par un administrateur et à l'usage d'un code de secours, le titulaire reçoit un courriel, et son jeton de rappel change. Un intrus qui connaît le mot de passe et enrôle son propre téléphone ne passe donc pas inaperçu.
4. **Obligation** (`FILAMENT_REQUIRE_2FA=true`) : un compte du personnel sans double authentification entre, mais il est conduit sur « Ma sécurité » à chaque page tant qu'il ne l'a pas activée. Il ne peut rien faire d'autre (sauf se déconnecter, et vérifier son adresse si elle ne l'est pas). L'obligation s'applique aussi aux échanges Livewire (middleware persistant), et la recherche globale reste muette pour lui. L'obligation reste désactivée en production jusqu'à décision du Chef USI, une fois le personnel prévenu.

## 3. Tests

1. Activation (mot de passe exigé), confirmation par code, codes de secours, nouveaux codes, désactivation refusée sous obligation.
2. Connexion : sans double authentification, une seule étape ; avec, code exigé, code faux compté comme un échec, code de secours à usage unique, étape expirée.
3. Obligation : redirection vers « Ma sécurité » ; accès normal une fois la double authentification activée.
