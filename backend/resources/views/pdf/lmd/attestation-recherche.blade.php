<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Attestation de recherche {{ $numero }}</title>
@include('pdf.lmd._administratif')
</head>
<body>
  @php
    $feminin = $dossier?->genre === 'F';
    $e = match ($dossier?->genre) { 'F' => 'e', 'M' => '', default => '(e)' };
    $civilite = match ($dossier?->civilite) { 'M.' => 'M.', 'Mme', 'Mlle' => 'Mme', default => $feminin ? 'Mme' : ($dossier?->genre === 'M' ? 'M.' : 'M./Mme') };
    $pronom = match ($dossier?->genre) { 'F' => 'elle', 'M' => 'il', default => 'il/elle' };
  @endphp
  <table class="reference"><tr>
    <td>N° {{ $edite_le->format('y') }}/………/AT/MINFI/SG/PSSFP/PCP/UPAAS</td>
    <td style="text-align:right;">Yaoundé, le ……………………</td>
  </tr></table>
  <h1 class="acte">ATTESTATION DE RECHERCHE</h1>
  <p class="corps">
    Le Président du Comité de Pilotage atteste que {{ $civilite }} <strong>{{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}</strong>,
    matricule « <strong>{{ $dossier?->matricule }}</strong> », est inscrit{{ $e }} au cycle de Master en Finances Publiques du Programme Supérieur
    de Spécialisation en Finances Publiques de la Faculté des sciences économiques et de gestion de l'Université de Yaoundé II-Soa,
    au titre de l'année académique {{ $inscription->annee_academique }}.
  </p>
  <p class="corps">
    Dans le cadre de la préparation de son mémoire de fin de formation, {{ $pronom }} effectue ses recherches sur le thème :
    « <strong>{{ $champs['theme'] }}</strong> », sous l'encadrement de <strong>{{ $champs['encadreur'] }}</strong>.
  </p>
  <p class="corps">En foi de quoi la présente attestation lui est délivrée pour servir et valoir ce que de droit.</p>
  <p class="signataire">Le Président du Comité de Pilotage</p>
  @include('pdf.lmd._verification')
</body>
</html>
