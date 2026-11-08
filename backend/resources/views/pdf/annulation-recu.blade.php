<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Annulation {{ $annulation->numero }}</title>
@include('pdf._recu-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Scolarite\RecusVersements::fcfa($m);
    $tz = 'Africa/Douala';
@endphp
</head>
<body>
  <table class="entete"><tr>
    <td>
      <p>Programme Supérieur de Spécialisation en Finances Publiques</p>
      <h1>Écriture d'annulation de reçu</h1>
    </td>
    <td class="numero">N° {{ $annulation->numero }}<br><span style="font-weight:400;font-size:8.5pt;color:#5C5C5C;">Le {{ $annulation->annule_at->setTimezone($tz)->format('d/m/Y à H:i') }}</span></td>
  </tr></table>

  <table class="ident">
    <tr><td class="l">Reçu annulé</td><td><strong>{{ $recu->numero }}</strong> du {{ $recu->emis_at->setTimezone($tz)->format('d/m/Y') }}</td></tr>
    <tr><td class="l">Auditeur</td><td>{{ $recu->auditeur_nom }}@if ($recu->matricule) — matricule {{ $recu->matricule }}@endif</td></tr>
    <tr><td class="l">Montant du reçu</td><td>{{ $fcfa($recu->montant) }} ({{ $lettres }})</td></tr>
    <tr><td class="l">Motif</td><td>{{ $annulation->motif }}</td></tr>
  </table>

  <p class="pied">Écriture passée par {{ $annulation->annuleur_nom }}, service administratif et financier du PSSFP. Le reçu {{ $recu->numero }} n'a plus d'effet ; un nouveau reçu peut être émis pour le versement.</p>
</body>
</html>
