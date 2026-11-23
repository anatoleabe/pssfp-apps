# Module 4 — Scolarité, lot 23 : relance automatique des tranches, activable

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0013.
**Source** : décision du 23/11/2026 : « les relances doivent être activables dans les configurations ».

## 1. Objectif

Relancer chaque semaine, par SMS et par e-mail, les auditeurs dont une tranche est échue, sans geste de la scolarité. La relance est éteinte par défaut ; un administrateur l'allume dans Paramètres.

## 2. Règles

1. **Réglage.** Dans Administration → Paramètres, section « Relance des tranches » : un interrupteur (éteint par défaut) et l'intervalle entre deux relances d'un même auditeur (7 jours par défaut, de 3 à 30). Le changement est journalisé.
2. **Qui est relancé.** Les auditeurs inscrits dans l'année en cours dont une tranche a dépassé son échéance sans être soldée. Les droits d'inscription, qui n'ont pas de date d'échéance, ne déclenchent pas la relance.
3. **Qui ne l'est pas.**
   - statut autre que « actif » (suspendu, abandon, exclu, diplômé), dossier retiré ;
   - année suspendue sur demande : son reste n'est plus réclamé ;
   - versement en attente de confirmation par la scolarité : la relance de confirmation lui écrit déjà ;
   - accès anticipé en cours (virement annoncé, moratoire, prise en charge) : sa propre relance s'applique ;
   - auditeur déjà relancé depuis moins que l'intervalle.
4. **Message.** Le SMS donne le montant échu et renvoie à la scolarité ; l'e-mail donne en plus le détail par tranche. Aucun code d'accès. Le message est réaffiché dans l'espace de l'auditeur.
5. **Garde-fou.** Au plus 200 auditeurs relancés par jour ; les suivants le sont le lendemain. Un envoi qui échoue (passerelle, aucune coordonnée) est retenté le lendemain.
6. **Cadence.** Chaque jour à 08:30, heure de Yaoundé. Chaque relance est journalisée sur la fiche de l'auditeur.

## 3. Modèle de données

Aucune migration : le réglage vit dans les réglages de l'application, l'anti-doublon dans le journal des relances (cause « tranche »).

## 4. Tests

- Éteinte : rien ne part.
- Allumée : l'auditeur en retard reçoit le montant échu ; l'auditeur à jour et le suspendu ne reçoivent rien.
- Pas de seconde relance avant l'intervalle ; une nouvelle après.
- Le réglage s'enregistre depuis Paramètres et se journalise.

## 5. Hors périmètre

- Relance avant l'échéance (rappel préventif).
- Arriérés des années antérieures.
