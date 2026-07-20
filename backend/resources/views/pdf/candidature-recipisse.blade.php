<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Récépissé candidature {{ $candidature->numero_dossier }}</title>
<style>
  @page { margin: 12mm 12mm 18mm 12mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color: #333; margin: 0; }

  /* En-tête officiel bilingue (lettre à en-tête PSSFP) */
  .letterhead { width: 100%; text-align: center; margin-bottom: 4mm; }
  .letterhead img { width: 100%; height: auto; max-height: 30mm; }
  .letterhead-fallback { display: table; width: 100%; }
  .letterhead-fallback .col { display: table-cell; width: 40%; vertical-align: top; text-align: center; font-size: 8pt; line-height: 1.3; }
  .letterhead-fallback .mid { width: 20%; }
  .letterhead-fallback .mid img { height: 20mm; }

  .rule { border: 0; border-top: 2px solid #4A2E67; margin: 3mm 0 5mm; }

  .doc-head { position: relative; margin-bottom: 4mm; }
  .doc-head .meta { position: absolute; right: 0; top: 0; text-align: right; font-size: 9pt; }
  .doc-head .meta .num { font-size: 13pt; color: #4A2E67; font-weight: bold; }

  h1 { font-size: 15pt; color: #4A2E67; margin: 2mm 0 1.5mm; letter-spacing: 0.3mm; }
  h2 { font-size: 11pt; color: #4A2E67; margin: 5mm 0 1.5mm; border-bottom: 1px solid #F4EFFA; padding-bottom: 1mm; }

  .copy-label { display: inline-block; padding: 1.2mm 3mm; border-radius: 2mm; background: #4A2E67; color: #fff; font-size: 9pt; letter-spacing: 0.5mm; }
  .copy-label.admin { background: #D4AF6A; color: #3A2452; }

  /* Bloc identité + photo côte à côte (page 1) — table HTML réelle (DomPDF-safe) */
  table.id-block { width: 100%; border-collapse: collapse; }
  table.id-block td.id-info { width: 72%; vertical-align: top; }
  table.id-block td.id-photo { width: 28%; vertical-align: top; text-align: center; }
  .photo-frame { width: 30mm; height: 30mm; border: 1px solid #4A2E67; background: #FAF7F2; margin: 0 auto; }
  .photo-frame img { width: 30mm; height: 30mm; }
  .photo-empty { width: 30mm; height: 30mm; border: 1px dashed #A592BD; background: #FAF7F2; color: #8A7AA0; font-size: 7.5pt; text-align: center; margin: 0 auto; padding-top: 9mm; }
  .photo-cap { font-size: 7.5pt; color: #777; margin-top: 1mm; text-align: center; }

  table.kv { width: 100%; border-collapse: collapse; margin-top: 1mm; }
  table.kv td { padding: 1mm 2mm; vertical-align: top; }
  table.kv td.k { width: 42%; color: #4A2E67; font-weight: bold; }
  table.kv td.v { width: 58%; color: #333; }

  /* Filigrane unique (DomPDF répète les `position: fixed` sur toutes les pages :
     un seul évite la superposition de deux filigranes différents). */
  .watermark { position: fixed; top: 45%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 52pt; color: rgba(74,46,103,0.07); font-weight: 800; letter-spacing: 3mm; z-index: 0; }

  /* Pied de page en flux normal (non fixed) — sinon superposition inter-pages. */
  .footer { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #F4EFFA; font-size: 8pt; color: #555; }
  .footer .qr { float: right; }
  .footer .qr img { height: 22mm; width: 22mm; }
  .footer .hash { font-family: DejaVu Sans Mono, monospace; word-break: break-all; }

  .page-break { page-break-after: always; }
  .lead { color: #555; font-style: italic; margin-top: 1mm; }
</style>
</head>
<body>

{{-- Filigrane unique, rendu sur les deux pages (position: fixed). --}}
<div class="watermark">RÉCÉPISSÉ PSSFP</div>

{{-- ========== Page 1 : Copie Étudiant ========== --}}
<div class="letterhead">
  @if($enteteSrc)
    <img src="{{ $enteteSrc }}" alt="République du Cameroun — Ministère des Finances — PSSFP">
  @elseif($logoSrc)
    <img src="{{ $logoSrc }}" alt="PSSFP" style="max-height: 24mm; width: auto;">
  @endif
</div>
<hr class="rule">

<div class="doc-head">
  <div class="meta">
    <strong>Numéro de dossier</strong><br>
    <span class="num">{{ $candidature->numero_dossier }}</span><br>
    <em>Généré le {{ $generatedAt->format('d/m/Y H:i') }}</em>
  </div>
  <span class="copy-label">COPIE ÉTUDIANT</span>
  <h1>Récépissé de dépôt de candidature</h1>
  <p class="lead">
    Programme Supérieur de Spécialisation en Finances Publiques —
    {{ $campagne->nom ?? 'Campagne en cours' }}.
  </p>
</div>

<h2>Identité du candidat</h2>
<table class="id-block">
  <tr>
    <td class="id-info">
      <table class="kv">
        <tr><td class="k">Civilité, nom</td><td class="v">{{ $candidature->civilite }} {{ $candidature->prenom }} {{ $candidature->nom }}@if($candidature->epouse) (épouse {{ $candidature->epouse }})@endif</td></tr>
        <tr><td class="k">Date et lieu de naissance</td><td class="v">{{ optional($candidature->date_naissance)->format('d/m/Y') }} à {{ $candidature->lieu_naissance ?? '—' }}</td></tr>
        <tr><td class="k">Nationalité</td><td class="v">{{ optional($candidature->paysNationalite)->nom ?? $candidature->nationalite }}</td></tr>
        <tr><td class="k">Téléphone</td><td class="v">@if($candidature->indicatif1 || $candidature->telephone1){{ $candidature->indicatif1 }} {{ $candidature->telephone1 }}@else{{ $candidature->phone_e164 }}@endif</td></tr>
        <tr><td class="k">Email</td><td class="v">{{ $candidature->email ?? '—' }}</td></tr>
      </table>
    </td>
    <td class="id-photo">
      @if($photoSrc)
        <div class="photo-frame"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
      @else
        <div class="photo-empty">Photo à déposer au bureau de la scolarité</div>
      @endif
      <div class="photo-cap">Photo 4×4</div>
    </td>
  </tr>
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
  Je soussigné(e), <strong>{{ $candidature->engagement_nom ?? '—' }}</strong>,
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
<div class="letterhead">
  @if($enteteSrc)
    <img src="{{ $enteteSrc }}" alt="République du Cameroun — Ministère des Finances — PSSFP">
  @elseif($logoSrc)
    <img src="{{ $logoSrc }}" alt="PSSFP" style="max-height: 24mm; width: auto;">
  @endif
</div>
<hr class="rule">

<div class="doc-head">
  <div class="meta">
    <strong>Numéro de dossier</strong><br>
    <span class="num">{{ $candidature->numero_dossier }}</span><br>
    <em>Page administration</em>
  </div>
  <span class="copy-label admin">COPIE ADMINISTRATION — PSSFP</span>
  <h1>Fiche administrative — Comité d'admission</h1>
  <p class="lead">
    Document à conserver dans le dossier physique du candidat. À ne pas remettre au candidat.
  </p>
</div>

<table class="id-block">
  <tr>
    <td class="id-info">
      <h2 style="margin-top:0;">Identité complète</h2>
      <table class="kv">
        <tr><td class="k">Nom complet</td><td class="v">{{ $candidature->civilite }} {{ $candidature->prenom }} {{ $candidature->nom }}@if($candidature->epouse) (épouse {{ $candidature->epouse }})@endif</td></tr>
        <tr><td class="k">UUID candidat</td><td class="v">{{ $candidature->uuid }}</td></tr>
        <tr><td class="k">Téléphone de contact</td><td class="v">@if($candidature->indicatif1 || $candidature->telephone1){{ $candidature->indicatif1 }} {{ $candidature->telephone1 }}@else—@endif</td></tr>
        <tr><td class="k">Téléphone de connexion</td><td class="v">{{ $candidature->phone_e164 }}</td></tr>
        <tr><td class="k">Date de naissance</td><td class="v">{{ optional($candidature->date_naissance)->format('d/m/Y') }}</td></tr>
        <tr><td class="k">Nationalité</td><td class="v">{{ optional($candidature->paysNationalite)->nom ?? $candidature->nationalite }}</td></tr>
        <tr><td class="k">Pays de résidence</td><td class="v">{{ $candidature->pays_residence }}</td></tr>
        @if($candidature->region)
          <tr><td class="k">Région / Département</td><td class="v">{{ optional($candidature->regionRel)->nom ?? $candidature->region }} / {{ optional($candidature->departementRel)->nom ?? $candidature->departement }}</td></tr>
        @endif
        <tr><td class="k">Adresse</td><td class="v">{{ $candidature->adresse ?? '—' }}, {{ $candidature->ville_residence ?? '—' }}</td></tr>
      </table>
    </td>
    <td class="id-photo">
      @if($photoSrc)
        <div class="photo-frame"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
      @else
        <div class="photo-empty">Photo à déposer au bureau de la scolarité</div>
      @endif
      <div class="photo-cap">Photo 4×4</div>
    </td>
  </tr>
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
