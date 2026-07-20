<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Récépissé de dépôt de candidature — {{ $candidature->numero_dossier }}</title>
@php
    /* ---------- Helpers de formatage ---------- */
    $fmtPhone = function ($ind, $num, $fallback = null) {
        $digits = preg_replace('/\D/', '', (string) $num);
        if ($digits === '') {
            return $fallback ?: null;
        }
        $ind = trim((string) $ind);
        if ($ind !== '' && $ind[0] !== '+') {
            $ind = '+'.$ind;
        }
        return trim(($ind !== '' ? $ind.' ' : '').trim(chunk_split($digits, 3, ' ')));
    };
    $fmtDate = function ($d, $withTime = true) {
        if (! $d) {
            return null;
        }
        return $withTime ? $d->translatedFormat('d F Y \à H \h i') : $d->translatedFormat('d F Y');
    };
    $row = function ($label, $value) {
        if ($value === null || $value === '') {
            return '';
        }
        return '<tr><td class="k">'.e($label).'</td><td class="v">'.e($value).'</td></tr>';
    };

    /* ---------- Valeurs dynamiques ---------- */
    $fullName    = trim(($candidature->prenom ?? '').' '.($candidature->nom ?? ''));
    $birth       = $fmtDate($candidature->date_naissance, false);
    if ($birth && $candidature->lieu_naissance) { $birth .= ' à '.$candidature->lieu_naissance; }
    $nationality = optional($candidature->paysNationalite)->nom ?? $candidature->nationalite;
    $phone       = $fmtPhone($candidature->indicatif1, $candidature->telephone1, $candidature->phone_e164);
    $studyMode   = match ($candidature->type_etude) {
        'presentiel' => 'Présentiel', 'distanciel' => 'Distanciel', default => $candidature->type_etude,
    };
    $language    = match ($candidature->premiere_langue) {
        'fr' => 'Français', 'en' => 'English', default => $candidature->premiere_langue,
    };
    $profStatus  = match ($candidature->statut_actuel) {
        'Etudiant' => 'Étudiant', 'Fonctionnaire-Contractuel' => 'Fonctionnaire / Contractuel',
        'Prive' => 'Secteur privé', default => $candidature->statut_actuel,
    };
    $degree      = $candidature->diplome_obtenu ?: null;
    $submission  = $fmtDate($candidature->submitted_at ?? $generatedAt);
    $generation  = $fmtDate($generatedAt);
    $promotion   = optional($campagne)->promotion_numero;
    $campaignName = optional($campagne)->nom;
    [$statusLabel, $statusClass] = match ($candidature->statut) {
        'candidat' => ['Candidature soumise', 'ok'],
        'accepte'  => ['Admis', 'ok'],
        'refuse'   => ['Non admis', 'red'],
        default    => ['Dossier enregistré', 'ok'],
    };
