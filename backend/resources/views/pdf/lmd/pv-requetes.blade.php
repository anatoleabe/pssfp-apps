<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>{{ $s->numero_pv }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Procès-verbal du jury des requêtes</h1>
  <p class="sous-titre">{{ $s->numero_pv }} · séance du {{ $s->tenue_le->format('d/m/Y') }} · jury désigné par {{ $s->jury->acte_reference }} du {{ $s->jury->acte_date?->format('d/m/Y') }}</p>
  <h2>Composition</h2>
  <p>
    @foreach ($s->jury->membres as $m){{ $m->libelle() }}@if (! $loop->last) ; @endif @endforeach
  </p>
  <h2>Requêtes examinées</h2>
  <table>
    <thead><tr><th>N°</th><th>Requête</th><th>Matricule</th><th>Objet</th><th>Mention</th><th class="n">Note retenue</th><th>Motif</th></tr></thead>
    <tbody>
      @foreach ($s->requetes as $r)
        <tr>
          <td>{{ $r->ordre_seance }}</td>
          <td>{{ $r->numero }}</td>
          <td>{{ $r->auditeur?->candidature?->matricule }}</td>
          <td>{{ $r->objet() }}</td>
          <td>{{ $r->mention ? \App\Models\Requete::MENTIONS[$r->mention] : '—' }}</td>
          <td class="n">{{ $r->note_retenue !== null ? number_format((float) $r->note_retenue, 2, ',', ' ') : '—' }}</td>
          <td>{{ $r->decision_motif }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  <table class="signatures"><tr><td>Le Rapporteur</td><td style="text-align:right;">Le Président du jury</td></tr></table>
  <p class="sceau">Clos le {{ $s->close_at?->timezone('Africa/Douala')->format('d/m/Y à H:i') }}. Sceau (SHA-256) : {{ $s->pv_sha256 }}.
    @if ($s->validee_at) Validé définitivement le {{ $s->validee_at->timezone('Africa/Douala')->format('d/m/Y') }} par le chef de la commission des requêtes. @else En attente de validation définitive. @endif</p>
</body>
</html>
