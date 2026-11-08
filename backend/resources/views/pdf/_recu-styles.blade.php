<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 8mm 12mm 7mm; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 9.5pt; color: #3C3C3C; }
  h1 { font-family: 'Source Serif 4', Georgia, serif; color: #4A2E67; font-size: 15pt; margin: 0; }
  .entete { border-bottom: 2px solid #4A2E67; padding-bottom: 2mm; margin-bottom: 4mm; }
  .entete td { border: none; padding: 0; vertical-align: bottom; }
  .entete p { margin: 0; color: #5C5C5C; font-size: 8.5pt; }
  .numero { text-align: right; font-size: 11pt; font-weight: 700; color: #0F3A4A; }
  table { width: 100%; border-collapse: collapse; }
  .ident td { padding: 0.8mm 0; border-bottom: 1px solid #F0ECF5; }
  .ident td.l { color: #5C5C5C; width: 42mm; }
  .montant { margin: 3mm 0 1.5mm; padding: 2.5mm 3mm; background: #FAF7F2; border-left: 3px solid #D4AF6A; }
  .montant strong { font-size: 13pt; color: #4A2E67; }
  .pied { margin-top: 3mm; font-size: 8.5pt; color: #5C5C5C; }
  .annule { margin: 0 0 2mm; padding: 1.5mm 3mm; border: 2px solid #9B1C1C; color: #9B1C1C; font-weight: 700; text-align: center; letter-spacing: 1pt; }
</style>
