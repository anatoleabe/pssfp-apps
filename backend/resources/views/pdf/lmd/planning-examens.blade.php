<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Planning — {{ $p->libelle() }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Planning des examens</h1>
  <p class="sous-titre">{{ $p->libelle() }} · année académique {{ $p->session->calendrier->annee_academique }}
    · du {{ $p->session->debut_prevu->format('d/m/Y') }} au {{ $p->session->fin_prevue->format('d/m/Y') }}@if (! $p->estPublie()) · PROJET, non publié @endif</p>
  <table>
    <thead><tr><th>Date</th><th>Heures</th><th>Épreuve</th><th class="n">Candidats</th><th>Salles (effectif)</th><th>Surveillants</th></tr></thead>
    <tbody>
      @foreach ($lignes as $l)
        <tr>
          <td>{{ ucfirst($l['epreuve']->date_epreuve->locale('fr')->translatedFormat('l j F Y')) }}</td>
          <td>{{ $l['epreuve']->heures() }}</td>
          <td><strong>{{ $l['epreuve']->code_ecue }}</strong> {{ $l['epreuve']->intitule }}</td>
          <td class="n">{{ $l['effectif'] }}</td>
          <td>@foreach ($l['salles'] as $s){{ $s['salle']->libelle }} ({{ $s['candidats']->count() }})@if (! $loop->last)<br>@endif @endforeach</td>
          <td>@foreach ($l['salles'] as $s){{ $s['surveillants']->pluck('name')->implode(', ') ?: '—' }}@if (! $loop->last)<br>@endif @endforeach</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
