<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $d->numero_pv ?? 'Projet de PV' }}</title>
@php
    $n = fn ($v, int $dec = 2): string => $v === null ? '—' : number_format((float) $v, $dec, ',', ' ');
@endphp
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Procès-verbal de délibération</h1>
  <p class="sous-titre">
    {{ $d->numero_pv ?? 'PROJET — délibération non arrêtée' }} ·
    Année académique {{ $d->annee_academique }} · {{ $d->niveau === 'M1' ? 'Master 1' : 'Master 2' }}{{ $d->parcours ? ' · '.$d->parcours->libelle : '' }} ·
    {{ \App\Models\EtapeSaisie::SESSIONS[$d->session] ?? $d->session }}
  </p>
  @if ($d->rectifie_id)
    <p><strong>PV rectificatif</strong> du {{ $d->rectifiee?->numero_pv }} — acte {{ $d->acte_rectificatif }} — motif : {{ $d->motif_rectification }}.
      Écarts : {{ $ecarts['favorables'] }} favorable(s), {{ $ecarts['defavorables'] }} défavorable(s).</p>
  @endif

  <h2>Jury</h2>
  @if ($d->jury)
    <p>Désigné par {{ $d->jury->acte_reference }} du {{ $d->jury->acte_date?->format('d/m/Y') }} ({{ $d->jury->acte_signataire }}).
      Séance du {{ $d->seance_ouverte_at?->timezone('Africa/Douala')->format('d/m/Y à H:i') ?? '—' }} ;
      quorum {{ $d->quorum_atteint ? 'atteint' : 'non atteint' }}.</p>
    <table>
      <thead><tr><th>Qualité</th><th>Nom</th><th>Présence</th><th>Signature</th></tr></thead>
      <tbody>
        @foreach ($participations as $p)
          <tr>
            <td>{{ \App\Models\MembreJury::ROLES[$p->membre->role] ?? $p->membre->role }}</td>
            <td>{{ trim(($p->membre->titre ? $p->membre->titre.' ' : '').$p->membre->nom) }}</td>
            <td>{{ $p->present ? 'Présent' : 'Absent — '.$p->motif_absence }}</td>
            <td>{{ $p->signe_at ? 'Signé le '.$p->signe_at->timezone('Africa/Douala')->format('d/m/Y') : '' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <h2>Mesures du jury</h2>
  @if ($mesures->isEmpty())
    <p>Le jury n'a pris aucune mesure : résultats par application stricte des règles (sans délibération).</p>
  @else
    <ol>
      @foreach ($mesures as $m)
        <li>{{ $m->enClair() }} — {{ $m->beneficiaires ?? 0 }} UE concernée(s).</li>
      @endforeach
    </ol>
  @endif

  <h2>Résultats</h2>
  <table>
    <thead><tr><th>Matricule</th><th>Nom et prénom</th><th class="n">Crédits</th><th class="n">Moyenne</th><th class="n">MGP</th><th>Décision</th><th>Mention</th><th class="n">Rang</th><th>Mesures</th></tr></thead>
    <tbody>
      @foreach ($resultats as $r)
        @php $dossier = $r->inscriptionAnnuelle?->auditeur?->candidature; @endphp
        <tr>
          <td>{{ $dossier?->matricule }}</td>
          <td>{{ trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom) }}</td>
          <td class="n">{{ $r->credits_acquis }}</td>
          <td class="n">{{ $n($r->moyenne) }}</td>
          <td class="n">{{ $n($r->mgp) }}</td>
          <td>{{ $r->decision }}{{ $r->decision !== $r->decision_calculee ? ' (calculée : '.$r->decision_calculee.')' : '' }}</td>
          <td>{{ $r->mention }}{{ $r->felicitations_proposees ? ' — félicitations proposées' : '' }}</td>
          <td class="n">{{ $r->rang }}</td>
          <td>{{ $r->mesures }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Synthèse</h2>
  <table>
    <thead><tr><th class="n">Effectif</th><th class="n">Admis (AD)</th><th class="n">Autorisés à soutenir (AS)</th><th class="n">Ajournés (AJ)</th><th class="n">Taux de réussite</th></tr></thead>
    <tbody><tr>
      <td class="n">{{ $synthese['effectif'] }}</td><td class="n">{{ $synthese['AD'] }}</td><td class="n">{{ $synthese['AS'] }}</td><td class="n">{{ $synthese['AJ'] }}</td>
      <td class="n">{{ $synthese['effectif'] > 0 ? $n(100 * ($synthese['AD'] + $synthese['AS']) / $synthese['effectif'], 1).' %' : '—' }}</td>
    </tr></tbody>
  </table>

  <table class="signatures">
    <tr>
      <td>Le Rapporteur</td>
      <td style="text-align:right;">Le Président du jury</td>
    </tr>
  </table>

  @if ($d->pv_sha256)
    <p class="sceau">Arrêté le {{ $d->arretee_at?->timezone('Africa/Douala')->format('d/m/Y à H:i') }}. Sceau des résultats (SHA-256) : {{ $d->pv_sha256 }}.
      Toute modification des résultats après l'arrêt est impossible sans PV rectificatif.</p>
  @endif
</body>
</html>
