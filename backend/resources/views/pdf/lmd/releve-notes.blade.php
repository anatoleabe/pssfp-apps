<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Relevé de notes {{ $numero }}</title>
@php
    $n = fn ($v, int $dec = 2): string => $v === null ? '—' : number_format((float) $v, $dec, ',', ' ');
    $modes = ['acquise' => 'Acquise', 'deliberation' => 'Acquise par décision du jury', 'capitalisee' => 'Capitalisée', 'non_acquise' => 'Non acquise'];
    $decisions = \App\Models\ResultatAnnuel::DECISIONS;
@endphp
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Relevé de notes</h1>
  <p class="sous-titre"><em>Transcript of records</em> · Année académique {{ $d->annee_academique }} · {{ $d->session === 'rattrapage' ? 'après rattrapage' : 'session normale' }}</p>

  <table>
    <tr><td style="border:none;width:45mm;color:#5C5C5C;">Nom et prénom / <em>Name</em></td><td style="border:none;"><strong>{{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}</strong></td></tr>
    <tr><td style="border:none;color:#5C5C5C;">Matricule</td><td style="border:none;">{{ $dossier?->matricule }}</td></tr>
    <tr><td style="border:none;color:#5C5C5C;">Formation</td><td style="border:none;">Master professionnel en finances publiques · {{ $inscription->niveau === 'M1' ? 'Master 1' : 'Master 2' }}{{ $inscription->parcours ? ' · '.$inscription->parcours->libelle : '' }}</td></tr>
  </table>

  <h2>Unités d'enseignement</h2>
  <table>
    <thead><tr><th>Sem.</th><th>Code</th><th>Intitulé</th><th class="n">Crédits</th><th class="n">Note / 20</th><th>Grade</th><th>Acquisition</th></tr></thead>
    <tbody>
      @foreach ($ues as $ue)
        <tr>
          <td>S{{ $ue['semestre'] }}</td>
          <td>{{ $ue['code'] }}</td>
          <td>{{ $ue['intitule'] }}</td>
          <td class="n">{{ $ue['credits'] }}</td>
          <td class="n">{{ $n($ue['note']) }}</td>
          <td>{{ $ue['grade'] ?? '—' }}</td>
          <td>{{ $modes[$ue['mode']] ?? $ue['mode'] }}{{ $ue['annee_acquisition'] ? ' en '.$ue['annee_acquisition'] : '' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Résultat de l'année</h2>
  <table>
    <thead><tr><th class="n">Crédits acquis</th><th class="n">Moyenne / 20</th><th class="n">MGP / 4</th><th>Décision</th><th>Mention</th></tr></thead>
    <tbody><tr>
      <td class="n">{{ $resultat->credits_acquis }}</td>
      <td class="n">{{ $n($resultat->moyenne) }}</td>
      <td class="n">{{ $n($resultat->mgp) }}</td>
      <td>{{ $decisions[$resultat->decision] ?? $resultat->decision }}</td>
      <td>{{ $resultat->mention ?? '—' }}</td>
    </tr></tbody>
  </table>
  <p style="font-size:8pt;color:#5C5C5C;">Délibération {{ $d->numero_pv }} du {{ $d->arretee_at?->timezone('Africa/Douala')->format('d/m/Y') }}, publiée le {{ $d->publiee_at?->timezone('Africa/Douala')->format('d/m/Y') }}.</p>

  <table class="signatures"><tr><td></td><td style="text-align:right;">Le Chef de la scolarité</td></tr></table>
  @include('pdf.lmd._verification')
</body>
</html>
