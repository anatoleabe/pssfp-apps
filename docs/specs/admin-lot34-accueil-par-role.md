# Administration, lot 34 : accueil par rôle

Deuxième lot de la revue ergonomique par profil (`docs/audits/ergonomie-profils-2026-11.md`, constat 2 : l'accueil est
presque vide). Suit le lot 33 (menu par rôle).

## Ce que le lot change

1. **« À traiter » toujours visible** en tête de l'accueil. Quand rien n'attend le compte, le bloc le dit (« Rien à traiter
   — À jour ») au lieu de disparaître. Les éléments suivent l'ordre du menu du compte (Finances en tête pour le service
   financier).
2. **« Raccourcis »** sous « À traiter » : au plus huit écrans du métier du compte, avec une ligne qui dit à quoi chacun
   sert, et le rappel de la recherche au clavier (Ctrl+K). Ordre du travail :
   - enseignant : Mes cours, Mes émargements, Saisie des notes, Mes heures ;
   - point focal (sans droit de gestion) : Ma classe, Saisie des notes ;
   - service financier (sans droit de gestion pédagogique) : Versements, Virements à valider, Reçus, Synthèse financière,
     Solvabilité ;
   - puis, pour tous : scolarité (Auditeurs, Versements à confirmer, Listes et états, Emplois du temps, Planning
     d'examens, Saisie des notes, Requêtes, Délibérations), admissions (Candidatures, Campagnes), administration
     (Demandes, Utilisateurs), autres (États de paie, Bibliothèque, Pages du site, Articles).

   Un raccourci n'apparaît que si le compte peut ouvrir l'écran : les droits ne changent pas.
3. Les deux blocs sont rendus avec la page (pas d'apparition après coup).

## Tests

`backend/tests/Feature/Filament/ATraiterTest.php` : « Rien à traiter » ; raccourcis par métier (service financier, agent de
scolarité, enseignant, réception) ; rendu sur l'accueil. Capture de l'accueil du service financier à 1440 px.
