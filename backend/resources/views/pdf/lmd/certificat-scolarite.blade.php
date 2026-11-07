<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Certificat de scolarité {{ $numero }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Certificat de scolarité</h1>
  <p class="sous-titre"><em>Certificate of enrolment</em></p>
  <p style="font-size:11pt;line-height:1.7;margin-top:10mm;">
    Le Chef de la scolarité du Programme Supérieur de Spécialisation en Finances Publiques certifie que
    <strong>{{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}</strong>, matricule {{ $dossier?->matricule }},
    est régulièrement inscrit(e) en <strong>{{ $inscription->niveau === 'M1' ? 'Master 1' : 'Master 2' }} professionnel en finances publiques</strong>{{ $inscription->parcours && $inscription->niveau === 'M2' ? ', option '.$inscription->parcours->libelle : '' }}
    pour l'année académique {{ $inscription->annee_academique }}.
  </p>
  <p style="font-size:11pt;">En foi de quoi le présent certificat lui est délivré pour servir et valoir ce que de droit.</p>
  <table class="signatures"><tr><td>Fait à Yaoundé, le {{ $edite_le->format('d/m/Y') }}</td><td style="text-align:right;">Le Chef de la scolarité</td></tr></table>
  @include('pdf.lmd._verification')
</body>
</html>
