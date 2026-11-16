<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Annulation {{ $annulation->numero }}</title>
@include('pdf._recu-a4-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Scolarite\RecusVersements::fcfa($m);
    $tz = 'Africa/Douala';
@endphp
<style>
  .ident.large td { padding: 1mm 0; border-bottom: 1px solid #F0ECF5; }
  .ident.large .l { width: 38mm; }
</style>
</head>
<body>
@foreach (['EXEMPLAIRE DE L\'AUDITEUR', 'EXEMPLAIRE DE L\'ADMINISTRATION'] as $i => $copie)
<div class="exemplaire">
  @include('pdf._recu-a4-entete', ['titre' => 'ANNULATION DE REÇU', 'numero' => $annulation->numero, 'date' => 'Passée le '.$annulation->annule_at->setTimezone($tz)->format('d/m/Y à H:i'), 'copie' => $copie])

  <div class="annule">LE REÇU {{ $recu->numero }} EST ANNULÉ ET N'A PLUS D'EFFET</div>

  <table class="ident large">
    <tr><td class="l">Reçu annulé</td><td class="v">{{ $recu->numero }} du {{ $recu->emis_at->setTimezone($tz)->format('d/m/Y') }}</td></tr>
    <tr><td class="l">Auditeur</td><td class="v">{{ $recu->auditeur_nom }}@if ($recu->matricule) — matricule {{ $recu->matricule }}@endif</td></tr>
    <tr><td class="l">Année académique</td><td>{{ $recu->annee_academique }}</td></tr>
    <tr><td class="l">Montant du reçu</td><td>{{ $fcfa((int) $recu->montant) }} ({{ $lettres }})</td></tr>
    <tr><td class="l">Motif</td><td>{{ $annulation->motif }}</td></tr>
    @if ($recu->remplacant !== null && $recu->memeAuditeur($recu->remplacant))
    <tr><td class="l">Remplacé par</td><td class="v">Reçu {{ $recu->remplacant->numero }} du {{ $recu->remplacant->emis_at->setTimezone($tz)->format('d/m/Y') }}{{ ($correction ?? null) !== null ? ' (correction '.$correction->numero.')' : '' }}</td></tr>
    @elseif ($recu->remplacant !== null)
    <tr><td class="l">Correction</td><td>{{ ($correction ?? null) !== null ? 'Pièce '.$correction->numero : 'Versement corrigé' }}</td></tr>
    @endif
  </table>

  <table class="sig" style="margin-top:4mm"><tr>
    <td class="qr">@if ($qr ?? null)<img src="{{ $qr }}" alt="QR code de vérification du reçu">@endif</td>
    <td class="verif">@if ($urlVerification ?? null)Vérifier l'état du reçu : scannez le QR code ou ouvrez<br><span>{{ $urlVerification }}</span>@endif</td>
    <td>Cachet et signature du service financier — {{ $annulation->annuleur_nom }}<div class="cadre"></div></td>
  </tr></table>

  <div class="pied">Écriture passée par {{ $annulation->annuleur_nom }}, service administratif et financier du PSSFP. Le reçu {{ $recu->numero }} n'a plus d'effet ; un nouveau reçu peut être émis pour le versement.</div>
</div>
@if ($i === 0)<div class="coupe">✂ - - - - - - - - - - - - - - - - - - - - découper ici - - - - - - - - - - - - - - - - - - - - ✂</div>@endif
@endforeach
</body>
</html>
