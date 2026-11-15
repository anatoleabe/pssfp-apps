<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $titre }}</title>
@include('pdf.scolarite._liste-styles')
@php
    $fcfa = fn (?int $m): string => $m === null ? '—' : \App\Services\Scolarite\RecusVersements::fcfa($m);
    $effectif = fn (int $n): string => $n.' auditeur'.($n > 1 ? 's' : '');
@endphp
</head>
<body>
@include('pdf.scolarite._liste-entete')

@if ($tableau['totaux']['effectif'] === 0)
  <p class="vide">Aucun auditeur ne correspond à ces critères.</p>
@else
  <table class="liste">
    <thead><tr>
      <th>Matricule</th><th>Nom et prénom</th><th>Niveau</th><th>Spécialité</th>
      <th class="n">Dû</th><th class="n">Remise</th><th class="n">Versé</th><th class="n">Reste</th><th class="n">Avance</th><th>Situation</th>
    </tr></thead>
    <tbody>
      @foreach ($tableau['groupes'] as $groupe)
        @if ($groupe['titre'] !== null)
          <tr class="groupe"><td colspan="10">{{ $groupe['titre'] }} — {{ $effectif($groupe['totaux']['effectif']) }}</td></tr>
        @endif
        @foreach ($groupe['lignes'] as $l)
          <tr>
            <td class="mat">{{ $l['matricule'] }}</td>
            <td>{{ $l['nom'] }}</td>
            <td>{{ $l['niveau'] ?? '—' }}</td>
            <td>{{ $l['specialite'] ?? '—' }}</td>
            <td class="n">{{ $fcfa($l['du']) }}</td>
            <td class="n">{{ $fcfa($l['remise']) }}</td>
            <td class="n">{{ $fcfa($l['verse']) }}@if (($l['a_confirmer'] ?? 0) > 0)<br><span style="font-size:7pt;">dont {{ $fcfa($l['a_confirmer']) }} à confirmer</span>@endif</td>
            <td class="n">{{ $fcfa($l['reste']) }}</td>
            <td class="n">{{ $l['avance'] > 0 ? $fcfa($l['avance']) : '—' }}</td>
            <td class="sit">{{ $l['situation'] }}</td>
          </tr>
        @endforeach
        @if ($groupe['titre'] !== null)
          <tr class="sous-total">
            <td colspan="3">Sous-total — {{ $groupe['titre'] }}</td>
            <td class="n">{{ $effectif($groupe['totaux']['effectif']) }}</td>
            <td class="n">{{ $fcfa($groupe['totaux']['du']) }}</td>
            <td class="n">{{ $fcfa($groupe['totaux']['remise']) }}</td>
            <td class="n">{{ $fcfa($groupe['totaux']['verse']) }}@if (($groupe['totaux']['a_confirmer'] ?? 0) > 0)<br><span style="font-size:7pt;">dont {{ $fcfa($groupe['totaux']['a_confirmer']) }} à confirmer</span>@endif</td>
            <td class="n">{{ $fcfa($groupe['totaux']['reste']) }}</td>
            <td class="n">{{ $fcfa($groupe['totaux']['avance']) }}</td>
            <td></td>
          </tr>
        @endif
      @endforeach
      <tr class="total">
        <td colspan="3">Total général</td>
        <td class="n">{{ $effectif($tableau['totaux']['effectif']) }}</td>
        <td class="n">{{ $fcfa($tableau['totaux']['du']) }}</td>
        <td class="n">{{ $fcfa($tableau['totaux']['remise']) }}</td>
        <td class="n">{{ $fcfa($tableau['totaux']['verse']) }}@if (($tableau['totaux']['a_confirmer'] ?? 0) > 0)<br><span style="font-size:7pt;">dont {{ $fcfa($tableau['totaux']['a_confirmer']) }} à confirmer</span>@endif</td>
        <td class="n">{{ $fcfa($tableau['totaux']['reste']) }}</td>
        <td class="n">{{ $fcfa($tableau['totaux']['avance']) }}</td>
        <td></td>
      </tr>
    </tbody>
  </table>
  <p class="note">Dû : montant de l'année après remise. Versé : part des versements enregistrés (hors versements annulés) imputée sur l'année, au plus le dû.
    Reste : ce qu'il reste à payer sur l'année. Avance : versé au-delà de ce qui est dû, gardé au compte de l'auditeur pour la scolarité à venir.
    Un auditeur « Barème manquant » n'a pas d'inscription pour l'année : il compte dans l'effectif, pas dans les montants.</p>
@endif
</body>
</html>
