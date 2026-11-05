<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Relevé de situation — {{ $dossier->matricule }}</title>
@php
    $fcfa = fn (?int $m): string => $m === null ? '—' : number_format($m, 0, ',', ' ').' FCFA';
    $libelles = ['inscription' => 'Inscription', 'tranche1' => '1re tranche', 'tranche2' => '2e tranche'];
    $modes = \App\Models\Versement::MODES + ['reprise' => 'Reprise du pointage'];
    $niveaux = \App\Models\BaremeScolarite::NIVEAUX;
    $date = fn (?string $d): string => $d === null ? '—' : \Carbon\CarbonImmutable::parse($d)->format('d/m/Y');
@endphp
<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 14mm 14mm 16mm; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 10pt; color: #3C3C3C; }
  h1 { font-family: 'Source Serif 4', Georgia, serif; color: #4A2E67; font-size: 17pt; margin: 0 0 2mm; }
  h2 { font-family: 'Source Serif 4', Georgia, serif; color: #0F3A4A; font-size: 12pt; margin: 7mm 0 2mm; }
  .entete { border-bottom: 2px solid #4A2E67; padding-bottom: 3mm; margin-bottom: 5mm; }
  .entete p { margin: 0; color: #5C5C5C; font-size: 9pt; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 2mm 2.5mm; border-bottom: 1px solid #E6E0EE; text-align: left; }
  th { background: #FAF7F2; color: #4A2E67; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .3pt; }
  td.n, th.n { text-align: right; }
  tfoot td { font-weight: 700; border-top: 2px solid #4A2E67; }
  .ident td { border: none; padding: 1mm 0; }
  .ident td.l { color: #5C5C5C; width: 40mm; }
  .note { margin-top: 8mm; font-size: 8.5pt; color: #5C5C5C; border-left: 3px solid #D4AF6A; padding-left: 3mm; }
</style>
</head>
<body>
  <div class="entete">
    <p>Programme Supérieur de Spécialisation en Finances Publiques</p>
    <h1>Relevé de situation financière</h1>
    <p>Édité le {{ $edite_le->format('d/m/Y à H:i') }}</p>
  </div>

  <table class="ident">
    <tr><td class="l">Auditeur</td><td><strong>{{ mb_strtoupper((string) $dossier->nom) }} {{ $dossier->prenom }}</strong></td></tr>
    <tr><td class="l">Matricule</td><td>{{ $dossier->matricule }}</td></tr>
    <tr><td class="l">Promotion</td><td>{{ $situation['promotion_numero'] }}e promotion</td></tr>
    <tr><td class="l">Année et niveau</td><td>{{ $situation['annee_academique'] }} · {{ $niveaux[$situation['niveau']] ?? $situation['niveau'] }}</td></tr>
  </table>

  <h2>Paliers</h2>
  <table>
    <thead><tr><th>Palier</th><th class="n">Dû</th><th class="n">Payé</th><th class="n">Reste</th><th>Échéance</th></tr></thead>
    <tbody>
      @foreach ($situation['paliers'] as $p)
        <tr>
          <td>{{ $libelles[$p['cle']] }}</td>
          <td class="n">{{ $fcfa($p['du']) }}</td>
          <td class="n">{{ $fcfa($p['paye']) }}</td>
          <td class="n">{{ $p['solde'] ? 'Soldé' : $fcfa($p['reste']) }}</td>
          <td>{{ $p['echeance_texte'] ?? $date($p['echeance']) }}</td>
        </tr>
      @endforeach
    </tbody>
    <tfoot><tr><td>Total</td><td class="n">{{ $fcfa($situation['du_total']) }}</td><td class="n">{{ $fcfa($situation['verse']) }}</td><td class="n">{{ $fcfa($situation['reste']) }}</td><td></td></tr></tfoot>
  </table>
  @if (($situation['remise'] ?? 0) > 0)
    <p>Remise accordée : {{ $fcfa($situation['remise']) }}</p>
  @endif
  @if ($situation['trop_percu'] > 0)
    <p>Trop-perçu : {{ $fcfa($situation['trop_percu']) }}</p>
  @endif

  <h2>Versements enregistrés</h2>
  @if ($situation['versements'] === [])
    <p>Aucun versement enregistré à ce jour.</p>
  @else
    <table>
      <thead><tr><th>Date</th><th>Mode</th><th>Référence</th><th class="n">Montant</th></tr></thead>
      <tbody>
        @foreach ($situation['versements'] as $v)
          <tr><td>{{ $date($v['date']) }}</td><td>{{ $modes[$v['mode']] ?? $v['mode'] }}</td><td>{{ $v['reference'] }}</td><td class="n">{{ $fcfa($v['montant']) }}</td></tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <p class="note">Ce relevé récapitule les versements enregistrés par la scolarité du PSSFP. Il ne remplace pas le bordereau de la banque, qui fait foi en cas de désaccord.</p>
</body>
</html>
