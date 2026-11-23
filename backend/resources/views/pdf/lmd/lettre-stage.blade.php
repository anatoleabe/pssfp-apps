<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Lettre de stage {{ $numero }}</title>
@include('pdf.lmd._administratif')
<style>
  /* La police a une hauteur de ligne naturelle élevée : 1.05 donne un interligne courant. */
  .lettre { font-size: 11pt; line-height: 1.05; text-align: justify; text-indent: 12mm; margin: 0 0 2.5mm; }
  .destinataire { margin: 5mm 0 4mm 45%; line-height: 1.25; text-align: center; font-family: 'Source Serif 4', Georgia, serif; font-weight: 700; font-size: 11.5pt; }
</style>
</head>
<body>
  @php
    $civilite = match ($dossier?->civilite) { 'M.' => 'Monsieur', 'Mme', 'Mlle' => 'Madame', default => match ($dossier?->genre) { 'F' => 'Madame', 'M' => 'Monsieur', default => 'Monsieur/Madame' } };
    $qualite = match ($dossier?->genre) { 'F' => 'Auditrice', 'M' => 'Auditeur', default => 'Auditeur(trice)' };
    $option = $inscription->parcours && $inscription->niveau === 'M2' ? $inscription->parcours->libelle : $dossier?->specialite;
  @endphp
  <table class="reference"><tr>
    <td>N° {{ $edite_le->format('y') }}/………/L/MINFI/SG/PSSFP/PCP/UPAAS</td>
    <td style="text-align:right;">Yaoundé, le ……………………</td>
  </tr></table>
  <p class="destinataire">LE PRÉSIDENT DU COMITÉ DE PILOTAGE<br>À<br>{{ mb_strtoupper($champs['destinataire']) }}</p>
  <p style="font-size:11.5pt;"><strong><u>OBJET</u> :</strong> Stage académique d'un auditeur</p>
  <p class="lettre"><strong>{{ $champs['appel'] }},</strong></p>
  <p class="lettre">
    Dans le cadre du dispositif national de renforcement des capacités des ressources humaines en Finances Publiques, le Ministre des Finances,
    le Ministre de l'Enseignement Supérieur et le Recteur de l'Université de Yaoundé II-Soa ont mis en place le Programme Supérieur de
    Spécialisation en Finances Publiques (PSSFP) préparant au Master professionnel en Finances Publiques.
  </p>
  <p class="lettre">
    La formation, qui se déroule en quatre semestres, prévoit une phase d'application consacrée à la recherche, aux stages, aux séminaires.
    Pendant cette période, les apprenants ont l'occasion de recueillir les éléments utiles à la rédaction de leur mémoire.
  </p>
  <p class="lettre">Dans cette perspective,</p>
  <p class="lettre"><strong>
    J'ai l'honneur de mettre à votre disposition {{ $civilite }} {{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}, {{ $dossier?->matricule }},
    {{ $qualite }} en Master {{ $inscription->niveau === 'M1' ? '1' : '2' }} Finances Publiques{{ $option ? ', option '.$option : '' }}, afin d'effectuer
    un {{ $champs['nature'] }} dans votre structure, pour une période de {{ $champs['duree'] }}@if ($champs['theme']), sur le thème : « {{ $champs['theme'] }} »@endif.
  </strong></p>
  <p class="lettre">Veuillez agréer, <strong>{{ $champs['appel'] }},</strong> l'assurance de ma parfaite considération. /-</p>
  <p class="signataire" style="margin:6mm 0 20mm;">Le Président du Comité de Pilotage</p>
  @include('pdf.lmd._verification')
</body>
</html>
