<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Reçu {{ $recu->numero }}</title>
@include('pdf._recu-a4-styles')
@php
    $fcfa = fn (int $m): string => \App\Services\Scolarite\RecusVersements::fcfa($m);
    $modes = \App\Models\Versement::MODES;
    $payeurs = \App\Models\Versement::PAYEURS;
    $niveaux = \App\Models\BaremeScolarite::NIVEAUX;
    $tz = 'Africa/Douala';
    // Ventilation figée à l'émission ; un reçu émis avant elle s'affiche avec sa seule ligne « objet ».
    $lignes = $recu->ventilation ?? [[
        'libelle' => $recu->objet, 'code' => null, 'du' => null, 'ce_versement' => (int) $recu->montant,
        'total_verse' => (int) $recu->total_verse, 'reste' => (int) $recu->reste_a_payer,
    ]];
    $somme = fn (string $k): int => (int) array_sum(array_map(fn (array $l): int => (int) ($l[$k] ?? 0), $lignes));
    $avecDu = $recu->ventilation !== null;
@endphp
</head>
<body>
@foreach (['EXEMPLAIRE DE L\'AUDITEUR', 'EXEMPLAIRE DE L\'ADMINISTRATION'] as $i => $copie)
<div class="exemplaire">
  @include('pdf._recu-a4-entete', ['titre' => 'REÇU DE PAIEMENT', 'numero' => $recu->numero, 'date' => 'Émis le '.$recu->emis_at->setTimezone($tz)->format('d/m/Y à H:i'), 'copie' => $copie])

  @if ($recu->annulation !== null)
    <div class="annule">ANNULÉ — écriture {{ $recu->annulation->numero }} du {{ $recu->annulation->annule_at->setTimezone($tz)->format('d/m/Y') }}@if ($recu->remplacant !== null) — {{ $recu->memeAuditeur($recu->remplacant) ? 'remplacé par '.$recu->remplacant->numero : 'corrigé'.(($correction ?? null) !== null ? ' (correction '.$correction->numero.')' : '') }}@endif</div>
  @endif
  @if ($recu->remplace !== null)
    @if ($recu->memeAuditeur($recu->remplace))
    <div class="mention">Remplace le reçu {{ $recu->remplace->numero }}@if ($recu->remplace->annulation !== null), annulé le {{ $recu->remplace->annulation->annule_at->setTimezone($tz)->format('d/m/Y') }}@endif{{ ($correction ?? null) !== null ? ' (correction '.$correction->numero.')' : '' }}.</div>
    @else
    <div class="mention">Reçu émis après correction d'un versement{{ ($correction ?? null) !== null ? ' (correction '.$correction->numero.')' : '' }}.</div>
    @endif
  @endif

  <table class="ident quatre">
    <tr><td class="l">Reçu de</td><td class="v">{{ $recu->auditeur_nom }}</td><td class="l2">Année académique</td><td class="v">{{ $recu->annee_academique }}</td></tr>
    <tr><td class="l">Matricule</td><td class="v">{{ $recu->matricule ?? '—' }}</td><td class="l2">Niveau</td><td class="v">{{ $niveaux[$recu->niveau] ?? $recu->niveau }}</td></tr>
    <tr><td class="l">Versement</td><td>{{ $modes[$recu->mode] ?? \App\Models\Versement::libelleMode($recu->mode) }}, bordereau <strong>{{ $recu->reference }}</strong> du {{ $recu->date_versement->format('d/m/Y') }}</td><td class="l2">Payé par</td><td>{{ $payeurs[$recu->payeur] ?? $recu->payeur }}</td></tr>
  </table>

  <table class="vent">
    <tr><th>Rubrique</th><th>Imputation</th><th class="n">Montant dû</th><th class="n">Ce versement</th><th class="n">Total versé</th><th class="n">Reste</th></tr>
    @foreach ($lignes as $l)
    <tr class="{{ ($l['du'] ?? null) === 0 ? 'nul' : '' }}">
      <td>{{ $l['libelle'] }}</td>
      <td class="code">@if (! empty($l['code']))<span>{{ $l['code'] }}</span>@elseif (($l['du'] ?? null) === null && $recu->ventilation !== null)—@else<i>à préciser</i>@endif</td>
      <td class="n">{{ ($l['du'] ?? null) === null ? '—' : $fcfa($l['du']) }}</td>
      <td class="n ce">{{ $l['ce_versement'] > 0 ? $fcfa($l['ce_versement']) : '—' }}</td>
      <td class="n">{{ ($l['total_verse'] ?? null) === null ? '—' : $fcfa($l['total_verse']) }}</td>
      <td class="n">{{ ($l['reste'] ?? null) === null ? '—' : ($l['reste'] === 0 ? 'Soldé' : $fcfa($l['reste'])) }}</td>
    </tr>
    @endforeach
    <tr class="tot"><td colspan="2">Total</td><td class="n">{{ $avecDu ? $fcfa($somme('du')) : '—' }}</td><td class="n ce">{{ $fcfa((int) $recu->montant) }}</td>
      <td class="n">{{ $fcfa((int) $recu->total_verse) }}</td><td class="n">{{ (int) $recu->reste_a_payer === 0 ? 'Soldé' : $fcfa((int) $recu->reste_a_payer) }}</td></tr>
  </table>

  <table style="margin-top:1.2mm"><tr>
    <td class="lettres">Montant reçu : <strong>{{ $fcfa((int) $recu->montant) }}</strong><br>Arrêté le présent reçu à la somme de {{ $lettres }}.</td>
    <td class="lettres" style="width:52mm;text-align:right;border-left:none">Reste à payer sur l'année<br><strong>{{ (int) $recu->reste_a_payer === 0 ? 'Soldé' : $fcfa((int) $recu->reste_a_payer) }}</strong></td>
  </tr></table>

  <table class="sig"><tr>
    <td class="qr">@if ($qr ?? null)<img src="{{ $qr }}" alt="QR code de vérification du reçu">@endif</td>
    <td class="verif">@if ($urlVerification ?? null)Vérifier ce reçu : scannez le QR code ou ouvrez<br><span>{{ $urlVerification }}</span>@endif</td>
    <td>Cachet et signature du service financier — {{ $recu->emetteur_nom }}<div class="cadre"></div></td>
  </tr></table>

  <div class="pied">@if ($recu->verifie_le !== null)Versement vérifié par la scolarité{{ $recu->verifie_par_nom !== null ? ' : '.$recu->verifie_par_nom.',' : '' }} le {{ $recu->verifie_le->format('d/m/Y') }}. @endif Montants arrêtés à la date d'émission. Ce reçu ne vaut que revêtu du cachet du service financier.</div>
</div>
@if ($i === 0)<div class="coupe">✂ - - - - - - - - - - - - - - - - - - - - découper ici - - - - - - - - - - - - - - - - - - - - ✂</div>@endif
@endforeach
</body>
</html>
