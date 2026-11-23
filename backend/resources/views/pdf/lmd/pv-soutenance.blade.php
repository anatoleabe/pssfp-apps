<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Procès-verbal de soutenance</title>
<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 11mm 16mm 12mm; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 10.5pt; color: #3C3C3C; }
  table { width: 100%; border-collapse: collapse; }
  p { margin: 0; }
  .page { page-break-after: always; }
  .page.derniere { page-break-after: auto; }
  .entete td { vertical-align: top; font-size: 7.5pt; line-height: 1.2; text-align: center; color: #3C3C3C; }
  .entete td.logo { width: 24%; vertical-align: middle; }
  .entete td.cote { width: 38%; }
  .filet { border-top: 1.5px solid #4A2E67; margin: 3mm 0 0; }
  .lieu { text-align: right; font-size: 9.5pt; color: #5C5C5C; margin: 3mm 0 0; }
  h1 { font-family: 'Source Serif 4', Georgia, serif; color: #4A2E67; font-size: 19pt; text-align: center; letter-spacing: 0.5pt; margin: 4mm 0 0; }
  .annee { text-align: center; color: #0F3A4A; font-size: 10pt; margin: 0 0 4mm; }
  .auditeur { text-align: center; font-size: 13pt; margin: 0; }
  .auditeur strong { font-size: 14pt; color: #0F3A4A; }
  .matricule { text-align: center; color: #5C5C5C; margin: 0 0 2mm; }
  .phrase { text-align: center; line-height: 1.3; margin: 0; }
  .theme { margin: 4mm 6mm 4mm; padding: 3mm 6mm; background: #FAF7F2; border-left: 3px solid #D4AF6A; }
  .theme .etiquette, .etiquette { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #4A2E67; font-weight: 700; }
  .theme .texte { font-family: 'Source Serif 4', Georgia, serif; font-size: 11.5pt; line-height: 1.25; margin-top: 1mm; color: #3C3C3C; }
  .jury { margin-top: 2mm; }
  .jury th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #4A2E67; padding: 0 2mm 2mm; border-bottom: 1px solid #A592BD; }
  .jury td { padding: 3.2mm 2mm; height: 9mm; border-bottom: 1px solid #E6E0EE; vertical-align: middle; }
  .jury td.role { width: 30%; font-weight: 700; color: #0F3A4A; }
  .jury td.role small { display: block; font-weight: 400; color: #5C5C5C; font-size: 8pt; }
  .jury td.nom { width: 38%; }
  .jury td.signature { width: 32%; }
  .resultat { margin-top: 5mm; }
  .resultat td { vertical-align: top; padding: 0 2mm; }
  .resultat .bloc { border: 1px solid #A592BD; border-radius: 2mm; padding: 2.5mm 4mm 3mm; height: 14mm; }
  .resultat .valeur { font-family: 'Source Serif 4', Georgia, serif; font-size: 17pt; color: #4A2E67; margin-top: 1.5mm; }
  .resultat .vide { margin-top: 8mm; border-bottom: 1px dotted #8C8C8C; }
  .observations { margin: 5mm 2mm 0; }
  .observations .texte { margin-top: 2mm; line-height: 1.55; }
  .observations .ligne { border-bottom: 1px dotted #8C8C8C; height: 7.5mm; }
  .pied { position: fixed; bottom: -6mm; left: 0; right: 0; text-align: center; font-size: 7pt; color: #8C8C8C; }
</style>
</head>
<body>
<div class="pied">PSSFP — procès-verbal édité le {{ $edite_le->format('d/m/Y à H:i') }}</div>
@foreach ($pages as $p)
  @php($d = $p['dossier'])
  @php($civilite = match ($p['candidat']->civilite) { 'M.' => 'Monsieur', 'Mme' => 'Madame', 'Mlle' => 'Mademoiselle', default => '' })
  <div class="page {{ $loop->last ? 'derniere' : '' }}">
    <table class="entete">
      <tr>
        <td class="cote">RÉPUBLIQUE DU CAMEROUN<br>Paix – Travail – Patrie<br>UNIVERSITÉ DE YAOUNDÉ II-SOA<br>FACULTÉ DES SCIENCES ÉCONOMIQUES ET DE GESTION<br><strong>PROGRAMME SUPÉRIEUR DE SPÉCIALISATION<br>EN FINANCES PUBLIQUES</strong></td>
        <td class="logo">@if ($logo)<img src="{{ $logo }}" alt="" style="height:20mm;">@endif</td>
        <td class="cote">REPUBLIC OF CAMEROON<br>Peace – Work – Fatherland<br>UNIVERSITY OF YAOUNDE II-SOA<br>FACULTY OF ECONOMICS AND MANAGEMENT<br><strong>ADVANCED PROGRAM OF SPECIALISATION<br>IN PUBLIC FINANCE</strong></td>
      </tr>
    </table>
    <div class="filet"></div>
    <p class="lieu">Yaoundé, le {{ $d->date_soutenance->format('d/m/Y') }}</p>

    <h1>Procès-verbal de soutenance</h1>
    <p class="annee">Année académique {{ $p['annee'] }}</p>

    <p class="auditeur">{{ $civilite }} <strong>{{ mb_strtoupper(trim($p['candidat']->nom.' '.$p['candidat']->prenom)) }}</strong></p>
    <p class="matricule">Matricule {{ $p['candidat']->matricule }}</p>
    <p class="phrase">a soutenu un mémoire de <strong>Master en finances publiques</strong>@if ($p['option'])<br>option <em>{{ $p['option'] }}</em>@endif</p>

    <div class="theme">
      <div class="etiquette">Thème</div>
      <div class="texte">« {{ $d->theme }} »</div>
    </div>

    <table class="jury">
      <tr><th>Devant le jury</th><th>Nom</th><th>Signature</th></tr>
      <tr><td class="role">Président</td><td class="nom">{{ $d->jury_president }}</td><td class="signature"></td></tr>
      <tr><td class="role">Rapporteur<small>Directeur de recherche</small></td><td class="nom">{{ $d->jury_rapporteur }}</td><td class="signature"></td></tr>
      @if (filled($d->jury_examinateur))
        <tr><td class="role">Examinateur</td><td class="nom">{{ $d->jury_examinateur }}</td><td class="signature"></td></tr>
      @endif
      @if (filled($d->encadreur_professionnel))
        <tr><td class="role">Encadreur professionnel</td><td class="nom">{{ $d->encadreur_professionnel }}</td><td class="signature"></td></tr>
      @endif
    </table>

    <table class="resultat">
      <tr>
        <td style="width:36%;"><div class="bloc"><div class="etiquette">Note sur 20</div>
          @if ($p['note'] !== null)<div class="valeur">{{ number_format($p['note'], 2, ',', ' ') }} / 20</div>@else<div class="vide"></div>@endif</div></td>
        <td style="width:64%;"><div class="bloc"><div class="etiquette">Mention</div>
          @if ($p['note'] !== null)<div class="valeur">{{ $p['mention'] ?? '—' }}</div>@else<div class="vide"></div>@endif</div></td>
      </tr>
    </table>

    <div class="observations">
      <div class="etiquette">Observations du jury</div>
      @if ($p['note'] !== null)
        <div class="texte">{{ $p['observations'] ?: '—' }}</div>
      @else
        <div class="ligne"></div><div class="ligne"></div><div class="ligne"></div>
      @endif
    </div>
  </div>
@endforeach
</body>
</html>
