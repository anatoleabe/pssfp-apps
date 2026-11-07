<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Bordereau {{ $e->libelle() }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Bordereau de notes</h1>
  <p class="sous-titre">{{ $e->ecue?->code }} — {{ $e->ecue?->intitule }} · {{ $e->session === 'rattrapage' ? 'rattrapage' : 'session normale' }} · {{ $copies->count() }} copie(s)</p>
  <table>
    <thead><tr><th>Numéro d'anonymat</th><th style="width:40mm;">Note / 20</th><th>Observation</th></tr></thead>
    <tbody>
      @foreach ($copies as $c)
        <tr><td style="font-family:DejaVu Sans Mono, monospace;">{{ $c->numero }}</td><td style="height:8mm;"></td><td></td></tr>
      @endforeach
    </tbody>
  </table>
  <table class="signatures"><tr><td>Le correcteur</td><td style="text-align:right;">Date</td></tr></table>
</body>
</html>
