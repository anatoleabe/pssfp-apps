<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $titre }}</title>
@include('pdf.scolarite._liste-styles')
</head>
<body>
@php $fcfa = fn (int $m): string => number_format($m, 0, ',', ' '); @endphp
@include('pdf.scolarite._liste-entete')

@if ($lignes->isEmpty())
  <p class="vide">Aucun auditeur dans cette situation pour ces critères.</p>
@else
<table class="liste">
  <thead><tr>
    <th>N°</th><th>Matricule</th><th>Nom et prénoms</th><th>Promo.</th><th>Niveau</th><th>Statut</th>
    <th class="n">Dû</th><th class="n">Versé</th><th class="n">Dont à confirmer</th><th class="n">Échu impayé</th><th class="n">Reste à payer</th>
  </tr></thead>
  <tbody>
  @foreach ($lignes as $n => $l)
    <tr>
      <td class="n">{{ $n + 1 }}</td>
      <td class="mat">{{ $l['matricule'] }}</td>
      <td>{{ $l['nom'] }} {{ $l['prenom'] }}</td>
      <td class="mat">{{ $l['promotion'] }}</td>
      <td class="mat">{{ $l['niveau'] }}</td>
      <td>{{ $l['statut'] }}</td>
      <td class="n">{{ $fcfa($l['du']) }}</td>
      <td class="n">{{ $fcfa($l['verse']) }}</td>
      <td class="n">{{ $fcfa($l['a_confirmer']) }}</td>
      <td class="n">{{ $fcfa($l['echu_impaye']) }}</td>
      <td class="n">{{ $fcfa($l['reste']) }}</td>
    </tr>
  @endforeach
  <tr class="total">
    <td colspan="6">Total : {{ $total['effectif'] }} auditeur(s)</td>
    <td class="n">{{ $fcfa($total['du']) }}</td><td class="n">{{ $fcfa($total['verse']) }}</td><td class="n">{{ $fcfa($total['a_confirmer']) }}</td>
    <td class="n">{{ $fcfa($total['echu_impaye']) }}</td><td class="n">{{ $fcfa($total['reste']) }}</td>
  </tr>
  </tbody>
</table>
@endif

<p class="note">Montants en FCFA, sur tout le parcours de l'auditeur (arriérés compris). Échu impayé : droits d'inscription restants et tranches dont l'échéance est passée. Versements à confirmer comptés comme payés. En règle : rien d'échu impayé ; partiellement insolvable : un échu impayé malgré au moins un versement ; totalement insolvable : un échu impayé sans aucun versement.</p>
</body>
</html>
