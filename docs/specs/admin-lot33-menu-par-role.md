# Administration, lot 33 : menu par rôle

Premier lot de la revue ergonomique par profil (état des lieux : `docs/audits/ergonomie-profils-2026-11.md`). Choix du
Chef de projet du 25/11/2026 : le paramétrage dans une rubrique « Réglages » repliée en bas ; les lots dans l'ordre menu,
accueil, espaces.

## Ce que le lot change

1. **Rubrique « Réglages »**, dernière du menu, repliée par défaut. Elle reprend l'ancien « Référentiel pédagogique »
   (parcours, maquettes, règles d'évaluation, attributions des ECUE, points focaux) et le paramétrage annuel : calendrier
   académique, promotions, quotas horaires, modèles d'actes, salles, plages horaires, jours non ouvrés, barèmes de
   scolarité, rubriques de frais, barème des enseignants, étiquettes. **Les droits ne changent pas** : chacun voit dans
   « Réglages » ce qu'il voyait avant ailleurs.
2. **« Accueil »** en tête du menu (l'ancien « Tableau de bord », même adresse), avant la messagerie et les demandes.
3. **Ordre selon le métier** : le service financier, qui encaisse sans suivre la pédagogie, voit Finances en tête ; les
   autres comptes gardent l'ordre commun.
4. **Libellés** : « Prises en charge », « Registre des documents », « Anciennes adresses » (plus de majuscule à chaque mot) ;
   le tableau de bord des finances devient « Synthèse » (plus deux « Tableau de bord » pour le service financier).

## Effet mesuré (comptes de test, 25/11/2026)

| Profil | Avant | Après (hors « Réglages ») |
|---|---|---|
| Agent de scolarité | 41 | 28 (+ 13 repliées) |
| Responsable de scolarité, compte réel | 59 | 43 (+ 16 repliées) |
| Service financier | 20 | 18 (+ 2 repliées), Finances en tête |

## Technique

Filament fixe l'ordre des rubriques à leur déclaration : le panneau déclare des emplacements dont le libellé est calculé
pour le compte connecté (`GroupeNavigation::ordrePour`). L'accueil est une sous-classe du tableau de bord de Filament
(même route), déclarée dans le socle commun.

## Tests

`backend/tests/Feature/Filament/NavigationAdminTest.php`, bloc « menu par rôle (lot 33) » ; `ModuleIsolationTest`
pointé sur la nouvelle page d'accueil.
