{{-- Styles communs aux listes PDF des auditeurs (ListesAuditeurs). --}}
<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 10mm 12mm 16mm; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 8.5pt; color: #3C3C3C; margin: 0; }
  footer { position: fixed; bottom: -10mm; left: 0; right: 0; border-top: 0.5pt solid #B8913F; padding: 1.5mm 30mm 0 0; font-size: 7pt; color: #5F5F5F; }

  @include('pdf._entete-pssfp-styles')

  h1 { font-family: 'Source Serif 4', Georgia, serif; color: #4A2E67; font-size: 14pt; margin: 1mm 0 1.5mm; text-align: center; }
  .interne { text-align: center; margin: 0 0 2.5mm; }
  .interne span { border: 0.8pt solid #9B1C1C; color: #9B1C1C; font-size: 7pt; font-weight: 700; letter-spacing: 1pt; padding: 0.4mm 2mm; text-transform: uppercase; }

  table.criteres { width: 100%; border-collapse: collapse; background: #FAF7F2; border-left: 1.5mm solid #4A2E67; margin: 0 0 1mm; }
  table.criteres td { padding: 0.9mm 2.5mm; font-size: 8pt; vertical-align: top; width: 33%; }
  table.criteres .l { color: #5F5F5F; }
  .edition { text-align: right; font-size: 7.5pt; color: #5F5F5F; margin: 0 0 3mm; }

  table.liste { width: 100%; border-collapse: collapse; }
  table.liste thead { display: table-header-group; }
  table.liste tr { page-break-inside: avoid; }
  table.liste th, table.liste td { padding: 0.8mm 1.6mm; border-bottom: 0.5pt solid #E6E0EE; text-align: left; vertical-align: top; }
  table.liste th { background: #FAF7F2; color: #4A2E67; font-size: 7pt; text-transform: uppercase; letter-spacing: .3pt; }
  table.liste .n { text-align: right; white-space: nowrap; }
  table.liste .mat, table.liste .sit { white-space: nowrap; }
  table.liste tr.groupe td { background: #4A2E67; color: #FFFFFF; font-weight: 700; border-bottom: none; }
  table.liste tr.sous-total td { background: #F3EFF7; color: #3A2452; font-weight: 700; border-top: 0.8pt solid #A592BD; }
  table.liste tr.total td { background: #0F3A4A; color: #FFFFFF; font-weight: 700; border-bottom: none; }

  .note { margin-top: 3mm; font-size: 7.5pt; color: #5F5F5F; border-left: 2pt solid #D4AF6A; padding-left: 2mm; }
  .vide { margin-top: 8mm; text-align: center; color: #5F5F5F; font-size: 10pt; }
</style>
