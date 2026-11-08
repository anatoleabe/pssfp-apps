<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Bordereau {{ $etat->numero }}</title>
@include('pdf.lmd._paie-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Lmd\EtatsPaie::fcfa($m);
    $h = fn (float $x): string => rtrim(rtrim(number_format($x, 2, ',', ' '), '0'), ',');
@endphp
</head>
<body>
  <div class="entete">
    <p>Programme Supérieur de Spécialisation en Finances Publiques — Scolarité</p>
    <h1>Bordereau de transmission au service financier</h1>
    <p>Édité le {{ $edite_le->format('d/m/Y à H:i') }}</p>
  </div>
  @if ($etat->statut === \App\Models\EtatPaie::REJETE)
    <div class="bandeau">REJETÉ — NE PAS PAYER ({{ $etat->motif_rejet }})</div>
  @elseif (in_array($etat->statut, [\App\Models\EtatPaie::BROUILLON, \App\Models\EtatPaie::VERIFIE, \App\Models\EtatPaie::VISE], true))
    <div class="bandeau">PROJET — NON TRANSMIS AU SERVICE FINANCIER</div>
  @endif

  <table class="ident">
    <tr><td class="l">État de paie</td><td><strong>{{ $etat->numero }}</strong> — {{ $etat->objet }}</td></tr>
    <tr><td class="l">Statut</td><td>{{ \App\Models\EtatPaie::STATUTS[$etat->statut] ?? $etat->statut }}</td></tr>
    <tr><td class="l">Période</td><td>du {{ $etat->periode_du->format('d/m/Y') }} au {{ $etat->periode_au->format('d/m/Y') }}</td></tr>
    <tr><td class="l">Référence du bordereau</td><td>{{ $etat->reference_bordereau ?? 'à attribuer à la transmission' }}</td></tr>
    <tr><td class="l">Enseignants</td><td>{{ $synthese['totaux']['enseignants'] }}</td></tr>
    <tr><td class="l">Heures de CM payées</td><td>{{ $h($synthese['totaux']['heures_cm']) }} h</td></tr>
    <tr><td class="l">Surveillances payées</td><td>{{ $synthese['totaux']['surveillances'] }}</td></tr>
    <tr><td class="l">Montant brut</td><td>{{ $fcfa($synthese['totaux']['brut']) }}</td></tr>
    <tr><td class="l">Retenue à la source</td><td>{{ $fcfa($synthese['totaux']['retenue']) }}</td></tr>
    <tr><td class="l"><strong>Net à virer</strong></td><td><strong>{{ $fcfa($synthese['totaux']['net']) }}</strong></td></tr>
  </table>

  <p class="note">L'état détaillé {{ $etat->numero }} est joint. Paiement par virement sur les comptes enregistrés aux dossiers de paie des enseignants.</p>

  <table class="signatures"><tr>
    <td>Le chef d'unité de la scolarité</td><td></td><td>Reçu par le service financier, le</td>
  </tr></table>
</body>
</html>
