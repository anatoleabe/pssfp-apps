{{-- En-tête des listes : en-tête officiel, titre, mention « Document interne », critères et date d'édition. La pagination est posée par ListesAuditeurs. --}}
<footer>Document interne — PSSFP, scolarité · {{ $titre }} · édité le {{ $edite_le->format('d/m/Y à H:i') }}</footer>

@include('pdf._entete-pssfp')

<h1>{{ $titre }}</h1>
<p class="interne"><span>Document interne</span></p>

<table class="criteres">
  @foreach (array_chunk($criteres, 3, true) as $rangee)
    <tr>
      @foreach ($rangee as $libelle => $valeur)
        <td><span class="l">{{ $libelle }}&nbsp;:</span> <strong>{{ $valeur }}</strong></td>
      @endforeach
    </tr>
  @endforeach
</table>
<p class="edition">Édité le {{ $edite_le->format('d/m/Y à H:i') }} (heure de Yaoundé)</p>
