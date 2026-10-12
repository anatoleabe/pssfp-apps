{{--
  Procès-verbal des délibérations du jury de sélection. Gabarit : PV des 12e et
  13e promotions (modèles Word de la scolarité).
  @var \App\Services\ResultatsSelection\ResultatsSelection $r
--}}
@php
    $blanc = \App\Services\ResultatsSelection\SeanceJury::BLANC;
    $titre = 'PROCES-VERBAL DU JURY DE SELECTION DES CANDIDATS A LA '.$r->promotionOrdinal()
        .' PROMOTION DU PROGRAMME DE MASTER PROFESSIONNEL EN FINANCES PUBLIQUES';
    $signataire = fn (?array $m): string => $m['nom'] ?? $blanc;
    $membres = $r->membresSimples();
@endphp
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>PV du jury de sélection — {{ $r->promotionAbregee() }} promotion</title>
@include('pdf.resultats._styles')
<style>
  img.entete { width: 100%; margin-bottom: 8pt; }
  table.ref { width: 100%; font-size: 9.5pt; font-weight: 700; margin-bottom: 10pt; }
  .titre { border: 1.2pt solid #1f1f1f; padding: 7pt 10pt; text-align: center; font-weight: 700; font-size: 12pt; margin: 0 0 12pt; }
  .titre .annee { margin-top: 4pt; font-size: 11pt; }
  h2 { font-size: 11pt; font-weight: 700; text-decoration: underline; margin: 12pt 0 5pt; }
  h3 { font-size: 10pt; font-weight: 700; text-align: center; margin: 10pt 0 2pt; }
  ul.criteres { margin: 0 0 6pt 10mm; padding: 0; }
  ul.criteres li { margin-bottom: 1pt; }
  table.signatures .role { font-weight: 700; }
  table.signatures .membre { padding-top: 16mm; }
</style>
</head>
<body>
<footer>
  @foreach ($piedDePage as $i => $ligne)
    <div @class(['web' => $i > 0])>{{ $ligne }}</div>
  @endforeach
</footer>

<img class="entete" src="{{ $entete }}" alt="République du Cameroun — Ministère des Finances — PSSFP">

<table class="ref">
  <tr><td>{{ $r->seance->referencePv() }}</td><td class="right">Yaoundé, le {{ $blanc }}</td></tr>
</table>

<div class="titre">{{ $titre }}<div class="annee">ANNEE ACADEMIQUE {{ $r->anneeAcademique }}</div></div>

<p>{{ $r->seance->dateEnLettres() }}, s’est réuni, dans les locaux du Programme Supérieur de Spécialisation en
  Finances Publiques (PSSFP), le jury de sélection des candidats à la {{ \App\Support\NombreEnLettres::ordinal($r->promotionNumero) }}
  promotion du Master Professionnel en Finances Publiques, au titre de l’année académique {{ $r->anneeAcademique }}.</p>

<p>Ont pris part aux travaux du jury, les membres suivants désignés par la note de service
  {{ $r->seance->noteService() }} portant désignation des membres du jury de sélection des candidats de la
  {{ $r->promotionAbregee() }} promotion pour l’admission en 1ère année de Master.</p>

<table class="grille">
  <thead><tr><th>N°</th><th>Noms et prénoms</th><th>Qualité</th><th>Fonction</th></tr></thead>
  <tbody>
    @foreach ($r->jury as $i => $m)
      <tr><td class="num n">{{ $i + 1 }}</td><td>{{ $m['nom'] }}</td><td>{{ $m['qualite'] }}</td><td>{{ $m['fonction'] }}</td></tr>
    @endforeach
  </tbody>
</table>

<h2>METHODOLOGIE</h2>
<p>Les membres du jury ont procédé au rappel des critères de sélection tenant aux conditions d’éligibilité et
  critères académiques d’une part, puis aux critères socioprofessionnels liés à la valorisation des acquis de
  l’expérience et à la pertinence du projet professionnel du candidat pour la formation sollicitée. Ainsi, outre
  les critères académiques, les principales exigences suivantes ont été retenues :</p>
<ul class="criteres">
  @foreach ($criteres as $critere)<li>{{ $critere }}</li>@endforeach
</ul>

<h2>PROCESSUS D’EXAMEN DES DOSSIERS</h2>
<p>Le secrétariat technique conduit par {{ $secretariat }}, a procédé au préalable à l’examen des inscriptions en
  ligne des {{ $r->totalEnLigne }} candidats afin de s’assurer du dépôt physique effectif des dossiers. Par la suite,
  le jury a examiné chacun des dossiers qui lui ont été présentés, aussi bien dans la forme que dans le fond, sur la
  base des critères prédéfinis. Un total de {{ $r->totalRetenus() }} dossiers physiques a ainsi été examiné et retenu.
  Ces dossiers se répartissent suivant les critères de nationalité, de genre et de mode de formation de la manière
  suivante :</p>

<h3>REPARTITION GENERALE DES DOSSIERS DEPOSES PAR PAYS</h3>
@include('pdf.resultats._repartition', ['repartition' => $r->parPays, 'entete' => 'SPECIALITE \\ PAYS', 'pourcentages' => false])

<h3>REPARTITION DES DOSSIERS DEPOSES SELON LE GENRE</h3>
@include('pdf.resultats._repartition', ['repartition' => $r->parGenre, 'entete' => 'SPECIALITE / GENRE', 'pourcentages' => true])

<h3>REPARTITION DES DOSSIERS DEPOSES PAR MODE DE FORMATION</h3>
@include('pdf.resultats._repartition', ['repartition' => $r->parMode, 'entete' => 'SPECIALITE / MODE DE FORMATION', 'pourcentages' => true])

<h2>LISTE DES CANDIDATS RETENUS POUR LA FORMATION</h2>
<p>Après avoir délibéré, le jury a retenu, sous réserve de la présentation des originaux de leurs diplômes, les
  candidats dont les noms suivent classés par ordre alphabétique :</p>

<p class="bold souligne">Candidats nationaux :</p>
@if ($r->nationaux === [])
  <p class="neant">Néant</p>
@else
  <table class="grille">
    <thead><tr><th>N°</th><th>NOMS</th><th>PRENOMS</th><th style="width: 26mm;">MODE FORMATION</th></tr></thead>
    <tbody>
      @foreach ($r->nationaux as $i => $c)
        <tr><td class="num n">{{ $i + 1 }}</td><td>{{ $c->nom }}</td><td>{{ $c->prenoms }}</td><td class="code">{{ $c->mode }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endif

<p class="bold souligne">Candidats étrangers :</p>
@if ($r->etrangers === [])
  <p class="neant">Néant</p>
@else
  <table class="grille">
    <thead><tr><th>N°</th><th>NOMS</th><th>PRENOMS</th><th style="width: 30mm;">NATIONALITE</th><th style="width: 26mm;">MODE FORMATION</th></tr></thead>
    <tbody>
      @foreach ($r->etrangers as $i => $c)
        <tr><td class="num n">{{ $i + 1 }}</td><td>{{ $c->nom }}</td><td>{{ $c->prenoms }}</td><td class="code">{{ $c->nationalite }}</td><td class="code">{{ $c->mode }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endif

<div class="legende">(P) = Présentiel<br>(D) = Distanciel</div>

<div class="bloc-signatures">
<div class="fait-a">Yaoundé, le {{ $blanc }}……………</div>

<table class="signatures">
  <tr>
    <td><div class="role">LE RAPPORTEUR</div><div class="espace"></div><div>{{ $signataire($r->membre('rapporteur')) }}</div></td>
    <td><div class="role">LE PRESIDENT DU JURY</div><div class="espace"></div><div>{{ $signataire($r->membre('president')) }}</div></td>
  </tr>
  <tr>
    <td style="padding-top: 8mm;"><div class="role">1er VICE PRESIDENT DU JURY</div><div class="espace"></div><div>{{ $signataire($r->membre('vice_president_1')) }}</div></td>
    <td style="padding-top: 8mm;"><div class="role">2e VICE PRESIDENT DU JURY</div><div class="espace"></div><div>{{ $signataire($r->membre('vice_president_2')) }}</div></td>
  </tr>
</table>
</div>

<div class="bloc-signatures">
<h3 style="margin-top: 10mm;">LES MEMBRES</h3>
<table class="signatures">
  @foreach (array_chunk($membres, 2) as $paire)
    <tr>
      @foreach ($paire as $m)<td class="membre">{{ $m['nom'] }}</td>@endforeach
      @if (count($paire) === 1)<td></td>@endif
    </tr>
  @endforeach
</table>
</div>
</body>
</html>
