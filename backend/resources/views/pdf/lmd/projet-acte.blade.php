<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Projet d'acte — {{ $acte->objet }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <p style="font-size:9pt;color:#5C3A7E;text-align:right;">PROJET — à signer par {{ $acte->modele->signataire }}</p>
  <h1>{{ $acte->modele->libelle }}</h1>
  <p class="sous-titre">N° ………… du …………</p>
  <p style="font-size:10.5pt;"><strong>Objet :</strong> {{ $acte->objet }}</p>
  <div style="font-size:11pt;line-height:1.6;margin-top:6mm;">
    @foreach (preg_split('/\R/', (string) $contenu) as $ligne)
      @if (trim($ligne) === '')
        <br>
      @else
        <p style="margin:0 0 1.5mm 0;">{{ $ligne }}</p>
      @endif
    @endforeach
  </div>
  <table class="signatures"><tr><td>Fait à Yaoundé, le …………</td><td style="text-align:right;">{{ $acte->modele->signataire }}</td></tr></table>
  <p style="font-size:8pt;color:#666;margin-top:10mm;">Échéance de production : {{ $acte->echeance->format('d/m/Y') }} — généré le {{ $edite_le->format('d/m/Y à H:i') }}.</p>
</body>
</html>