@endphp
<style>
  @page { margin: 8mm 11mm 7mm 11mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8.6pt; color: #2a2a2a; margin: 0; line-height: 1.3; }

  /* Palette : violet #4A2E67 · anthracite #2A2A2A · gris labels #6B6B6B ·
     carte pâle #F7F5FB · bordure #E6E2EE · vert/orange/rouge statut. */

  .wm { position: fixed; top: 44%; left: 50%; transform: translate(-50%,-50%); z-index: 0; }
  .wm img { width: 80mm; opacity: 0.035; }

  /* En-tête institutionnel compact : 3 colonnes */
  table.head { width: 100%; border-collapse: collapse; }
  table.head td { vertical-align: middle; }
  table.head td.fr, table.head td.en { width: 38%; font-size: 6.6pt; line-height: 1.2; color: #333; }
  table.head td.en { text-align: right; }
  table.head td.logo { width: 24%; text-align: center; }
  table.head td.logo img { height: 15mm; }
  .head .big { font-size: 7pt; font-weight: bold; letter-spacing: 0.2mm; }
  .band { height: 1.4mm; background: #4A2E67; border-radius: 1mm; margin: 1.5mm 0 2mm; }

  .pagemark { font-size: 7.3pt; color: #8a8a8a; text-align: right; margin-bottom: 0.8mm; }

  /* Bloc confirmation */
  .confirm { background: #F7F5FB; border: 0.4pt solid #E6E2EE; border-radius: 2.4mm; padding: 2.5mm 4mm; margin-bottom: 2mm; }
  .confirm .title { font-size: 13pt; font-weight: bold; color: #4A2E67; letter-spacing: 0.2mm; }
  .confirm .prog { font-size: 8pt; color: #6B6B6B; margin-top: 0.4mm; }
  table.cline { width: 100%; border-collapse: collapse; margin-top: 1.6mm; }
  table.cline td { vertical-align: middle; }
  .dossier-lbl { font-size: 7.3pt; color: #6B6B6B; letter-spacing: 0.8mm; text-transform: uppercase; }
  .dossier-num { font-size: 15pt; font-weight: bold; color: #2a2a2a; letter-spacing: 0.3mm; }
  .submitted { font-size: 8.2pt; color: #444; margin-top: 0.3mm; }
  .badge { display: inline-block; border-radius: 6mm; padding: 1.4mm 4.5mm; font-size: 8.5pt; font-weight: bold; }
  .badge.ok  { background: #E7F4E8; color: #1F6B2B; border: 0.4pt solid #B8DDBd; }
  .badge.red { background: #FBEAE9; color: #A3271F; border: 0.4pt solid #E7B7B3; }
  .badge.amber { background: #FBF1E2; color: #9A5B00; border: 0.4pt solid #E6CDA1; }

  /* Cartes de section */
  .card { border: 0.4pt solid #E6E2EE; border-radius: 2.4mm; padding: 2mm 3.5mm; margin-bottom: 1.8mm; }
  .card > .h { font-size: 9.6pt; font-weight: bold; color: #4A2E67; margin-bottom: 1.1mm; }
  .card.accent { background: #FBFAFD; }

  table.kv { width: 100%; border-collapse: collapse; }
  table.kv td { padding: 0.55mm 0; vertical-align: top; font-size: 8.6pt; }
  table.kv td.k { width: 42%; color: #6B6B6B; padding-right: 3mm; }
  table.kv td.v { width: 58%; color: #222; font-weight: 600; }

  /* deux colonnes de kv côte à côte */
  table.two { width: 100%; border-collapse: collapse; }
  table.two > tr > td { width: 50%; vertical-align: top; padding-right: 4mm; }
  table.two > tr > td + td { padding-right: 0; padding-left: 4mm; border-left: 0.4pt solid #EEE; }

  /* Identité + photo */
  table.id { width: 100%; border-collapse: collapse; }
  table.id td.info { vertical-align: top; }
  table.id td.ph { vertical-align: top; width: 30mm; padding-left: 4mm; text-align: center; }
  .photo { width: 24mm; height: 30mm; border-radius: 2mm; border: 0.5pt solid #C9C2D6; overflow: hidden; margin: 0 auto; }
  .photo img { width: 24mm; height: 30mm; }
  .photo-empty { width: 24mm; height: 30mm; border-radius: 2mm; border: 0.6pt dashed #B9B0CC; background: #F7F5FB; color: #8a80a0; font-size: 6.4pt; padding: 8mm 1.5mm 0; text-align: center; margin: 0 auto; }
  .photo-cap { font-size: 6.6pt; color: #8a8a8a; margin-top: 0.8mm; }

  .highlight { font-size: 10.5pt; font-weight: bold; color: #4A2E67; }

  .note { font-size: 8.2pt; color: #444; line-height: 1.42; }
  .note .certif { display: block; margin-top: 1.2mm; font-size: 7.6pt; color: #6B6B6B; font-style: italic; }

  /* Zone QR / vérification */
  table.verify { width: 100%; border-collapse: collapse; background: #F7F5FB; border: 0.4pt solid #E6E2EE; border-radius: 2.4mm; margin-bottom: 2.4mm; }
  table.verify td { vertical-align: middle; padding: 2.4mm 3.5mm; }
  table.verify td.qr { width: 30mm; text-align: center; }
  table.verify td.qr img { width: 26mm; height: 26mm; }
  .verify .vt { font-size: 9.5pt; font-weight: bold; color: #4A2E67; }
  .verify .vmeta { font-size: 8pt; color: #555; margin-top: 1.2mm; }
  .vcode { font-family: DejaVu Sans Mono, monospace; font-weight: bold; letter-spacing: 0.5mm; color: #2a2a2a; }

  /* Pied de page fixe (identique sur les 2 pages) */
  .foot { position: fixed; bottom: 0; left: 0; right: 0; padding: 1.5mm 11mm 0; font-size: 7.3pt; color: #7a7a7a; border-top: 0.4pt solid #E6E2EE; }
  .foot table { width: 100%; border-collapse: collapse; }
  .foot td.r { text-align: right; }

  /* Bandeau administration (page 2) */
  .adminbar { background: #4A2E67; color: #fff; border-radius: 2mm; padding: 2mm 4mm; font-size: 9.5pt; font-weight: bold; letter-spacing: 0.4mm; margin-bottom: 3mm; }
  .check { font-size: 8.8pt; color: #333; }
  .check .box { font-family: DejaVu Sans, sans-serif; color: #4A2E67; }
  table.checks { width: 100%; border-collapse: collapse; }
  table.checks td { width: 50%; padding: 1.1mm 0; vertical-align: top; }
  .writeline { border-bottom: 0.4pt solid #cfc8dc; height: 4.5mm; }
  .opt { display: inline-block; border: 0.5pt solid #C9C2D6; border-radius: 5mm; padding: 1mm 3.5mm; font-size: 8.2pt; color: #444; margin: 0 2mm 1.5mm 0; }

  .page-break { page-break-after: always; }
</style>
</head>
<body>

<div class="wm">@if($logoSrc)<img src="{{ $logoSrc }}" alt="">@endif</div>

{{-- Pied de page fixe (contact institutionnel) — répété sur les 2 pages --}}
<div class="foot">
  <table>
    <tr>
      <td>{{ $contact['adresse'] }} &nbsp;·&nbsp; {{ $contact['tel'] }} &nbsp;·&nbsp; {{ $contact['web'] }} &nbsp;·&nbsp; {{ $contact['email'] }}</td>
      <td class="r">Document généré électroniquement</td>
    </tr>
  </table>
</div>

{{-- ===================== PAGE 1 — COPIE CANDIDAT ===================== --}}
<table class="head">
  <tr>
    <td class="fr">
      <span class="big">RÉPUBLIQUE DU CAMEROUN</span><br>
      Paix – Travail – Patrie<br>
      Ministère des Finances<br>
      Secrétariat Général<br>
      Programme Supérieur de Spécialisation<br>en Finances Publiques
    </td>
    <td class="logo">@if($logoSrc)<img src="{{ $logoSrc }}" alt="PSSFP">@endif</td>
    <td class="en">
      <span class="big">REPUBLIC OF CAMEROON</span><br>
      Peace – Work – Fatherland<br>
      Ministry of Finance<br>
      General Secretariat<br>
      Advanced Program of Specialisation<br>in Public Finance
    </td>
  </tr>
</table>
<div class="band"></div>

<div class="pagemark">Page 1 sur 2 — Copie candidat</div>

<div class="confirm">
  <table style="width:100%; border-collapse:collapse;">
    <tr>
      <td style="vertical-align:top;">
        <div class="title">Récépissé de dépôt de candidature</div>
        <div class="prog">{{ $programName }}@if($promotion) — Promotion {{ $promotion }}@endif@if($campaignName) · {{ $campaignName }}@endif</div>
      </td>
    </tr>
  </table>
  <table class="cline">
    <tr>
      <td style="vertical-align:top;">
        <div class="dossier-lbl">Numéro de dossier</div>
        <div class="dossier-num">{{ $candidature->numero_dossier }}</div>
        @if($submission)<div class="submitted">Soumis le {{ $submission }}</div>@endif
      </td>
      <td style="text-align:right; vertical-align:top;">
        <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">Profil du candidat</div>
  <table class="id">
    <tr>
      <td class="info">
        <table class="kv">
          {!! $row('Civilité', $candidature->civilite) !!}
          {!! $row('Nom complet', $fullName) !!}
          @if($candidature->epouse){!! $row('Épouse', $candidature->epouse) !!}@endif
          {!! $row('Naissance', $birth) !!}
          {!! $row('Nationalité', $nationality) !!}
          {!! $row('Téléphone', $phone) !!}
          {!! $row('Adresse e-mail', $candidature->email) !!}
        </table>
      </td>
      <td class="ph">
        @if($photoSrc)
          <div class="photo"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
        @else
          <div class="photo-empty">Photo à déposer auprès de la scolarité</div>
        @endif
        <div class="photo-cap">Photographie</div>
      </td>
    </tr>
  </table>
</div>

<div class="card accent">
  <div class="h">Choix académique</div>
  @if($candidature->specialite)<div class="highlight">{{ $candidature->specialite }}</div>@endif
  <table class="two" style="margin-top:1mm;">
    <tr>
      <td>
        <table class="kv">
          {!! $row('Type de formation', $studyMode) !!}
          {!! $row('Langue principale', $language) !!}
        </table>
      </td>
      <td>
        <table class="kv">
          {!! $row('Promotion', $promotion ? 'Promotion '.$promotion : null) !!}
          {!! $row('Programme', $programName) !!}
        </table>
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">Parcours académique et professionnel</div>
  <table class="two">
    <tr>
      <td>
        <table class="kv">
          {!! $row('Diplôme le plus élevé', $degree) !!}
          {!! $row("Année d'obtention", $candidature->annee_diplome) !!}
          {!! $row('Établissement', $candidature->institut) !!}
        </table>
      </td>
      <td>
        <table class="kv">
          {!! $row('Spécialité du diplôme', $candidature->specialite_diplome) !!}
          {!! $row('Situation actuelle', $profStatus) !!}
          {!! $row('Employeur', $candidature->employeur) !!}
        </table>
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">Confirmation de dépôt</div>
  <div class="note">
    Votre candidature a été enregistrée avec succès. Ce récépissé confirme la réception de votre
    dossier et ne vaut pas admission : le dossier reste soumis à la vérification des pièces justificatives.
    <span class="certif">Le candidat certifie l'exactitude des informations transmises et s'engage à respecter les conditions de l'appel à candidatures et le règlement du PSSFP.</span>
  </div>
</div>

<table class="verify">
  <tr>
    <td class="qr">@if($qrSvg)<img src="{{ $qrSvg }}" alt="QR de vérification">@endif</td>
    <td>
      <div class="vt">Vérifier l'authenticité du récépissé</div>
      <div class="vmeta">
        Scannez le code pour consulter le statut du dossier.<br>
        Dossier <strong>{{ $candidature->numero_dossier }}</strong>
        &nbsp;·&nbsp; Code de vérification <span class="vcode">{{ $vcodePlaceholder }}</span><br>
        Généré le {{ $generation }}
      </div>
    </td>
  </tr>
</table>

<div class="page-break"></div>

{{-- ===================== PAGE 2 — FICHE DE CONTRÔLE ADMINISTRATIF ===================== --}}
<table class="head">
  <tr>
    <td class="fr">
      <span class="big">RÉPUBLIQUE DU CAMEROUN</span><br>
      Paix – Travail – Patrie<br>
      Ministère des Finances<br>
      Programme Supérieur de Spécialisation<br>en Finances Publiques
    </td>
    <td class="logo">@if($logoSrc)<img src="{{ $logoSrc }}" alt="PSSFP">@endif</td>
    <td class="en">
      <span class="big">REPUBLIC OF CAMEROON</span><br>
      Peace – Work – Fatherland<br>
      Ministry of Finance<br>
      Advanced Program of Specialisation<br>in Public Finance
    </td>
  </tr>
</table>
<div class="band"></div>

<div class="pagemark">Page 2 sur 2 — Usage interne PSSFP</div>

<div class="adminbar">COPIE ADMINISTRATION — FICHE DE CONTRÔLE DE LA CANDIDATURE</div>

<div class="card">
  <div class="h">Résumé du candidat</div>
  <table class="id">
    <tr>
      <td class="info">
        <table class="two">
          <tr>
            <td>
              <table class="kv">
                {!! $row('Numéro de dossier', $candidature->numero_dossier) !!}
                {!! $row('Nom complet', $fullName) !!}
                {!! $row('Téléphone', $phone) !!}
                {!! $row('E-mail', $candidature->email) !!}
              </table>
            </td>
            <td>
              <table class="kv">
                {!! $row('Spécialité demandée', $candidature->specialite) !!}
                {!! $row('Type de formation', $studyMode) !!}
                {!! $row('Soumis le', $submission) !!}
              </table>
            </td>
          </tr>
        </table>
      </td>
      <td class="ph">
        @if($photoSrc)
          <div class="photo"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
        @else
          <div class="photo-empty">Photo à déposer</div>
        @endif
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">Suivi du dossier</div>
  <table class="checks check">
    <tr>
      <td><span class="box">☐</span> Candidature en ligne reçue</td>
      <td><span class="box">☐</span> Paiement des frais effectué</td>
    </tr>
    <tr>
      <td><span class="box">☐</span> Photo reçue</td>
      <td><span class="box">☐</span> Dossier complet</td>
    </tr>
    <tr>
      <td><span class="box">☐</span> CNI / passeport reçu</td>
      <td><span class="box">☐</span> Dossier incomplet</td>
    </tr>
    <tr>
      <td><span class="box">☐</span> Diplôme(s) reçu(s)</td>
      <td><span class="box">☐</span> Dossier transmis au comité d'admission</td>
    </tr>
    <tr>
      <td><span class="box">☐</span> Relevés / justificatifs reçus</td>
      <td></td>
    </tr>
  </table>
  <div style="margin-top:2.5mm; font-size:8.4pt; color:#6B6B6B;">Pièces manquantes / observations :</div>
  <div class="writeline" style="margin-top:1.5mm;"></div>
  <div class="writeline" style="margin-top:2.5mm;"></div>
</div>

<div class="card">
  <div class="h">Décision / traitement administratif</div>
  <div style="margin-bottom:2mm;">
    <span class="opt">☐ Recevable</span>
    <span class="opt">☐ À compléter</span>
    <span class="opt">☐ Rejeté</span>
    <span class="opt">☐ Présélectionné</span>
    <span class="opt">☐ Admis</span>
    <span class="opt">☐ Non admis</span>
  </div>
  <table class="two" style="margin-top:1mm;">
    <tr>
      <td>
        <div style="font-size:8.4pt; color:#6B6B6B;">Agent ayant vérifié le dossier</div>
        <div class="writeline" style="margin-top:5mm;"></div>
        <div style="font-size:8.4pt; color:#6B6B6B; margin-top:2mm;">Date de vérification</div>
        <div class="writeline" style="margin-top:5mm;"></div>
      </td>
      <td>
        <div style="font-size:8.4pt; color:#6B6B6B;">Signature et cachet du PSSFP</div>
        <div style="border:0.4pt solid #cfc8dc; border-radius:2mm; height:26mm; margin-top:2mm;"></div>
      </td>
    </tr>
  </table>
</div>

<table class="verify">
  <tr>
    <td class="qr">@if($qrSvg)<img src="{{ $qrSvg }}" alt="QR de vérification">@endif</td>
    <td>
      <div class="vt">Vérification numérique</div>
      <div class="vmeta">
        Dossier <strong>{{ $candidature->numero_dossier }}</strong>
        &nbsp;·&nbsp; Code <span class="vcode">{{ $vcodePlaceholder }}</span><br>
        Identifiant technique : {{ $candidature->uuid }}<br>
        Généré le {{ $generation }} &nbsp;·&nbsp; <span style="font-size:6.5pt; color:#9a9a9a;">SHA-256 {{ $hashPlaceholder }}</span>
      </div>
    </td>
  </tr>
</table>

</body>
</html>
