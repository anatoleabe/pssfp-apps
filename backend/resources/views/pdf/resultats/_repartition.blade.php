{{--
  Tableau « spécialité × critère » du PV.
  @var \App\Services\ResultatsSelection\Repartition $repartition
  @var string $entete    libellé de la première colonne
  @var bool   $pourcentages  ligne « EN % DU TOTAL » (genre, mode)
--}}
<table class="grille">
  <thead>
    <tr>
      <th style="width: 8mm;">N°</th>
      <th>{{ $entete }}</th>
      @foreach ($repartition->colonnes as $libelle)
        <th>{{ $libelle }}</th>
      @endforeach
      @unless ($pourcentages)<th>TOTAL</th>@endunless
    </tr>
  </thead>
  <tbody>
    @foreach (array_keys($repartition->lignes) as $i => $specialite)
      <tr>
        <td class="num">{{ $i + 1 }}</td>
        <td>{{ $specialite }}</td>
        @foreach (array_keys($repartition->colonnes) as $col)
          <td class="num">{{ $repartition->valeur($specialite, $col) }}</td>
        @endforeach
        @unless ($pourcentages)<td class="num bold">{{ $repartition->totalLigne($specialite) }}</td>@endunless
      </tr>
    @endforeach
    <tr class="total">
      <td></td>
      <td>TOTAL</td>
      @foreach (array_keys($repartition->colonnes) as $col)
        <td class="num">{{ $repartition->totalColonne($col) }}</td>
      @endforeach
      @unless ($pourcentages)<td class="num">{{ $repartition->total() }}</td>@endunless
    </tr>
    @if ($pourcentages)
      <tr class="total">
        <td></td>
        <td>EN % DU TOTAL</td>
        @foreach (array_keys($repartition->colonnes) as $col)
          <td class="num">{{ $repartition->pourcentage($col) }}</td>
        @endforeach
      </tr>
    @endif
    <tr class="total">
      <td></td>
      <td>{{ $pourcentages ? 'TOTAL GENERAL' : 'TOTAL DEPOT PHYSIQUE DES DOSSIERS' }}</td>
      <td class="num" colspan="{{ count($repartition->colonnes) + ($pourcentages ? 0 : 1) }}">{{ $repartition->total() }}</td>
    </tr>
  </tbody>
</table>
