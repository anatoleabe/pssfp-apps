<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Informations d'accès à la plateforme de formation à distance — {{ $matricule }}</title>
<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 8mm 20mm 12mm; }
  body { font-family: 'Source Serif 4', Georgia, serif; font-size: 10pt; color: #1F1F1F; line-height: 1.1; margin: 0; }
  footer { position: fixed; bottom: -8mm; left: 0; right: 0; border-top: 0.5pt solid #B8913F; padding-top: 1.5mm;
    text-align: center; font-size: 7pt; color: #5F5F5F; }

  table.entete { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 3mm; }
  table.entete td { vertical-align: top; text-align: center; font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 7.2pt; line-height: 1.08; color: #000; }
  table.entete td.logo { width: 26%; vertical-align: middle; }
  table.entete td.logo img { height: 17mm; }
  .devise { font-size: 6pt; font-weight: 700; }
  .sep { letter-spacing: 0.4mm; }

  table.ref { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
  table.ref td { padding: 0; vertical-align: bottom; }
  .reference { font-size: 8.5pt; color: #5F5F5F; }
  .date { text-align: right; font-size: 11pt; }

  .dest { text-align: right; margin-bottom: 4mm; }
  .dest .exp { font-size: 12pt; font-weight: 700; }
  .dest .a { font-size: 10pt; font-weight: 700; color: #5F5F5F; margin: 1mm 0; }
  .dest .nom { font-size: 11.5pt; font-weight: 700; color: #4A2E67; }
  .dest .mat { font-size: 9.5pt; font-weight: 700; color: #5F5F5F; }

  .objet { margin-bottom: 4mm; }
  .objet strong { text-decoration: underline; }
  p { margin: 0 0 1.8mm; text-align: justify; }

  h2 { font-size: 8.5pt; font-weight: 700; color: #4A2E67; letter-spacing: 0.5pt; text-transform: uppercase;
    border-bottom: 0.5pt solid #B8913F; padding-bottom: 0.6mm; margin: 3mm 0 1.4mm; }
  table.ident { width: 100%; border-collapse: collapse; }
  table.ident td { background: #FAF7F2; padding: 0.9mm 4mm; vertical-align: middle; }
  table.ident td.l { width: 30%; border-left: 1.5mm solid #4A2E67; font-size: 9pt; color: #5F5F5F; }
  table.ident td.v { font-size: 12pt; font-weight: 700; }
  table.ident td.code { font-family: 'DejaVu Sans Mono', monospace; font-size: 12pt; color: #4A2E67; }
  .aide { font-size: 9pt; color: #5F5F5F; margin: 1.5mm 0 0; }
  .point { margin: 0 0 1mm 5mm; text-align: left; }
  .point strong { font-weight: 700; }

  table.signature { width: 100%; border-collapse: collapse; margin-top: 3mm; }
  table.signature td { padding: 0; vertical-align: top; }
  table.signature td.formules { width: 56%; padding-right: 6mm; }
  table.signature td.formules p { text-align: left; }
  .signataire { text-align: right; font-weight: 700; font-size: 10.5pt; }
  .scan { position: relative; height: 0; }
  .scan img { position: absolute; right: 0; top: 1mm; height: 18mm; }
</style>
</head>
<body>
<footer>{{ $pied_de_page }}</footer>

<table class="entete">
  <tr>
    <td>
      REPUBLIQUE DU CAMEROUN<br><span class="devise">Paix- Travail- Patrie</span><br><span class="sep">--------------</span><br>
      MINISTERE DES FINANCES<br><span class="sep">--------------</span><br>SECRETARIAT GENERAL<br><span class="sep">--------------</span><br>
      PROGRAMME SUPERIEUR DE SPECIALISATION EN FINANCES PUBLIQUES
    </td>
    <td class="logo">@if ($logo)<img src="{{ $logo }}" alt="PSSFP">@endif</td>
    <td>
      REPUBLIC OF CAMEROON<br><span class="devise">Peace- Work- Fatherland</span><br><span class="sep">--------------</span><br>
      MINISTRY OF FINANCE<br><span class="sep">--------------</span><br>GENERAL SECRETARIAT<br><span class="sep">--------------</span><br>
      ADVANCED PROGRAM OF SPECIALISATION IN PUBLIC FINANCE
    </td>
  </tr>
</table>

<table class="ref">
  <tr>
    <td class="reference">{{ $reference }}</td>
    <td class="date">Yaoundé, le {{ $date }}</td>
  </tr>
</table>

<div class="dest">
  <div class="exp">{{ $expediteur }}</div>
  <div class="a">à</div>
  <div class="nom">{{ $appellation }}</div>
  <div class="mat">Matricule&nbsp;: {{ $matricule }}</div>
</div>

<p class="objet"><strong>Objet</strong>&nbsp;: Informations d'accès à la plateforme de formation à distance</p>

<p>{{ $civilite }},</p>
<p>J'ai le plaisir de vous transmettre vos identifiants personnels d'accès au campus numérique du Programme Supérieur de
  Spécialisation en Finances Publiques (PSSFP), sur lequel se déroulent les enseignements à distance de votre formation.</p>

<h2>Vos identifiants</h2>
<table class="ident">
  <tr><td class="l">Adresse</td><td class="v">{{ $adresse_foad }}</td></tr>
  <tr><td class="l">Identifiant</td><td class="v code">{{ $identifiant }}</td></tr>
  <tr><td class="l">Mot de passe</td><td class="v code">{{ $mot_de_passe }}</td></tr>
</table>
<p class="aide">Ces identifiants vous sont personnels et vous sont attribués pour toute la durée de votre formation.
  Ne communiquez jamais votre mot de passe.</p>

<h2>Se connecter</h2>
<p class="point"><strong>Sur ordinateur&nbsp;: </strong>ouvrez {{ $url_foad }} et saisissez votre identifiant et votre mot de passe.</p>
<p class="point"><strong>Sur téléphone&nbsp;: </strong>installez gratuitement l'application «&nbsp;Moodle&nbsp;» depuis Google Play ou
  l'App Store, indiquez l'adresse de la plateforme, puis vos identifiants.</p>

<h2>Votre espace auditeur</h2>
<p class="point"><strong>Adresse&nbsp;: </strong>{{ $adresse_espace }} — votre dossier, les messages du PSSFP et vos documents administratifs.</p>
<p class="point"><strong>Connexion&nbsp;: </strong>avec les identifiants ci-dessus (onglet «&nbsp;Matricule&nbsp;»), ou avec votre
  numéro de téléphone et votre code personnel à 6 chiffres.</p>

<h2>Vos coordonnées enregistrées</h2>
<p class="point"><strong>Téléphone&nbsp;: </strong>{{ $telephones === [] ? '—' : implode(' · ', $telephones) }}
  &nbsp;&nbsp;&nbsp;<strong>E-mail&nbsp;: </strong>{{ $email }}</p>
<p class="aide" style="margin-left: 5mm; margin-bottom: 3mm;">Une erreur&nbsp;? Signalez-la à la scolarité.</p>

{{-- Formules à gauche, signataire à droite : la hauteur du scan ne pousse pas la lettre sur une 2e page. --}}
<table class="signature">
  <tr>
    <td class="formules">
      <p>Je vous souhaite la bienvenue au PSSFP&nbsp;; la scolarité reste à votre disposition pour toute assistance.</p>
      <p>Veuillez agréer, {{ $civilite }}, l'expression de mes salutations distinguées.</p>
    </td>
    <td class="signataire">
      {{ $signataire }}
      {{-- Flottant, comme dans le Word : le scan ne fait pas grandir le bloc. --}}
      @if ($signature)<div class="scan"><img src="{{ $signature }}" alt="Signature et cachet"></div>@endif
    </td>
  </tr>
</table>
</body>
</html>
