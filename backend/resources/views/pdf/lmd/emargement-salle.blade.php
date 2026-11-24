<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Émargement — {{ $p->libelle() }}</title>
@include('pdf.lmd._entete')
<style>
  .page { page-break-after: always; }
  .page.derniere { page-break-after: auto; }
  .cartouche td { border: none; padding: .6mm 2mm .6mm 0; }
</style>
</head>
<body>
  @forelse ($pages as $page)
    @php $refuses = array_intersect_key($page['nonAutorises'], $page['candidats']->pluck('inscription_annuelle_id')->flip()->all()); @endphp
    <div class="page {{ $loop->last ? 'derniere' : '' }}">
      <h1>Liste d'émargement</h1>
      <p class="sous-titre">{{ $p->libelle() }} · {{ $page['epreuve']->code_ecue }} — {{ $page['epreuve']->intitule }}</p>
      <table class="cartouche">
        <tr><td><strong>Date :</strong> {{ $page['epreuve']->date_epreuve->locale('fr')->translatedFormat('l j F Y') }}, {{ $page['epreuve']->heures() }}</td>
          <td><strong>Salle :</strong> {{ $page['salle']->libelle }} ({{ $page['candidats']->count() }} candidat(s))</td></tr>
        <tr><td colspan="2"><strong>Surveillants :</strong> {{ $page['surveillants']->pluck('name')->implode(', ') ?: 'à désigner' }}</td></tr>
      </table>
      @if ($refuses !== [])
        <p>{{ count($refuses) }} auditeur(s) non autorisé(s) à composer : échéance en retard, sans levée de restriction.</p>
      @endif
      <table>
        <thead><tr><th style="width:9mm;">N°</th><th>Matricule</th><th>Nom et prénom</th><th style="width:60mm;">Signature</th></tr></thead>
        <tbody>
          @foreach ($page['candidats'] as $ip)
            <tr><td>{{ $loop->iteration }}</td><td>{{ $ip->inscriptionAnnuelle?->auditeur?->candidature?->matricule }}</td>
              <td>{{ \App\Services\Lmd\CandidatsExamen::nom($ip) }}</td>
              <td style="height:9mm;">{{ isset($page['nonAutorises'][$ip->inscription_annuelle_id]) ? 'Non autorisé à composer (situation financière)' : '' }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <table class="signatures"><tr><td>Présents : ………… &nbsp; Absents : ………… &nbsp; Copies remises : …………</td><td style="text-align:right;">Signature des surveillants</td></tr></table>
    </div>
  @empty
    <h1>Liste d'émargement</h1>
    <p class="sous-titre">Aucune salle n'est affectée aux épreuves de ce planning.</p>
  @endforelse
</body>
</html>
