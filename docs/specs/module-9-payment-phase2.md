# Spec — Module Paiement bibliothèque (Phase II — différé)

> **Référence** : Sprint Specs A9
> **Statut** : DIFFÉRÉ POST-LANCEMENT V1 — Décision Anatole 5 mai 2026
> **Date de cadrage** : 2026-05-05
> **Source CDC** : §G du CDC bibliothèque v2.0

Ce document maintient le cadrage du module de paiement de la bibliothèque (4 M FCFA budgétés au CDC §G du CDC biblio v2.0 et CDC v5 §13.1) **sans le mettre en œuvre en Phase I**. Il existe pour deux raisons : (1) ne pas perdre le travail d'analyse réalisé pendant la rédaction du CDC v2.0 ; (2) garantir que **l'architecture Phase I prévoit l'extension** (Strategy Pattern Laravel, table `transactions` placeholder dans le data-model) afin que le module puisse être ajouté sans refacto cassante.

## 1. Décision de différé — justification

Trois raisons concourent à reporter ce module après le lancement V1 :

1. **Concentration sur les livrables critiques V1**. Site institutionnel + biblio + candidatures + admin = déjà un périmètre tendu pour 13 semaines (Phase 4 à Phase 8). Ajouter un module de paiement multi-canal avec ses contraintes réglementaires (KYC, conformité fiscale, contrats opérateurs MTN et Orange) augmente le risque de glissement.

2. **Pas de monétisation premium en V1**. Le contenu mis en ligne au lancement (thèses, articles, textes de loi) est majoritairement public ou réservé aux auditeurs authentifiés via leur compte `@pssfp.net`. Le besoin de paiement n'apparaît que pour les ouvrages premium et certifications FOAD (CDC v2.0 §C.2), qui ne sont pas eux-mêmes prêts à être commercialisés en V1.

3. **Frais candidature payés à CREMINCAM en agence**. Le seul flux de paiement potentiellement nécessaire en V1 (frais de candidature 50 kFCFA) est traité via paiement en agence CREMINCAM et saisie manuelle dans Filament — décision Anatole du 5 mai 2026. Pas besoin d'intégration en ligne pour ce flux.

## 2. Périmètre cadré pour Phase II

Trois canaux à intégrer dans l'ordre :

**Canal 1 — MTN Mobile Money (priorité 1)**. Premier opérateur mobile au Cameroun (~50% de part de marché). Intégration via l'API officielle MTN MoMo Collection (developers.mtn.com). Sandbox disponible pour les tests. Flux UX : utilisateur saisit son numéro MTN → reçoit notification USSD → confirme sur son téléphone → webhook serveur → confirmation Laravel. Devise : FCFA (XAF).

**Canal 2 — Orange Money (priorité 2)**. Second opérateur. Intégration possible directe via API Orange Cameroun (partenariat à négocier) ou via un agrégateur camerounais — **CinetPay**, **Pawapay**, ou **Campay** sont les candidats sérieux. L'agrégateur a un coût (~3% par transaction) mais simplifie l'opérationnel et inclut souvent MTN MoMo en bonus. À arbitrer en fonction du contrat MTN signé.

**Canal 3 — Visa/Mastercard via Stripe ou CinetPay (priorité 3)**. Pour la diaspora et les partenaires institutionnels disposant d'une carte bancaire. Stripe est mature mais sa disponibilité Cameroun est limitée — CinetPay couvre ce besoin nativement.

## 3. Architecture Phase I qui prépare Phase II

L'architecture V1 doit déjà inclure :

- Table `transactions` créée vide par migration V1 (cf. data-model.md §11).
- Service Laravel `PaymentGateway` interface avec implémentations stubbées (`McMtnMomoGateway`, `OrangeMoneyGateway`, `StripeGateway`) — méthodes `initiate()`, `verify()`, `refund()` — Strategy Pattern.
- Webhook endpoint `POST /v1/webhooks/payment/{channel}` activable par feature flag `PAYMENT_ENABLED=false` en V1.
- Resources Filament `Transaction` et `Subscription` non publiées en V1 (commentées ou cachées du menu admin).
- Champs `frais_paiement_mode`, `frais_paiement_reference`, `frais_paiement_date` sur `candidatures` — déjà actés en V1, prêts à recevoir des références automatiques en Phase II.

## 4. Spec à compléter avant kickoff Phase II

Cette section est un **TODO list** à remplir au moment où Phase II démarre :

- [ ] Sélection définitive de l'agrégateur Orange Money (direct vs CinetPay/Pawapay/Campay) — arbitrage UAAF.
- [ ] Contrat MTN MoMo Collection signé (numéro de marchand, API key sandbox + production).
- [ ] Contrat Stripe ou CinetPay pour cartes bancaires.
- [ ] Politique de prix et abonnements premium définie par COPIL.
- [ ] Politique de remboursement définie (délai, motif, processus).
- [ ] Règlement intérieur monétisation : qui valide les transactions, qui peut faire des remboursements.
- [ ] Conformité fiscale Cameroun : facturation, TVA si applicable, déclarations DGI.
- [ ] Tests sandbox bout-en-bout sur les 3 canaux avant prod.
- [ ] Réconciliation comptable mensuelle : process avec UAAF.
- [ ] Tableau de bord financier dans Filament (CDC v2.0 §E.4.2).
- [ ] Dashboard public « Mes achats / Mes abonnements » dans `/mon-espace` biblio.

## 5. Critères de réception Phase II

Conservés du CDC bibliothèque v2.0 §K.1 :

- Test bout-en-bout MTN MoMo + Orange Money + carte bancaire en environnement sandbox, puis 5 transactions réelles de test en production.
- Webhook idempotent (retry sans double-débit).
- Tableau de bord analytique financier opérationnel : revenus par canal, ventes par ouvrage, abonnements actifs, taux de renouvellement.
- Export rapports financiers PDF + Excel.
- Notifications email + SMS post-transaction côté utilisateur.

---

## Annexe — Pointeurs documentation

- API MTN MoMo Collection : https://developers.mtn.com/
- CinetPay (agrégateur Cameroun) : https://cinetpay.com/
- Pawapay (agrégateur Afrique) : https://pawapay.io/
- Campay (agrégateur Cameroun) : https://campay.net/
- Stripe : https://stripe.com/africa
- Discussion CREMINCAM pour le canal frais candidature en agence : à initier UAAF.

Ce document est à mettre à jour quand le go Phase II sera donné par le Comité de Pilotage.
