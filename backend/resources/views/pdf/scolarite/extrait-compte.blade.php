<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
@php
    $titre = 'Extrait de compte';
    $fcfa = fn (?int $m): string => $m === null ? '—' : number_format($m, 0, ',', ' ').' FCFA';
    $date = fn ($d): string => $d === null ? '—' : $d->format('d/m/Y');
    $statuts = \App\Models\Versement::STATUTS;
    $categorie = \App\Services\Scolarite\Solvabilite::LIBELLES[$totaux['categorie']];
    $campagne = $dossier?->campagne;
@endphp
<title>{{ $titre }} — {{ $dossier?->matricule }}</title>
@include('pdf.scolarite._liste-styles')
<style>
  body { font-size: 9.5pt; }
  h2 { font-family: 'Source Serif 4', Georgia, serif; color: #0F3A4A; font-size: 11pt; margin: 3mm 0 1.2mm; }
  table.ident { width: 100%; border-collapse: collapse; margin: 0 0 2mm; }
  table.ident td { padding: 0.5mm 2mm 0.5mm 0; vertical-align: top; }
  table.ident .l { color: #5F5F5F; width: 30mm; }
  table.liste th { font-size: 7.6pt; }
  table.liste td { font-size: 9pt; }
  table.liste tr.annee td { background: #F3EFF7; color: #3A2452; font-weight: 700; border-top: 0.8pt solid #A592BD; }
  .echu { color: #9B1C1C; font-weight: 700; }
  .gris { color: #8A8A8A; }
  table.synthese { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  table.synthese td { padding: 1mm 2.5mm; border-bottom: 0.5pt solid #E6E0EE; font-size: 9.8pt; }
  table.synthese td.n { text-align: right; font-weight: 700; white-space: nowrap; }
  table.synthese tr.fort td { background: #0F3A4A; color: #FFFFFF; font-weight: 700; font-size: 11pt; }
  .badge { display: inline-block; padding: 0.6mm 3mm; font-weight: 700; letter-spacing: .3pt; border: 1pt solid; }
  .badge.en_regle { color: #1E6B3A; border-color: #1E6B3A; }
  .badge.partiellement_insolvable { color: #8A5A00; border-color: #B8913F; }
  .badge.totalement_insolvable { color: #9B1C1C; border-color: #9B1C1C; }
</style>
</head>
<body>
<footer>Document interne — PSSFP, service financier · {{ $titre }} {{ $dossier?->matricule }} · édité le {{ $edite_le->format('d/m/Y à H:i') }}</footer>

@include('pdf._entete-pssfp')

<h1>{{ $titre }}</h1>
<p class="interne"><span>Document interne</span></p>

<table class="ident">
  <tr>
    <td class="l">Auditeur</td><td><strong>{{ $dossier?->nom }} {{ $dossier?->prenom }}</strong></td>
    <td class="l">Matricule</td><td><strong>{{ $dossier?->matricule ?? '—' }}</strong></td>
  </tr>
  <tr>
    <td class="l">Promotion</td><td>{{ $campagne?->promotion_numero ? $campagne->promotion_numero.'e promotion' : '—' }} ({{ $campagne?->anneeAcademique() ?? '—' }})</td>
    <td class="l">Statut</td><td>{{ \App\Models\Auditeur::STATUTS[$auditeur->statut] ?? $auditeur->statut }}</td>
  </tr>
  <tr>
    <td class="l">Situation</td><td><span class="badge {{ $totaux['categorie'] }}">{{ mb_strtoupper($categorie) }}</span></td>
    <td class="l">Édité le</td><td>{{ $edite_le->format('d/m/Y à H:i') }} (heure de Yaoundé)</td>
  </tr>
</table>

<h2>Ce qui est dû, année par année</h2>
@if ($annees === [])
  <p class="vide">Aucune inscription annuelle.</p>
@else
<table class="liste">
  <thead><tr><th>Année / échéance</th><th class="n">Dû</th><th class="n">Payé</th><th class="n">Reste</th><th>Situation</th></tr></thead>
  <tbody>
  @foreach ($annees as $a)
    <tr class="annee"><td colspan="5">{{ $a['annee'] }} — {{ $a['niveau'] }}@if ($a['remise'] > 0) · remise de {{ $fcfa($a['remise']) }}@endif</td></tr>
    @foreach ($a['paliers'] as $p)
      @continue($p['du'] === 0 && $p['paye'] === 0)
      <tr>
        <td>{{ $p['libelle'] }}@if ($p['echeance']) <span class="gris">· échéance {{ $date($p['echeance']) }}</span>@endif</td>
        <td class="n">{{ $fcfa($p['du']) }}</td>
        <td class="n">{{ $fcfa($p['paye']) }}</td>
        <td class="n">{{ $fcfa($p['reste']) }}</td>
        <td>@if ($p['reste'] === 0) Soldé @elseif ($p['echu']) <span class="echu">Échu impayé</span> @else À venir @endif</td>
      </tr>
    @endforeach
    <tr class="sous-total"><td>Total {{ $a['annee'] }}@if ($a['a_confirmer'] > 0) <span class="gris">(dont {{ $fcfa($a['a_confirmer']) }} à confirmer)</span>@endif</td>
      <td class="n">{{ $fcfa($a['du']) }}</td><td class="n">{{ $fcfa($a['verse']) }}</td><td class="n">{{ $fcfa($a['reste']) }}</td><td></td></tr>
  @endforeach
  </tbody>
</table>
@endif

<h2>Versements</h2>
@if ($versements === [])
  <p class="vide">Aucun versement enregistré.</p>
@else
<table class="liste">
  <thead><tr><th>Date</th><th>Référence</th><th>Mode · payé par</th><th>Reçu</th><th>Statut</th><th class="n">Montant</th></tr></thead>
  <tbody>
  @foreach ($versements as $v)
    @php $nul = in_array($v['statut'], [\App\Models\Versement::STATUT_ANNULE, \App\Models\Versement::STATUT_REMPLACE], true); @endphp
    <tr @class(['gris' => $nul])>
      <td class="mat">{{ $date($v['date']) }}</td>
      <td>{{ $v['reference'] }}</td>
      <td>{{ $v['mode'] }} · {{ $v['payeur'] }}</td>
      <td class="mat">{{ $v['recu'] ?? '—' }}</td>
      <td>{{ $statuts[$v['statut']] ?? $v['statut'] }}</td>
      <td class="n">@if ($nul)<s>{{ $fcfa($v['montant']) }}</s>@else{{ $fcfa($v['montant']) }}@endif</td>
    </tr>
  @endforeach
  <tr class="total"><td colspan="5">Total des versements valides</td><td class="n">{{ $fcfa($totaux['verse_valide']) }}</td></tr>
  </tbody>
</table>
@endif

<h2>Synthèse sur le parcours</h2>
<table class="synthese">
  <tr><td>Total dû (remises déduites)</td><td class="n">{{ $fcfa($totaux['du']) }}</td></tr>
  <tr><td>Total versé@if ($totaux['a_confirmer'] > 0) (dont {{ $fcfa($totaux['a_confirmer']) }} à confirmer par la scolarité)@endif</td><td class="n">{{ $fcfa($totaux['verse_valide']) }}</td></tr>
  @if ($totaux['avance'] > 0)
    <tr><td>Avance (versé au-delà du dû, à imputer)</td><td class="n">{{ $fcfa($totaux['avance']) }}</td></tr>
  @endif
  <tr><td>Échu impayé (droits d'inscription et tranches échues)</td><td class="n @if ($totaux['echu_impaye'] > 0) echu @endif">{{ $fcfa($totaux['echu_impaye']) }}</td></tr>
  <tr class="fort"><td>Reste à payer</td><td class="n">{{ $fcfa($totaux['reste']) }}</td></tr>
</table>

<p class="note">Versements à confirmer comptés comme payés ; versements annulés ou remplacés barrés et non comptés. Les montants sont ceux enregistrés à la date d'édition. Document interne : il ne remplace ni les reçus ni une attestation.</p>
</body>
</html>
