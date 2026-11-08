<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Reçu {{ $recu->numero }}</title>
@include('pdf._recu-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Scolarite\RecusVersements::fcfa($m);
    $modes = \App\Models\Versement::MODES;
    $payeurs = \App\Models\Versement::PAYEURS;
    $niveaux = \App\Models\BaremeScolarite::NIVEAUX;
    $tz = 'Africa/Douala';
@endphp
</head>
<body>
  <table class="entete"><tr>
    <td>
      <p>Programme Supérieur de Spécialisation en Finances Publiques</p>
      <h1>Reçu de versement</h1>
    </td>
    <td class="numero">N° {{ $recu->numero }}<br><span style="font-weight:400;font-size:8.5pt;color:#5C5C5C;">Émis le {{ $recu->emis_at->setTimezone($tz)->format('d/m/Y à H:i') }}</span></td>
  </tr></table>

  @if ($recu->annulation !== null)
    <div class="annule">ANNULÉ — écriture {{ $recu->annulation->numero }} du {{ $recu->annulation->annule_at->setTimezone($tz)->format('d/m/Y') }}</div>
  @endif

  <table class="ident">
    <tr><td class="l">Reçu de</td><td><strong>{{ $recu->auditeur_nom }}</strong>@if ($recu->matricule) — matricule {{ $recu->matricule }}@endif</td></tr>
    <tr><td class="l">Année et niveau</td><td>{{ $recu->annee_academique }} · {{ $niveaux[$recu->niveau] ?? $recu->niveau }}</td></tr>
    <tr><td class="l">Objet</td><td>{{ $recu->objet }}</td></tr>
    <tr><td class="l">Versement</td><td>{{ $modes[$recu->mode] ?? $recu->mode }} du {{ $recu->date_versement->format('d/m/Y') }}, référence {{ $recu->reference }}</td></tr>
    <tr><td class="l">Payé par</td><td>{{ $payeurs[$recu->payeur] ?? $recu->payeur }}</td></tr>
  </table>

  <div class="montant">
    Montant reçu : <strong>{{ $fcfa($recu->montant) }}</strong><br>
    Soit {{ $lettres }}.
  </div>

  <table class="ident">
    <tr><td class="l">Total versé pour l'année</td><td>{{ $fcfa($recu->total_verse) }}</td></tr>
    <tr><td class="l">Reste à payer</td><td>{{ $recu->reste_a_payer === 0 ? 'Soldé' : $fcfa($recu->reste_a_payer) }}</td></tr>
  </table>

  <p class="pied">Émis par {{ $recu->emetteur_nom }}, service administratif et financier du PSSFP. Montants arrêtés à la date d'émission du reçu.</p>
</body>
</html>
