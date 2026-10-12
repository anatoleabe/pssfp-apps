{{-- Styles communs au communiqué des résultats et au PV du jury (dompdf). --}}
<style>
  @font-face { font-family: 'Source Serif 4'; font-style: normal; font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-style: normal; font-weight: 700;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-style: italic; font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Italic.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-style: italic; font-weight: 700;
    src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-BoldItalic.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-style: normal; font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-style: italic; font-weight: 400;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Italic.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-style: normal; font-weight: 700;
    src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }

  @page { margin: 14mm 18mm 26mm; }
  body { font-family: 'Source Serif 4', DejaVu Serif, serif; font-size: 11pt; color: #1f1f1f; line-height: 1.32; margin: 0; }
  p { margin: 0 0 7pt; text-align: justify; }
  .center { text-align: center; }
  .right { text-align: right; }
  .italic { font-style: italic; }
  .bold { font-weight: 700; }
  .sans { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; }
  .souligne { text-decoration: underline; }

  footer { position: fixed; bottom: -19mm; left: 0; right: 0; text-align: center;
    font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 7.5pt; color: #444; line-height: 1.3;
    border-top: 0.6pt solid #888; padding-top: 3pt; }
  footer .web { font-weight: 700; }

  table.grille { width: 100%; border-collapse: collapse; font-family: 'Source Sans 3', DejaVu Sans, sans-serif;
    font-size: 9.5pt; line-height: 1.12; margin: 4pt 0 8pt; page-break-inside: auto; }
  table.grille th, table.grille td { border: 0.6pt solid #333; padding: 1.6pt 5pt; vertical-align: middle; }
  table.grille thead th { background: #e6e6e6; font-weight: 700; text-align: center; }
  table.grille thead { display: table-header-group; }
  table.grille tr { page-break-inside: avoid; }
  table.grille td.num, table.grille td.code { text-align: center; }
  table.grille td.n { width: 9mm; }
  table.grille tr.total td { font-weight: 700; background: #f2f2f2; }

  .legende { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 9pt; font-style: italic; margin: 0 0 0 60mm; }
  .fait-a { text-align: right; margin: 14pt 0 10pt; }

  .bloc-signatures { page-break-inside: avoid; }
  table.signatures { width: 100%; border-collapse: collapse; page-break-inside: avoid; }
  table.signatures td { width: 50%; vertical-align: top; text-align: center; padding: 0 2mm; font-size: 10.5pt; }
  table.signatures .espace { height: 24mm; }
  .neant { font-style: italic; margin: 2pt 0 8pt 8mm; }
</style>
