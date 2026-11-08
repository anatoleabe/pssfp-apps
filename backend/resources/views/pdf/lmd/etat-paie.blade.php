<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $etat->numero }}</title>
@include('pdf.lmd._paie-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Lmd\EtatsPaie::fcfa($m);
    $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ' '), '0'), ',');
    $statuts = \App\Models\EtatPaie::STATUTS;
@endphp
</head>
<body>
  <div class="entete">
    <p>Programme Supérieur de Spécialisation en Finances Publiques — Scolarité</p>
    <h1>{{ $etat->objet }}</h1>
    <p>N° {{ $etat->numero }} · période du {{ $etat->periode_du->format('d/m/Y') }} au {{ $etat->periode_au->format('d/m/Y') }} · {{ $statuts[$etat->statut] ?? $etat->statut }} · édité le {{ $edite_le->format('d/m/Y à H:i') }}</p>
  </div>
  @if ($etat->statut === \App\Models\EtatPaie::REJETE)
    <div class="bandeau">REJETÉ — NE PAS PAYER ({{ $etat->motif_rejet }})</div>
  @elseif (in_array($etat->statut, [\App\Models\EtatPaie::BROUILLON, \App\Models\EtatPaie::VERIFIE, \App\Models\EtatPaie::VISE], true))
    <div class="bandeau">PROJET — NON TRANSMIS AU SERVICE FINANCIER</div>
  @endif

  <table>
    <thead><tr>
      <th>Enseignant</th><th>Statut</th><th class="n">CM payés</th><th class="n">TD et TP faits</th><th class="n">Service dû</th><th class="n">Hors quota</th>
      <th class="n">Surveillances</th><th class="n">Brut</th><th class="n">Retenue</th><th class="n">Net</th>
    </tr></thead>
    <tbody>
      @foreach ($synthese['lignes'] as $l)
        <tr>
          <td>{{ $l['enseignant'] }}</td>
          <td>{{ $l['statut'] }}</td>
          <td class="n">{{ $h($l['heures_cm']) }} h</td>
          <td class="n">{{ $h($l['heures_td_tp']) }} h</td>
          <td class="n">{{ $l['service'] > 0 ? $h($l['service']).' h' : '—' }}</td>
          <td class="n">{{ $l['hors_quota'] > 0 ? $h($l['hors_quota']).' h' : '—' }}</td>
          <td class="n">{{ $l['surveillances'] ?: '—' }}</td>
          <td class="n">{{ $fcfa($l['brut']) }}</td>
          <td class="n">{{ $fcfa($l['retenue']) }}</td>
          <td class="n">{{ $fcfa($l['net']) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot><tr>
      <td colspan="2">{{ $synthese['totaux']['enseignants'] }} enseignant(s)</td>
      <td class="n">{{ $h($synthese['totaux']['heures_cm']) }} h</td><td></td>
      <td class="n">{{ $synthese['totaux']['service'] > 0 ? $h($synthese['totaux']['service']).' h' : '—' }}</td>
      <td class="n">{{ $synthese['totaux']['hors_quota'] > 0 ? $h($synthese['totaux']['hors_quota']).' h' : '—' }}</td>
      <td class="n">{{ $synthese['totaux']['surveillances'] ?: '—' }}</td>
      <td class="n">{{ $fcfa($synthese['totaux']['brut']) }}</td>
      <td class="n">{{ $fcfa($synthese['totaux']['retenue']) }}</td>
      <td class="n">{{ $fcfa($synthese['totaux']['net']) }}</td>
    </tr></tfoot>
  </table>

  @if (($etat->ecartes ?? []) !== [])
    <p class="note"><strong>Enseignants écartés de cet état</strong> (pointages conservés pour un état suivant) :
      @foreach ($etat->ecartes as $e){{ $e['enseignant'] }} ({{ $e['raison'] }}){{ $loop->last ? '.' : ' ;' }} @endforeach</p>
  @endif
  <p class="note">Seules les heures de cours magistral sont rémunérées ; les heures de TD et de TP sont tracées sans être payées (D24). Les heures au-delà des heures allouées ne sont pas payées ; celles d'un permanent sont d'abord imputées sur son service dû. Paiement par virement uniquement.</p>

  <table class="signatures"><tr>
    <td>Établi par la Scolarité</td><td>Vérifié par le chef d'unité de la scolarité</td>
    @if ($etat->vise_par !== null)<td>Visé par le contrôleur interne</td>@endif
    <td>Pris en charge par le service financier</td>
  </tr></table>
</body>
</html>
