{{--
  Communiqué conjoint MINFI / Université de Yaoundé II portant publication des
  résultats. Gabarit : communiqué de la 13e promotion (modèle de la scolarité).
  @var \App\Services\ResultatsSelection\ResultatsSelection $r
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Communiqué des résultats — {{ $r->promotionAbregee() }} promotion</title>
@include('pdf.resultats._styles')
<style>
  table.entete { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
  table.entete td { vertical-align: top; text-align: center; font-size: 8pt; line-height: 1.25; }
  table.entete td.texte { width: 37%; }
  table.entete td.logos { width: 26%; vertical-align: middle; }
  table.entete td.logos img { height: 22mm; margin: 0 1.5mm; }
  h1 { font-size: 17pt; text-align: center; margin: 4pt 0 2pt; letter-spacing: 0.5pt; }
  .reference { text-align: center; font-weight: 700; margin-bottom: 9pt; }
  .autorites { font-weight: 700; text-align: center; font-size: 12pt; margin: 9pt 0 2pt; }
  h2 { font-size: 11pt; margin: 10pt 0 2pt; }
</style>
</head>
<body>
<footer>
  @foreach ($piedDePage as $i => $ligne)
    <div @class(['web' => $i > 0])>{{ $ligne }}</div>
  @endforeach
</footer>

<table class="entete">
  <tr>
    <td class="texte">@foreach ($enteteUniversite as $ligne)<div>{{ $ligne }}</div>@endforeach</td>
    <td class="logos"><img src="{{ $logoUy2 }}" alt="Université de Yaoundé II"><img src="{{ $logoPssfp }}" alt="PSSFP"></td>
    <td class="texte">@foreach ($enteteMinfi as $ligne)<div>{{ $ligne }}</div>@endforeach</td>
  </tr>
</table>

<h1>JOINT PRESS RELEASE / COMMUNIQUE CONJOINT</h1>
<div class="reference">{{ $r->seance->referenceCommunique() }} of {{ \App\Services\ResultatsSelection\SeanceJury::BLANC }}</div>

<p class="center">Announcing the results of the call for application for the entrance into the first year of the
  Master's degree cycle in Public Finance, for the {{ str_replace('-', ' – ', $r->anneeAcademique) }} Academic year.</p>
<p class="center italic">Portant publication des résultats de l’appel à candidatures pour l’entrée en première année
  du cycle de Master en Finances Publiques au titre de l’année académique {{ str_replace('-', ' – ', $r->anneeAcademique) }}.</p>

<div class="autorites">THE MINISTER OF FINANCE AND THE RECTOR OF THE UNIVERSITY OF YAOUNDE II – SOA</div>
<div class="autorites italic">LE MINISTRE DES FINANCES ET LE RECTEUR DE L’UNIVERSITE DE YAOUNDE II – SOA</div>
<p class="center bold" style="margin-top: 6pt;">ANNOUNCE / <span class="italic">COMMUNIQUENT</span> :</p>

<p>The following candidates, listed in alphabetical order, are declared admitted to the first year of the Master's
  degree in Public Finance for the {{ $r->anneeAcademiqueSlash() }} academic year, subject to presentation of their
  original diplomas.</p>
<p class="italic">Les candidats dont les noms suivent, classés par ordre alphabétique, sont déclarés admis, sous
  réserve de la présentation des originaux de leurs diplômes, en première année de Master en Finances Publiques au
  titre de l’année académique {{ $r->anneeAcademiqueSlash() }}.</p>
<p class="center">They are / <span class="italic">Il s’agit de</span> :</p>

<h2>National candidates / <span class="italic">Candidats nationaux</span> :</h2>
@if ($r->nationaux === [])
  <p class="neant">None / Néant</p>
@else
  <table class="grille">
    <thead><tr><th>N°</th><th>FULL NAME / NOMS ET PRENOMS</th><th style="width: 36mm;">TRAINING METHOD / MODE FORMATION</th></tr></thead>
    <tbody>
      @foreach ($r->nationaux as $i => $c)
        <tr><td class="num n">{{ $i + 1 }}</td><td>{{ $c->nomComplet() }}</td><td class="code">{{ $c->mode }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endif

<h2>Foreign candidates / <span class="italic">Candidats étrangers</span> :</h2>
@if ($r->etrangers === [])
  <p class="neant">None / Néant</p>
@else
  <table class="grille">
    <thead><tr><th>N°</th><th>FULL NAME / NOMS ET PRENOMS</th><th style="width: 34mm;">NATIONALITY / NATIONALITE</th><th style="width: 34mm;">TRAINING METHOD / MODE FORMATION</th></tr></thead>
    <tbody>
      @foreach ($r->etrangers as $i => $c)
        <tr><td class="num n">{{ $i + 1 }}</td><td>{{ $c->nomComplet() }}</td><td class="code">{{ $c->nationalite }}</td><td class="code">{{ $c->mode }}</td></tr>
      @endforeach
    </tbody>
  </table>
@endif

<div class="legende">(P) = Présentiel / In-person<br>(D) = Distanciel / Distance learning</div>

<div class="bloc-signatures">
<div class="fait-a">Yaoundé, le {{ \App\Services\ResultatsSelection\SeanceJury::BLANC }}……………</div>

<table class="signatures">
  <tr>
    <td><div class="bold souligne">The Rector of the University of Yaoundé II</div><div class="italic souligne">Le Recteur de l’Université de Yaoundé II</div></td>
    <td><div class="bold souligne">The Minister of Finance</div><div class="italic souligne">Le Ministre des Finances</div></td>
  </tr>
  <tr><td class="espace"></td><td class="espace"></td></tr>
</table>
</div>
</body>
</html>
