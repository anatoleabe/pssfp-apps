<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Récépissé de dépôt de candidature - {{ $candidature->numero_dossier }}</title>
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
    $hasValue = function ($value) {
        if ($value === null) {
            return false;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' && ! in_array(mb_strtolower($normalized), ['null', 'undefined', 'n/a', '—', '-'], true);
    };

    $translations = [
        'Civilité' => 'Title',
        'Nom complet' => 'Full name',
        "Nom d'épouse" => 'Married name',
        'Date de naissance' => 'Date of birth',
        'Lieu de naissance' => 'Place of birth',
        'Nationalité' => 'Nationality',
        'Téléphone' => 'Phone number',
        'Adresse e-mail' => 'Email address',
        'Numéro de dossier' => 'Application number',
        'Spécialité demandée' => 'Requested specialisation',
        'Mode de formation' => 'Study mode',
        'Langue principale' => 'Primary language',
        'Promotion' => 'Cohort',
        'Campagne' => 'Application campaign',
        'Année académique' => 'Academic year',
        'Diplôme le plus élevé' => 'Highest qualification',
        "Année d'obtention" => 'Year awarded',
        'Établissement' => 'Institution',
        'Spécialité du diplôme' => 'Field of study',
        'Situation actuelle' => 'Current status',
        'Employeur' => 'Employer',
        'Structure / employeur' => 'Organisation / employer',
        'Soumis le' => 'Submitted on',
        'Photo conforme' => 'Compliant photograph',
        'CNI ou passeport reçu' => 'National ID card or passport received',
        'Diplôme reçu' => 'Qualification received',
        'Relevés ou justificatifs reçus' => 'Transcripts or supporting documents received',
        'Frais de candidature réglés' => 'Application fee paid',
        'Dossier complet' => 'Complete application',
        'Dossier à compléter' => 'Application to be completed',
        "Transmis au comité d'admission" => 'Forwarded to the Admissions Committee',
        'Pièces manquantes ou observations' => 'Missing documents or comments',
        'Recevable' => 'Eligible for review',
        'À compléter' => 'To be completed',
        'Irrecevable' => 'Ineligible',
        'Présélectionné' => 'Shortlisted',
        'Admis' => 'Admitted',
        'Non admis' => 'Not admitted',
        "Nom de l'agent vérificateur" => "Reviewing officer's name",
        'Date de vérification' => 'Review date',
        'Visa de la scolarité, signature et cachet' => 'Academic Office approval, signature and stamp',
        'Dossier' => 'Application',
        'Code de vérification' => 'Verification code',
        'Généré le' => 'Generated on',
    ];
    $label = function ($fr, $en = null) use ($translations) {
        $english = $en ?: ($translations[$fr] ?? null);
        $html = '<span class="label-fr">'.e($fr).'</span>';
        if ($english) {
            $html .= '<span class="label-en">'.e($english).'</span>';
        }
        return '<span class="field-label">'.$html.'</span>';
    };
    $row = function ($fr, $value, $en = null) use ($hasValue, $label) {
        if (! $hasValue($value)) {
            return '';
        }
        return '<tr><td class="k">'.$label($fr, $en).'</td><td class="v">'.e($value).'</td></tr>';
    };
    $field = function ($fr, $value, $en = null) use ($hasValue, $label) {
        if (! $hasValue($value)) {
            return '';
        }
        return '<div class="field">'.$label($fr, $en).'<span class="field-value">'.e($value).'</span></div>';
    };
    $section = function ($fr, $en) {
        return '<span class="section-fr">'.e($fr).'</span><span class="section-en">'.e($en).'</span>';
    };
    $choice = function ($fr, $en = null) use ($label) {
        return '<span class="choice"><span class="box">□</span><span class="choice-label">'.$label($fr, $en).'</span></span>';
    };

    /* ---------- Valeurs dynamiques ---------- */
    $fullName = trim(($candidature->prenom ?? '').' '.($candidature->nom ?? ''));
    $birthDate = $fmtDate($candidature->date_naissance, false);
    $birthPlace = $candidature->lieu_naissance ?: null;
    $nationality = optional($candidature->paysNationalite)->nom ?? $candidature->nationalite;
    $phone = $fmtPhone($candidature->indicatif1, $candidature->telephone1, $candidature->phone_e164);
    $studyMode = match ($candidature->type_etude) {
        'presentiel' => 'Présentiel',
        'distanciel' => 'Distanciel',
        default => $candidature->type_etude,
    };
    $language = match ($candidature->premiere_langue) {
        'fr' => 'Français',
        'en' => 'Anglais',
        default => $candidature->premiere_langue,
    };
    $profStatus = match ($candidature->statut_actuel) {
        'Etudiant' => 'Étudiant',
        'Sans-emploi' => 'Sans emploi / en recherche d’emploi',
        'Fonctionnaire' => 'Fonctionnaire titulaire',
        'Contractuel-Etat' => 'Agent contractuel de l’État',
        'Etablissement-public' => 'Agent d’un établissement public',
        'Entreprise-publique' => 'Salarié(e) d’une entreprise publique',
        'Fonctionnaire-Contractuel' => 'Fonctionnaire / Contractuel',
        'Prive' => 'Salarié(e) du secteur privé',
        'Independant' => 'Indépendant(e) / profession libérale',
        'ONG-International' => 'ONG / organisation internationale',
        'Autre' => 'Autre situation professionnelle',
        default => $candidature->statut_actuel,
    };
    $degree = $candidature->diplome_obtenu ?: null;
    $submission = $fmtDate($candidature->submitted_at ?? $generatedAt);
    $generation = $fmtDate($generatedAt);
    $promotion = optional($campagne)->promotion_numero;
    $academicYear = optional($campagne)->nom;
    $campaignYear = optional($campagne)->opens_at?->format('Y');
    if (! $campaignYear && preg_match('/20\d{2}/', (string) optional($campagne)->slug, $yearMatch)) {
        $campaignYear = $yearMatch[0];
    }
    if ($academicYear && ! str_contains(mb_strtolower($academicYear), 'année académique')) {
        $academicYear = $campaignYear ? 'Année académique '.$campaignYear.'-'.((int) $campaignYear + 1) : null;
    }
    $academicYearValue = $academicYear ? preg_replace('/^Année académique\s+/iu', '', $academicYear) : null;
    [$statusLabel, $statusEnglish, $statusClass] = match ($candidature->statut) {
        'candidat' => ['Candidature soumise', 'Application submitted', 'ok'],
        'accepte' => ['Admis', 'Admitted', 'ok'],
        'refuse' => ['Non admis', 'Not admitted', 'red'],
        default => ['Dossier enregistré', 'Application recorded', 'ok'],
    };
@endphp
<style>
  @font-face {
    font-family: 'Source Serif 4';
    font-style: normal;
    font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Regular.ttf') }}") format('truetype');
  }
  @font-face {
    font-family: 'Source Serif 4';
    font-style: normal;
    font-weight: 700;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype');
  }
  @font-face {
    font-family: 'Source Sans 3';
    font-style: normal;
    font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype');
  }
  @font-face {
    font-family: 'Source Sans 3';
    font-style: italic;
    font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Italic.ttf') }}") format('truetype');
  }
  @font-face {
    font-family: 'Source Sans 3';
    font-style: normal;
    font-weight: 700;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype');
  }

  @page { margin: 7mm 11mm 10mm; }
  * { box-sizing: border-box; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 8.5pt; color: #29282b; margin: 0; line-height: 1.18; }

  .wm { position: fixed; top: 44%; left: 50%; transform: translate(-50%, -50%); z-index: 0; }
  .wm img { width: 80mm; opacity: 0.03; }

  table.head { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.head td { vertical-align: middle; text-align: center; font-family: 'Source Serif 4', Georgia, serif; color: #29282b; }
  table.head td.fr, table.head td.en { width: 32%; font-size: 7.7pt; line-height: 1.08; }
  table.head td.logo { width: 36%; }
  table.head td.logo img { height: 16mm; }
  .head .big { display: inline-block; font-size: 9pt; font-weight: 700; letter-spacing: 0.25mm; margin-bottom: 0.15mm; }
  .head .motto { font-size: 7.7pt; }
  .head .ministry, .head .program { font-size: 7.9pt; }
  .band { height: 1.2mm; background: #4A2E67; border-radius: 1mm; margin: 0.8mm 0 1mm; }

  .confirm { background: #F7F5FB; border: 0.4pt solid #E6E2EE; border-radius: 2.3mm; padding: 1.5mm 3.2mm; margin-bottom: 0.9mm; }
  .confirm .title { font-family: 'Source Serif 4', Georgia, serif; font-size: 18pt; line-height: 1.05; font-weight: 700; color: #4A2E67; letter-spacing: -0.1mm; }
  .confirm .title-en { font-size: 7.2pt; line-height: 1; font-style: italic; color: #756c7e; margin-top: 0.4mm; }
  .confirm .prog { font-size: 7.9pt; color: #625e65; margin-top: 0.55mm; }
  table.cline { width: 100%; border-collapse: collapse; margin-top: 0.8mm; }
  table.cline td { vertical-align: middle; }
  .dossier-lbl .label-fr { font-size: 8.5pt; letter-spacing: 0.65mm; text-transform: uppercase; color: #5f5a63; }
  .dossier-num { font-family: 'Source Serif 4', Georgia, serif; font-size: 17pt; line-height: 1; font-weight: 700; color: #29282b; letter-spacing: 0.2mm; margin-top: 0.3mm; }
  .submitted { font-size: 8.3pt; color: #454148; margin-top: 0.5mm; }
  .submitted .meta-en { color: #77717b; font-style: italic; font-size: 6.5pt; }
  .badge { display: inline-block; border-radius: 6mm; padding: 1.25mm 4.2mm; font-size: 8.7pt; line-height: 1.05; font-weight: 700; text-align: center; }
  .badge-en { display: block; margin-top: 0.35mm; font-size: 6.5pt; font-weight: 400; font-style: italic; }
  .badge.ok { background: #E7F4E8; color: #1F6B2B; border: 0.4pt solid #B8DDBD; }
  .badge.red { background: #FBEAE9; color: #A3271F; border: 0.4pt solid #E7B7B3; }

  .card { border: 0.4pt solid #E6E2EE; border-radius: 2.3mm; padding: 1.25mm 2.8mm; margin-bottom: 0.8mm; page-break-inside: avoid; }
  .card.accent { background: #FBFAFD; }
  .card > .h { color: #4A2E67; margin-bottom: 0.8mm; }
  .section-fr { display: block; font-size: 10.2pt; line-height: 1.02; font-weight: 700; }
  .section-en { display: block; font-size: 6.3pt; line-height: 0.96; font-weight: 400; font-style: italic; color: #7b7284; margin-top: 0.15mm; }

  .field-label { display: inline-block; line-height: 1; }
  .label-fr { display: block; font-size: 8.2pt; line-height: 0.98; font-weight: 400; color: #555158; }
  .label-en { display: block; font-size: 6.1pt; line-height: 0.95; font-weight: 400; font-style: italic; color: #827d85; margin-top: 0.15mm; white-space: normal; }
  table.kv { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.kv td { padding: 0.42mm 0; vertical-align: middle; }
  table.kv td.k { width: 41%; padding-right: 2mm; }
  table.kv td.v { width: 59%; font-size: 9pt; line-height: 1.12; color: #242326; font-weight: 700; overflow-wrap: break-word; }

  table.fields { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.fields td { width: 50%; vertical-align: top; padding: 0.28mm 2mm 0.28mm 0; }
  table.fields td + td { padding-right: 0; padding-left: 2.2mm; border-left: 0.4pt solid #EEEAF2; }
  table.fields.three td { width: 33.333%; }
  table.fields.three td[colspan="2"] { width: 66.666%; }
  table.fields.four td { width: 25%; }
  table.fields.five td { width: 20%; }
  .field { min-height: 0; }
  .field .label-fr, .field .label-en { white-space: nowrap; }
  .field-value { display: block; margin-top: 0.25mm; font-size: 8.6pt; line-height: 1.02; color: #242326; font-weight: 700; overflow-wrap: break-word; }
  table.two { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.two > tbody > tr > td, table.two > tr > td { width: 50%; vertical-align: top; padding-right: 3mm; }
  table.two > tbody > tr > td + td, table.two > tr > td + td { padding-right: 0; padding-left: 3mm; border-left: 0.4pt solid #EEEAF2; }

  table.id { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.id td.info { vertical-align: top; }
  table.id td.ph { vertical-align: top; width: 27mm; padding-left: 3mm; text-align: center; }
  .photo { width: 22mm; height: 28mm; border-radius: 2mm; border: 0.5pt solid #C9C2D6; overflow: hidden; margin: 0 auto; }
  .photo img { width: 22mm; height: 28mm; }
  .photo-empty { width: 22mm; height: 28mm; border-radius: 2mm; border: 0.6pt dashed #B9B0CC; background: #F7F5FB; color: #766e7e; font-size: 6.5pt; line-height: 1.1; padding: 6.2mm 1.2mm 0; text-align: center; margin: 0 auto; }
  .photo-empty .en { display: block; margin-top: 0.8mm; font-style: italic; color: #8b8492; }
  .photo-cap { font-size: 7.2pt; color: #68636b; margin-top: 0.6mm; line-height: 1; }
  .photo-cap .en { display: block; font-size: 6.4pt; font-style: italic; color: #88828b; margin-top: 0.25mm; }

  .wide-field { margin-bottom: 0.55mm; }
  .wide-field .value { display: block; margin-top: 0.45mm; font-size: 10.2pt; line-height: 1.12; font-weight: 700; color: #4A2E67; }
  .note { font-size: 8.1pt; color: #413e43; line-height: 1.27; }
  .note .certif { display: block; margin-top: 0.8mm; font-size: 7.4pt; color: #6f6972; font-style: italic; }

  table.verify { width: 100%; border-collapse: collapse; background: #F7F5FB; border: 0.4pt solid #E6E2EE; border-radius: 2.3mm; margin-bottom: 1mm; }
  table.verify td { vertical-align: middle; padding: 1.45mm 3mm; }
  table.verify td.qr { width: 23mm; text-align: center; }
  table.verify td.qr img { width: 19mm; height: 19mm; }
  .verify .vt .section-fr { font-size: 9.8pt; }
  .verify .vmeta { font-size: 7.9pt; line-height: 1.25; color: #4e4a50; margin-top: 0.7mm; }
  .verify .instruction-en { font-size: 6.5pt; font-style: italic; color: #7c7680; }
  table.verify-meta { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 0.6mm; }
  table.verify-meta td { width: 33.333%; padding: 0 2mm 0 0; vertical-align: top; }
  table.verify-meta .meta-value { display: block; margin-top: 0.35mm; font-size: 8pt; font-weight: 700; color: #302d32; }
  .meta-label { color: #5d5860; }
  .meta-label em { display: block; font-size: 6.3pt; color: #858087; line-height: 1; }
  .vcode { font-weight: 700; letter-spacing: 0.45mm; color: #29282b; }
  table.closing { width: 100%; border-collapse: separate; border-spacing: 1mm 0; margin: 0 -1mm 1mm; table-layout: fixed; }
  table.closing > tbody > tr > td, table.closing > tr > td { vertical-align: top; border: 0.4pt solid #E6E2EE; border-radius: 2.3mm; padding: 0.8mm 2.2mm; }
  table.closing td.confirm-side { width: 56%; }
  table.closing td.verify-side { width: 44%; background: #F7F5FB; }
  table.committee-closing td.committee-decision { width: 72%; }
  table.committee-closing td.committee-verify { width: 28%; background: #F7F5FB; }
  table.review-row td.review-academic { width: 45%; }
  table.review-row td.review-documents { width: 55%; }
  table.mini-verify { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.mini-verify td { vertical-align: middle; }
  table.mini-verify td.qr { width: 18mm; padding-right: 2mm; }
  table.mini-verify td.qr img { width: 14mm; height: 14mm; }
  .mini-meta { margin-top: 0.35mm; font-size: 7pt; line-height: 1.1; color: #4e4a50; }
  .mini-meta .en { font-size: 6.1pt; font-style: italic; color: #858087; }
  table.closing .note { font-size: 7.5pt; line-height: 1.17; }
  table.closing .note .certif { margin-top: 0.45mm; font-size: 6.8pt; }
  table.closing .vt .section-fr { font-size: 9.1pt; }
  table.closing .vt .section-en { font-size: 5.9pt; }

  .foot { position: fixed; bottom: -1mm; left: 0; right: 0; height: 8mm; padding-top: 1.2mm; font-size: 7.1pt; line-height: 1.1; color: #77717a; border-top: 0.4pt solid #E2DDE7; }
  .foot table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .foot td { vertical-align: top; }
  .foot td.l { width: 42%; text-align: left; }
  .foot td.c { width: 16%; text-align: center; }
  .foot td.r { width: 42%; text-align: right; }
  .foot .en { display: block; margin-top: 0.25mm; font-size: 6.4pt; font-style: italic; color: #918b94; }

  .adminbar { background: #4A2E67; color: #fff; border-radius: 2mm; padding: 1.5mm 3.2mm; margin-bottom: 1mm; }
  .adminbar .fr { display: block; font-size: 9.7pt; line-height: 1.05; font-weight: 700; letter-spacing: 0.3mm; }
  .adminbar .en { display: block; margin-top: 0.45mm; font-size: 6.8pt; line-height: 1; font-weight: 400; font-style: italic; color: #E9E1F1; letter-spacing: 0.16mm; }
  .subh { color: #4A2E67; margin-bottom: 0.5mm; }
  .subh .section-fr { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.18mm; }
  .subh .section-en { font-size: 6.4pt; }
  .choice { display: block; min-height: 4.2mm; padding: 0.25mm 0; }
  .box { display: inline-block; width: 4mm; vertical-align: top; font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #4A2E67; }
  .choice-label { display: inline-block; width: 84%; vertical-align: top; }
  .choice .label-fr { font-size: 8.2pt; color: #353237; }
  .choice .label-en { font-size: 6.2pt; }
  .writeline { border-bottom: 0.4pt solid #CFC8DC; height: 2.7mm; }
  table.decision { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.decision td { width: 50%; vertical-align: top; padding-right: 3mm; }
  table.decision td + td { padding-right: 0; padding-left: 3mm; border-left: 0.4pt solid #EEEAF2; }

  .page-two { page-break-before: always; }
  .page-two table.head td.fr, .page-two table.head td.en { font-size: 7.5pt; line-height: 1.08; }
  .page-two table.head td.logo img { height: 12mm; }
  .page-two .head .big { font-size: 8.3pt; }
  .page-two .head .motto { font-size: 7pt; }
  .page-two .head .ministry, .page-two .head .program { font-size: 7.2pt; }
  .page-two .band { margin: 0.8mm 0 1mm; }
  .page-two .card { padding: 0.95mm 2.4mm; margin-bottom: 0.65mm; }
  .page-two .card > .h { margin-bottom: 0.55mm; }
  .page-two .section-fr { font-size: 9.3pt; }
  .page-two .section-en { font-size: 5.9pt; }
  .page-two table.kv td { padding: 0.25mm 0; }
  .page-two table.kv td.k { width: 39%; padding-right: 1.3mm; }
  .page-two table.kv td.v { width: 61%; font-size: 8.7pt; }
  .page-two table.fields td { padding-top: 0.1mm; padding-bottom: 0.1mm; }
  .page-two .field { min-height: 0; }
  .page-two .field-value { margin-top: 0.12mm; font-size: 8.2pt; line-height: 0.98; }
  .page-two .label-fr { font-size: 7.7pt; }
  .page-two .label-en { font-size: 5.8pt; }
  .page-two table.id td.ph { width: 22mm; padding-left: 2mm; }
  .page-two .photo, .page-two .photo img { width: 15mm; height: 19mm; }
  .page-two .photo-empty { width: 15mm; height: 19mm; padding: 3.8mm 0.6mm 0; }
  .page-two .subh { margin-bottom: 0.25mm; }
  .page-two .subh .section-fr { font-size: 8pt; }
  .page-two .subh .section-en { font-size: 6.1pt; }
  .page-two .choice { min-height: 3mm; padding: 0; }
  .page-two .choice .label-fr { font-size: 7.6pt; }
  .page-two .choice .label-en { font-size: 5.7pt; }
  .page-two .writeline { height: 2.2mm; }
  .page-two table.verify td { padding: 0.6mm 2.5mm; }
  .page-two table.verify td.qr { width: 18mm; }
  .page-two table.verify td.qr img { width: 14mm; height: 14mm; }
  .page-two .verify .vmeta { margin-top: 0.35mm; line-height: 1.15; }
</style>
</head>
<body>

<div class="wm">@if($logoSrc)<img src="{{ $logoSrc }}" alt="">@endif</div>

{{-- Le centre du pied est rempli par RecipisseService via le canvas Dompdf. --}}
<div class="foot">
  <table>
    <tr>
      <td class="l">
        {{ $contact['adresse'] }}<br>
        {{ $contact['tel'] }} · {{ $contact['email'] }}
      </td>
      <td class="c"></td>
      <td class="r">
        Document généré électroniquement
        <span class="en">Electronically generated document</span>
      </td>
    </tr>
  </table>
</div>

{{-- ===================== PAGE 1 - COPIE CANDIDAT ===================== --}}
<table class="head">
  <tr>
    <td class="fr">
      <span class="big">RÉPUBLIQUE DU CAMEROUN</span><br>
      <span class="motto">Paix - Travail - Patrie</span><br>
      <span class="ministry">Ministère des Finances</span><br>
      <span class="program">Programme Supérieur de Spécialisation<br>en Finances Publiques</span>
    </td>
    <td class="logo">@if($logoSrc)<img src="{{ $logoSrc }}" alt="PSSFP">@endif</td>
    <td class="en">
      <span class="big">REPUBLIC OF CAMEROON</span><br>
      <span class="motto">Peace - Work - Fatherland</span><br>
      <span class="ministry">Ministry of Finance</span><br>
      <span class="program">Advanced Program of Specialisation<br>in Public Finance</span>
    </td>
  </tr>
</table>
<div class="band"></div>

<div class="confirm">
  <div class="title">Récépissé de dépôt de candidature</div>
  <div class="title-en">Application submission receipt</div>
  <div class="prog">
    {{ $programName }}
    @if($promotion) - Promotion {{ $promotion }}@endif
    @if($campaignYear) - Campagne {{ $campaignYear }}@endif
    @if($academicYear) - {{ $academicYear }}@endif
  </div>
  <table class="cline">
    <tr>
      <td>
        <div class="dossier-lbl">{!! $label('Numéro de dossier') !!}</div>
        <div class="dossier-num">{{ $candidature->numero_dossier }}</div>
        @if($submission)
          <div class="submitted"><strong>Soumis le</strong> <span class="meta-en">Submitted on</span> · {{ $submission }}</div>
        @endif
      </td>
      <td style="text-align:right;">
        <span class="badge {{ $statusClass }}">{{ $statusLabel }}<span class="badge-en">{{ $statusEnglish }}</span></span>
      </td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">{!! $section('Profil du candidat', 'Applicant profile') !!}</div>
  <table class="id">
    <tr>
      <td class="info">
        <table class="fields three">
          <tr>
            <td colspan="2">{!! $field('Nom complet', $fullName) !!}</td>
            <td>@if($candidature->genre === 'F' && filled($candidature->epouse) && mb_strtolower(trim($candidature->epouse)) !== mb_strtolower(trim($fullName))){!! $field("Nom d'épouse", $candidature->epouse) !!}@endif</td>
          </tr>
          <tr>
            <td>{!! $field('Civilité', $candidature->civilite) !!}</td>
            <td>{!! $field('Date de naissance', $birthDate) !!}</td>
            <td>{!! $field('Lieu de naissance', $birthPlace) !!}</td>
          </tr>
          <tr>
            <td>{!! $field('Nationalité', $nationality) !!}</td>
            <td>{!! $field('Téléphone', $phone) !!}</td>
            <td>{!! $field('Adresse e-mail', $candidature->email) !!}</td>
          </tr>
        </table>
      </td>
      <td class="ph">
        @if($photoSrc)
          <div class="photo"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
        @else
          <div class="photo-empty">Photo à déposer auprès de la scolarité<span class="en">Photo to be submitted to the Academic Office</span></div>
        @endif
        <div class="photo-cap">Photographie<span class="en">Photograph</span></div>
      </td>
    </tr>
  </table>
</div>

<div class="card accent">
  <div class="h">{!! $section('Choix académique', 'Academic choice') !!}</div>
  @if($candidature->specialite)
    <div class="wide-field">
      {!! $label('Spécialité demandée') !!}
      <span class="value">{{ $candidature->specialite }}</span>
    </div>
  @endif
  <table class="fields five">
    <tr>
      <td>{!! $field('Mode de formation', $studyMode) !!}</td>
      <td>{!! $field('Langue principale', $language) !!}</td>
      <td>{!! $field('Promotion', $promotion ? (string) $promotion : null) !!}</td>
      <td>{!! $field('Campagne', $campaignYear) !!}</td>
      <td>{!! $field('Année académique', $academicYearValue) !!}</td>
    </tr>
  </table>
</div>

<div class="card">
  <div class="h">{!! $section('Parcours académique et situation professionnelle', 'Academic background and professional status') !!}</div>
  <table class="fields three">
    <tr>
      <td>{!! $field('Diplôme le plus élevé', $degree) !!}</td>
      <td>{!! $field('Spécialité du diplôme', $candidature->specialite_diplome) !!}</td>
      <td>{!! $field("Année d'obtention", $candidature->annee_diplome) !!}</td>
    </tr>
    <tr>
      <td>{!! $field('Établissement', $candidature->institut) !!}</td>
      <td>{!! $field('Situation actuelle', $profStatus) !!}</td>
      <td>{!! $field('Employeur', $candidature->employeur) !!}</td>
    </tr>
    <tr>
      <td>{!! $field('Fonction / poste', $candidature->fonction_actuelle) !!}</td>
      <td>{!! $field('Adresse professionnelle', $candidature->adresse_employeur) !!}</td>
      <td>{!! $field('Téléphone professionnel', $candidature->tel_employeur) !!}</td>
    </tr>
  </table>
</div>

<table class="closing">
  <tr>
    <td class="confirm-side">
      <div class="h">{!! $section('Confirmation de dépôt', 'Submission confirmation') !!}</div>
      <div class="note">
        Votre candidature est enregistrée. Ce récépissé en confirme la réception sous réserve de vérification
        et ne vaut pas admission définitive.
        <span class="certif">Le candidat certifie l'exactitude des informations transmises et accepte le règlement du PSSFP.</span>
      </div>
    </td>
    <td class="verify-side">
      <table class="mini-verify">
        <tr>
          <td class="qr">@if($qrSvg)<img src="{{ $qrSvg }}" alt="QR de vérification">@endif</td>
          <td>
            <div class="vt">{!! $section("Vérifier l'authenticité du récépissé", 'Verify the authenticity of this receipt') !!}</div>
            <div class="mini-meta">
              Dossier <span class="en">Application</span> <strong>{{ $candidature->numero_dossier }}</strong><br>
              Code de vérification <span class="en">Verification code</span> <span class="vcode">{{ $vcodePlaceholder }}</span><br>
              Généré le <span class="en">Generated on</span> {{ $generation }}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

{{-- ===================== PAGE 2 - FICHE DU COMITÉ DE PILOTAGE ===================== --}}
<div class="page-two">
<table class="head">
  <tr>
    <td class="fr">
      <span class="big">RÉPUBLIQUE DU CAMEROUN</span><br>
      <span class="motto">Paix - Travail - Patrie</span><br>
      <span class="ministry">Ministère des Finances</span><br>
      <span class="program">Programme Supérieur de Spécialisation<br>en Finances Publiques</span>
    </td>
    <td class="logo">@if($logoSrc)<img src="{{ $logoSrc }}" alt="PSSFP">@endif</td>
    <td class="en">
      <span class="big">REPUBLIC OF CAMEROON</span><br>
      <span class="motto">Peace - Work - Fatherland</span><br>
      <span class="ministry">Ministry of Finance</span><br>
      <span class="program">Advanced Program of Specialisation<br>in Public Finance</span>
    </td>
  </tr>
</table>
<div class="band"></div>

<div class="adminbar">
  <span class="fr">COPIE DU COMITÉ DE PILOTAGE - FICHE D'ANALYSE</span>
  <span class="en">STEERING COMMITTEE COPY - ASSESSMENT SHEET</span>
</div>

<div class="card">
  <div class="h">{!! $section('Résumé du candidat', 'Applicant summary') !!}</div>
  <table class="id">
    <tr>
      <td class="info">
        <table class="fields three">
          <tr>
            <td colspan="2">{!! $field('Nom complet', $fullName) !!}</td>
            <td>{!! $field('Spécialité demandée', $candidature->specialite) !!}</td>
          </tr>
          <tr>
            <td>{!! $field('Numéro de dossier', $candidature->numero_dossier) !!}</td>
            <td>{!! $field('Promotion', $promotion ? 'Promotion '.$promotion : null) !!}</td>
            <td>{!! $field('Campagne', $campaignYear) !!}</td>
          </tr>
          <tr>
            <td>{!! $field('Année académique', $academicYearValue) !!}</td>
            <td>{!! $field('Date de naissance', $birthDate) !!}</td>
            <td>{!! $field('Lieu de naissance', $birthPlace) !!}</td>
          </tr>
          <tr>
            <td>{!! $field('Nationalité', $nationality) !!}</td>
            <td>{!! $field('Téléphone', $phone) !!}</td>
            <td>{!! $field('Adresse e-mail', $candidature->email) !!}</td>
          </tr>
          <tr>
            <td>{!! $field('Mode de formation', $studyMode) !!}</td>
            <td>{!! $field('Langue principale', $language) !!}</td>
            <td>{!! $field('Soumis le', $submission) !!}</td>
          </tr>
        </table>
      </td>
      <td class="ph">
        @if($photoSrc)
          <div class="photo"><img src="{{ $photoSrc }}" alt="Photo du candidat"></div>
        @else
          <div class="photo-empty">Photo à déposer<span class="en">Photo to be submitted</span></div>
        @endif
      </td>
    </tr>
  </table>
</div>

<table class="closing review-row">
  <tr>
    <td class="review-academic">
      <div class="h">{!! $section('Parcours académique et professionnel', 'Academic and professional background') !!}</div>
      <table class="fields">
        <tr>
          <td><div class="subh">{!! $section('Parcours académique', 'Academic background') !!}</div></td>
          <td><div class="subh">{!! $section('Situation professionnelle', 'Professional status') !!}</div></td>
        </tr>
        <tr>
          <td>
            {!! $field('Diplôme le plus élevé', $degree) !!}
            {!! $field('Spécialité du diplôme', $candidature->specialite_diplome) !!}
            {!! $field('Établissement', $candidature->institut) !!}
            {!! $field("Année d'obtention", $candidature->annee_diplome) !!}
          </td>
          <td>
            {!! $field('Situation actuelle', $profStatus) !!}
            {!! $field('Structure / employeur', $candidature->employeur) !!}
            {!! $field('Fonction / poste', $candidature->fonction_actuelle) !!}
          </td>
        </tr>
      </table>
    </td>
    <td class="review-documents">
      <div class="h">{!! $section('Vérification administrative du dossier', 'Administrative application review') !!}</div>
      <table class="fields three">
        <tr>
          <td>
            <div class="subh">{!! $section("Pièces d'identité", 'Identity documents') !!}</div>
            {!! $choice('Photo conforme') !!}
            {!! $choice('CNI ou passeport reçu') !!}
          </td>
          <td>
            <div class="subh">{!! $section('Pièces académiques', 'Academic documents') !!}</div>
            {!! $choice('Diplôme reçu') !!}
            {!! $choice('Relevés ou justificatifs reçus') !!}
          </td>
          <td>
            <div class="subh">{!! $section('Validation administrative', 'Administrative validation') !!}</div>
            {!! $choice('Frais de candidature réglés') !!}
            {!! $choice('Dossier complet') !!}
            {!! $choice('Dossier à compléter') !!}
            {!! $choice("Transmis au comité d'admission") !!}
          </td>
        </tr>
      </table>
      <div style="margin-top:0.55mm;">{!! $label('Pièces manquantes ou observations') !!}</div>
      <div class="writeline"></div>
      <div class="writeline"></div>
    </td>
  </tr>
</table>

<table class="closing committee-closing">
  <tr>
    <td class="committee-decision">
      <div class="h">{!! $section('Décision du Comité de Pilotage', 'Steering Committee decision') !!}</div>
      <table class="fields four">
        <tr>
          <td>
            <div class="subh">{!! $section('Contrôle administratif', 'Administrative review') !!}</div>
            {!! $choice('Recevable') !!}
            {!! $choice('À compléter') !!}
            {!! $choice('Irrecevable') !!}
          </td>
          <td>
            <div class="subh">{!! $section('Décision du comité', 'Committee decision') !!}</div>
            {!! $choice('Présélectionné') !!}
            {!! $choice('Admis') !!}
            {!! $choice('Non admis') !!}
          </td>
          <td>
            {!! $label("Nom de l'agent vérificateur") !!}
            <div class="writeline"></div>
            <div style="margin-top:0.45mm;">{!! $label('Date de vérification') !!}</div>
            <div class="writeline"></div>
          </td>
          <td>
            {!! $label('Visa de la scolarité, signature et cachet') !!}
            <div style="border:0.4pt solid #CFC8DC; border-radius:2mm; height:10mm; margin-top:0.6mm;"></div>
          </td>
        </tr>
      </table>
    </td>
    <td class="committee-verify">
      <div class="vt">{!! $section('Vérification numérique', 'Digital verification') !!}</div>
      <table class="mini-verify" style="margin-top:1mm;">
        <tr>
          <td class="qr">@if($qrSvg)<img src="{{ $qrSvg }}" alt="QR de vérification">@endif</td>
          <td>
            <div class="mini-meta">
              Dossier <span class="en">Application</span> <strong>{{ $candidature->numero_dossier }}</strong><br>
              Code <span class="en">Verification code</span> <span class="vcode">{{ $vcodePlaceholder }}</span><br>
              Généré le <span class="en">Generated on</span> {{ $generation }}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

</div>
</body>
</html>
