# Module 4 — Scolarité : validation par l'administrateur de toute modification ou annulation financière

Règle donnée par le Chef de projet le 25/11/2026 : « il faut vraiment que toute modification ou annulation soit validée
par l'admin ». Elle remplace les décisions Q2 (rectification par le service financier seul) et Q11 (annulation directe
par le responsable de scolarité) de la spec `module-4-scolarite-espace-financier.md`, §7.

## Constat (24-25/11/2026)

Le service financier avait annulé seul quatre reçus (RCPT-2026-000022, 000059, 000064, 000103) sans corriger les
versements : les versements restaient comptés, sans reçu en vigueur. Trois motifs relevaient d'une correction (doublon,
erreur de date), le quatrième d'un simple renvoi (« reçu non envoyé », alors que le SMS et l'e-mail étaient partis).

## Règles

| Geste | Avant | Désormais |
|---|---|---|
| Décider une demande de correction d'un versement | responsable, admin, super admin | administrateur (`admin`, `super_admin`) |
| Annulation directe d'un versement | responsable, admin, super admin | administrateur |
| Annulation d'un règlement d'organisme | responsable, admin, super admin | administrateur |
| Rectification sous 48 h | service financier seul | administrateur qui détient tous les droits du geste (en pratique `super_admin`) ; le service financier demande la correction |
| Annulation d'un reçu | service financier seul | « Annuler et réémettre le reçu » : décidée par un administrateur, jamais d'annulation seule |
| Renvoi du reçu à l'auditeur | — | nouveau geste du service financier, sans rien annuler |

- R1. Le droit `scolarite.versement_annuler` est retiré au rôle `scolarite_responsable` (migration additive, réversible) ;
  `admin` et `super_admin` le gardent.
- R2. Un reçu ne s'annule plus seul depuis les écrans : l'annulation est toujours suivie, dans la même transaction, de
  l'émission d'un nouveau reçu pour le même versement (« remplace le reçu … »). Si le nouveau reçu ne peut pas être émis
  (dossier retiré), rien n'est annulé.
- R3. Le service financier demande la réémission (nouveau type de demande `reemettre_recu`, circuit du lot 14c, sans
  visa, 24 h). À l'approbation, l'annulation est passée au nom de l'administrateur et le nouveau reçu au nom du
  demandeur. L'empreinte du reçu (ligne et annulation) fait échouer la demande si le reçu a changé entre-temps.
- R4. La fenêtre de la demande rappelle qu'une erreur sur le versement (montant, date, référence, auditeur, doublon) se
  règle par « Corriger ce versement », et un reçu non reçu par « Renvoyer le reçu à l'auditeur ».
- R5. « Renvoyer le reçu à l'auditeur » renvoie le SMS et l'e-mail du reçu (avis journalisé `recu_avis`) ; ouvert à qui
  émet les reçus.

## Hors lot

Remises, barèmes et moratoires restent des décisions du responsable de scolarité : ce ne sont ni des modifications ni
des annulations d'un versement ou d'un reçu enregistré. L'abandon d'un virement annoncé reste au service financier : le
virement n'a encore rien crédité. À trancher si le Chef de projet veut les y inclure.

## Tests

`backend/tests/Feature/Scolarite/ValidationAdminFinancesTest.php` ; tests existants adaptés (l'annulation et la décision
passent par un administrateur, la rectification par le super administrateur).
