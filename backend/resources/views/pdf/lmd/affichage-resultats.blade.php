<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Résultats {{ $d->numero_pv }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Liste des résultats</h1>
  <p class="sous-titre">
    Année académique {{ $d->annee_academique }} · {{ $d->niveau === 'M1' ? 'Master 1' : 'Master 2' }}{{ $d->parcours ? ' · '.$d->parcours->libelle : '' }} ·
    {{ \App\Models\EtapeSaisie::SESSIONS[$d->session] ?? $d->session }} · {{ $d->numero_pv }}
  </p>
  <table>
    <thead><tr><th>Matricule</th><th>Nom et prénom</th><th>Décision</th></tr></thead>
    <tbody>
      @foreach ($resultats as $r)
        @php $dossier = $r->inscriptionAnnuelle?->auditeur?->candidature; @endphp
        <tr>
          <td>{{ $dossier?->matricule }}</td>
          <td>{{ trim(mb_strtoupper((string) $dossier?->nom).' '.$dossier?->prenom) }}</td>
          <td>{{ \App\Models\ResultatAnnuel::DECISIONS[$r->decision] ?? $r->decision }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @if ($d->requetes_jusqu_au)
    <p class="sceau">Publié le {{ $d->publiee_at?->timezone('Africa/Douala')->format('d/m/Y') }}. Les requêtes sont recevables jusqu'au {{ $d->requetes_jusqu_au->format('d/m/Y') }}, depuis l'espace auditeur.</p>
  @endif
</body>
</html>
