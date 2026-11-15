<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $titre }}</title>
@include('pdf.scolarite._liste-styles')
@php($effectif = fn (int $n): string => $n.' auditeur'.($n > 1 ? 's' : ''))
</head>
<body>
@include('pdf.scolarite._liste-entete')

@if ($tableau['totaux']['effectif'] === 0)
  <p class="vide">Aucun auditeur ne correspond à ces critères.</p>
@else
  {{-- Ni téléphone ni e-mail : la liste peut circuler en interne. --}}
  <table class="liste">
    <thead><tr>
      <th class="n">N°</th><th>Matricule</th><th>Nom et prénom</th><th>Niveau</th><th>Spécialité</th><th>Statut</th>
    </tr></thead>
    <tbody>
      @foreach ($tableau['groupes'] as $groupe)
        @if ($groupe['titre'] !== null)
          <tr class="groupe"><td colspan="6">{{ $groupe['titre'] }} — {{ $effectif($groupe['totaux']['effectif']) }}</td></tr>
        @endif
        @foreach ($groupe['lignes'] as $l)
          <tr>
            <td class="n">{{ $loop->iteration }}</td>
            <td class="mat">{{ $l['matricule'] }}</td>
            <td>{{ $l['nom'] }}</td>
            <td>{{ $l['niveau'] ?? '—' }}</td>
            <td>{{ $l['specialite'] ?? '—' }}</td>
            <td>{{ $l['statut'] }}</td>
          </tr>
        @endforeach
      @endforeach
      <tr class="total"><td colspan="6">Effectif total : {{ $effectif($tableau['totaux']['effectif']) }}</td></tr>
    </tbody>
  </table>
@endif
</body>
</html>
