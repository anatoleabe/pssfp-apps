{{-- En-tête et styles des documents administratifs signés du Président du Comité de Pilotage (spec lot 26). --}}
@include('pdf.lmd._entete', ['banniere' => \App\Support\Pdf\Banniere::PSSFP])
<style>
  .corps { font-size: 12pt; line-height: 1.9; text-align: justify; text-indent: 12mm; margin: 0 0 3mm; }
  h1.acte { font-size: 19pt; text-decoration: underline; margin: 16mm 0 12mm; color: #3C3C3C; }
  .reference td { border: none; padding: 5mm 0 0; font-style: italic; font-weight: 700; font-size: 10pt; }
  .signataire { text-align: right; font-family: 'Source Serif 4', Georgia, serif; font-weight: 700; font-size: 12pt; text-decoration: underline; margin: 8mm 0 30mm; }
</style>
