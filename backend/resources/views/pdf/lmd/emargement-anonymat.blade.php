<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Émargement {{ $e->libelle() }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Liste d'émargement</h1>
  <p class="sous-titre">{{ $e->ecue?->code }} — {{ $e->ecue?->intitule }} · {{ $e->session === 'rattrapage' ? 'rattrapage' : 'session normale' }}</p>
  <table>
    <thead><tr><th>Matricule</th><th>Nom et prénom</th><th style="width:60mm;">Signature</th></tr></thead>
    <tbody>
      @foreach ($inscrits as $ip)
        @php $d = $ip->inscriptionAnnuelle?->auditeur?->candidature; @endphp
        <tr><td>{{ $d?->matricule }}</td><td>{{ trim(mb_strtoupper((string) $d?->nom).' '.$d?->prenom) }}</td><td style="height:9mm;"></td></tr>
      @endforeach
    </tbody>
  </table>
</body>
</html>
