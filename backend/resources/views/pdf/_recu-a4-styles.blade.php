<style>
  @font-face { font-family: 'Source Sans 3'; font-weight: 400; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Regular.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Sans 3'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSans3-Bold.ttf') }}") format('truetype'); }
  @font-face { font-family: 'Source Serif 4'; font-weight: 700; src: url("file://{{ resource_path('fonts/pdf/SourceSerif4-Bold.ttf') }}") format('truetype'); }
  @page { margin: 6mm 11mm 4mm; }
  body { font-family: 'Source Sans 3', DejaVu Sans, sans-serif; font-size: 8.6pt; color: #3C3C3C; margin: 0; }
  .exemplaire { height: 138mm; overflow: hidden; }
  table { width: 100%; border-collapse: collapse; }
  .tete td { vertical-align: middle; padding: 0; }
  .tete .logo { width: 30mm; } .tete .logo img { height: 11mm; }
  .tete .ets { font-size: 8pt; line-height: 1.25; color: #3C3C3C; }
  .tete .ets strong { color: #4A2E67; font-size: 9pt; }
  .tete .droite { text-align: right; width: 62mm; }
  h1 { font-family: 'Source Serif 4', Georgia, serif; color: #4A2E67; font-size: 12.5pt; margin: 0; letter-spacing: 0.3pt; }
  .num { font-size: 10.5pt; font-weight: 700; color: #0F3A4A; }
  .copie { display: inline-block; margin-top: 1mm; padding: 0.4mm 2mm; border: 1px solid #0F3A4A; color: #0F3A4A; font-size: 7.2pt; font-weight: 700; letter-spacing: 0.6pt; }
  .filet { border-bottom: 2px solid #4A2E67; margin: 1mm 0 1.5mm; }
  .annule { margin: 0 0 1mm; padding: 0.6mm 3mm; border: 2px solid #9B1C1C; color: #9B1C1C; font-weight: 700; text-align: center; letter-spacing: 0.6pt; font-size: 8.4pt; }
  .mention { margin: 0 0 1.5mm; padding: 0.8mm 2.5mm; background: #F4EFFA; color: #4A2E67; font-size: 8pt; }
  .ident td { padding: 0.3mm 0; vertical-align: top; }
  .ident .l { color: #6B6B6B; width: 25mm; }
  .ident .v { font-weight: 700; }
  .ident .l2 { color: #6B6B6B; width: 27mm; padding-left: 4mm; }
  .vent { margin-top: 1.5mm; font-size: 8.2pt; }
  .vent th { background: #4A2E67; color: #FFFFFF; font-size: 7.4pt; text-align: left; padding: 0.7mm 1.5mm; }
  .vent td { padding: 0.3mm 1.5mm; border-bottom: 1px solid #E6E0EE; }
  .vent .n { text-align: right; white-space: nowrap; }
  .vent .code { font-size: 7.6pt; color: #0F3A4A; } .vent .code i { color: #A0A0A0; }
  .vent tr.nul td { color: #9A9A9A; }
  .vent tr.tot td { font-weight: 700; border-top: 1.5px solid #4A2E67; border-bottom: none; background: #FAF7F2; }
  .vent .ce { background: #F4EFE3; font-weight: 700; color: #4A2E67; }
  .lettres { padding: 1mm 2.5mm; background: #FAF7F2; border-left: 3px solid #D4AF6A; }
  .lettres strong { color: #4A2E67; font-size: 10pt; }
  .sig { margin-top: 1mm; }
  .sig td { vertical-align: top; font-size: 7.6pt; color: #6B6B6B; }
  .sig .qr { width: 22mm; } .sig .qr img { width: 20mm; height: 20mm; }
  .sig .verif { width: 70mm; padding-right: 4mm; }
  .sig .verif span { color: #0F3A4A; }
  .sig .cadre { border: 1px dashed #A592BD; height: 9mm; margin-top: 0.8mm; }
  .pied { margin-top: 1mm; font-size: 6.8pt; color: #7A7A7A; border-top: 1px solid #E6E0EE; padding-top: 0.8mm; }
  .coupe { height: 6mm; text-align: center; font-size: 7pt; color: #9A9A9A; border-top: 1px dashed #9A9A9A; margin-top: 1mm; padding-top: 0.4mm; }
</style>
