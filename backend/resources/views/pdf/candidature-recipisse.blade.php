<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Récépissé candidature {{ $candidature->numero_dossier }}</title>
<style>
  @page { margin: 14mm 12mm 18mm 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #333; margin: 0; }
  .header { border-bottom: 2px solid #6B2FA0; padding-bottom: 6mm; margin-bottom: 6mm; position: relative; }
  .header img.logo { height: 22mm; }
  .header img.entete { width: 100%; height: auto; max-height: 18mm; }
  .header .meta { position: absolute; right: 0; top: 0; text-align: right; font-size: 9pt; }
  h1 { font-size: 16pt; color: #6B2FA0; margin: 4mm 0 2mm; letter-spacing: 0.3mm; }
  h2 { font-size: 11pt; color: #6B2FA0; margin: 5mm 0 1.5mm; border-bottom: 1px solid #EDE7F6; padding-bottom: 1mm; }
  .copy-label { display: inline-block; padding: 1.2mm 3mm; border-radius: 2mm; background: #6B2FA0; color: #fff; font-size: 9pt; letter-spacing: 0.5mm; }
  .copy-label.admin { background: #C9A227; color: #1a1a1a; }
  table.kv { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  table.kv td { padding: 1mm 2mm; vertical-align: top; }
  table.kv td.k { width: 38%; color: #6B2FA0; font-weight: bold; }
  table.kv td.v { width: 62%; color: #333; }
  .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 64pt; color: rgba(107,47,160,0.08); font-weight: 800; letter-spacing: 4mm; pointer-events: none; z-index: 0; }
  .watermark.admin { color: rgba(201,162,39,0.25); font-size: 48pt; letter-spacing: 2mm; }
  .footer { position: fixed; bottom: 6mm; left: 0; right: 0; font-size: 8pt; color: #555; padding: 0 12mm; }
  .footer .qr { float: right; }
  .footer .qr img { height: 22mm; width: 22mm; }
  .footer .hash { font-family: DejaVu Sans Mono, monospace; word-break: break-all; }
  .page-break { page-break-after: always; }
  .lead { color: #555; font-style: italic; margin-top: 1mm; }
</style>
</head>
<body>

{{-- ========== Page 1 : Copie Étudiant ========== --}}
<div class="watermark">COPIE ÉTUDIANT</div>

<div class="header">
  @if($logoSrc)
    <img class="logo" src="{{ $logoSrc }}" alt="PSSFP">
  @endif
  <div class="meta">
    <strong>Numéro de dossier</strong><br>
    <span style="font-size: 13pt; color: #6B2FA0;">{{ $candidature->numero_dossier }}</span><br>
    <em>Généré le {{ $generatedAt->format('d/m/Y H:i') }}</em>
  </div>
</div>

<span class="copy-label">COPIE ÉTUDIANT</span>

<h1>Récépissé de dépôt de candidature</h1>
<p class="lead">
  Programme Supérieur de Spécialisation en Finances Publiques —
  {{ $campagne->nom ?? 'Campagne en cours' }}.
</p>

<h2>Identité du candidat</h2>
<table class="kv">
  <tr><td class="k">Civilité, nom</td><td class="v">{{ $candidature->civilite }} {{ $candidature->prenom }} {{ $candidature->nom }}@if($candidature->epouse) (épouse {{ $candidature->epouse }})@endif</td></tr>
  <tr><td class="k">Date de naissance</td><td class="v">{{ optional($candidature->date_naissance)->format('d/m/Y') }} à {{ $candidature->lieu_naissance ?? '—' }}</td></tr>
  <tr><td class="k">Nationalité</td><td class="v">{{ optional($candidature->paysNationalite)->nom ?? $candidature->nationalite }}</td></tr>
  <tr><td class="k">Téléphone</td><td class="v">{{ $candidature->phone_e164 }}</td></tr>
  <tr><td class="k">Email</td><td class="v">{{ $candidature->email ?? '—' }}</td></tr>
</table>

<h2>Vœu de spécialité</h2>
<table class="kv">
  <tr><td class="k">Spécialité demandée</td><td class="v">{{ $candidature->specialite ?? '—' }}</td></tr>
  @if($candidature->second_choix)
    <tr><td class="k">Second choix éventuel</td><td class="v">{{ $candidature->second_choix }}</td></tr>
  @endif
  <tr><td class="k">Type d'études</td><td class="v">{{ $candidature->type_etude ?? '—' }}</td></tr>
  <tr><td class="k">Première langue</td><td class="v">{{ strtoupper($candidature->premiere_langue ?? '—') }}</td></tr>
</table>

<h2>Diplôme et situation professionnelle</h2>
<table class="kv">
  <tr><td class="k">Diplôme le plus élevé</td><td class="v">{{ $candidature->diplome_obtenu ?? '—' }} ({{ $candidature->annee_diplome ?? '—' }})</td></tr>
  <tr><td class="k">Établissement</td><td class="v">{{ $candidature->institut ?? '—' }}</td></tr>
  <tr><td class="k">Spécialité du diplôme</td><td class="v">{{ $candidature->specialite_diplome ?? '—' }}</td></tr>
  <tr><td class="k">Situation actuelle</td><td class="v">{{ $candidature->statut_actuel ?? '—' }}</td></tr>
  @if($candidature->employeur)
    <tr><td class="k">Employeur</td><td class="v">{{ $candidature->employeur }}</td></tr>
  @endif
</table>

<h2>Engagement</h2>
<p>
  Je soussigné, <strong>{{ $candidature->engagement_nom ?? '—' }}</strong>,
  certifie l'exactitude des informations transmises et m'engage à respecter
  le règlement intérieur du PSSFP. Cette pièce vaut récépissé de dépôt
  de la candidature soumise le {{ optional($candidature->submitted_at)->format('d/m/Y à H:i') ?? $generatedAt->format('d/m/Y à H:i') }}.
</p>

<div class="footer">
  <div class="qr">
    @if($qrSvg)<img src="{{ $qrSvg }}" alt="QR">@endif
  </div>
  <div>
    <strong>Vérification :</strong> scannez le QR pour consulter la fiche
    publique de la candidature.<br>
    <span class="hash">SHA256 : {{ $hashPlaceholder }}</span>
  </div>
</div>

<div class="page-break"></div>

{{-- ========== Page 2 : Copie Administration ========== --}}
<div class="watermark admin">DOCUMENT ADMINISTRATIF</div>

<div class="header">
  @if($logoSrc)
    <img class="logo" src="{{ $logoSrc }}" alt="PSSFP">
  @endif
  <div class="meta">
    <strong>Numéro de dossier</strong><br>
    <span style="font-size: 13pt; color: #6B2FA0;">{{ $candidature->numero_dossier }}</span><br>
    <em>Page administration</em>
  </div>
</div>

<span class="copy-label admin">COPIE ADMINISTRATION — PSSFP</span>

<h1>Fiche administrative — Comité d'admission</h1>
<p class="lead">
  Document à conserver dans le dossier physique du candidat. À ne pas remettre au candidat.
</p>

<h2>Identité complète</h2>
<table class="kv">
  <tr><td class="k">Nom complet</td><td class="v">{{ $candidature->civilite }} {{ $candidature->prenom }} {{ $candidature->nom }}@if($candidature->epouse) (épouse {{ $candidature->epouse }})@endif</td></tr>
  <tr><td class="k">UUID candidat</td><td class="v">{{ $candidature->uuid }}</td></tr>
  <tr><td class="k">Numéro téléphone</td><td class="v">{{ $candidature->phone_e164 }}</td></tr>
  <tr><td class="k">Date de naissance</td><td class="v">{{ optional($candidature->date_naissance)->format('d/m/Y') }}</td></tr>
  <tr><td class="k">Nationalité</td><td class="v">{{ optional($candidature->paysNationalite)->nom ?? $candidature->nationalite }}</td></tr>
  <tr><td class="k">Pays de résidence</td><td class="v">{{ $candidature->pays_residence }}</td></tr>
  @if($candidature->region)
    <tr><td class="k">Région / Département</td><td class="v">{{ optional($candidature->regionRel)->nom ?? $candidature->region }} / {{ optional($candidature->departementRel)->nom ?? $candidature->departement }}</td></tr>
  @endif
  <tr><td class="k">Adresse</td><td class="v">{{ $candidature->adresse ?? '—' }}, {{ $candidature->ville_residence ?? '—' }}</td></tr>
</table>

<h2>Tracking dossier</h2>
<table class="kv">
  <tr><td class="k">Statut métier</td><td class="v"><strong>{{ strtoupper($candidature->statut) }}</strong></td></tr>
  <tr><td class="k">Soumis le</td><td class="v">{{ optional($candidature->submitted_at)->format('d/m/Y H:i:s') ?? $generatedAt->format('d/m/Y H:i:s') }}</td></tr>
  <tr><td class="k">Frais payés</td><td class="v">{{ $candidature->frais_paye ? 'OUI' : 'NON (à percevoir)' }}</td></tr>
  <tr><td class="k">Moyen de connaissance</td><td class="v">{{ $candidature->moyen_connaissance ?? '—' }}</td></tr>
</table>

<h2>Vérification documentaire</h2>
<p>
  Le code QR ci-dessous renvoie vers la fiche publique du candidat.
  Scannez au comptoir au moment du dépôt physique pour vérification.
  L'empreinte SHA256 ci-dessous garantit l'intégrité du PDF imprimé.
</p>

<div class="footer">
  <div class="qr">
    @if($qrSvg)<img src="{{ $qrSvg }}" alt="QR">@endif
  </div>
  <div>
    <strong>Réservé à l'administration PSSFP.</strong><br>
    <span class="hash">SHA256 : {{ $hashPlaceholder }}</span>
  </div>
</div>

</body>
</html>
