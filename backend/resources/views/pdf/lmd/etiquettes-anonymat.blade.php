<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Étiquettes {{ $e->libelle() }}</title>
<style>
  @page { margin: 8mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #1A1A1A; }
  table { width: 100%; border-collapse: collapse; }
  td { width: 50%; height: 44mm; border: 1px dashed #A592BD; padding: 3mm; vertical-align: top; }
  .numero { font-family: DejaVu Sans Mono, monospace; font-size: 16pt; font-weight: bold; letter-spacing: 1pt; }
  .talon { margin-top: 3mm; padding-top: 2mm; border-top: 1px dotted #5C5C5C; font-size: 7.5pt; color: #3C3C3C; }
</style>
</head>
<body>
  <table>
    @foreach ($etiquettes->chunk(2) as $ligne)
      <tr>
        @foreach ($ligne as $et)
          <td>
            <img src="{{ $et['qr'] }}" alt="" style="float:right;width:20mm;height:20mm;">
            <div>{{ $e->libelle() }}</div>
            <div class="numero">{{ $et['numero'] }}</div>
            <div class="talon">Talon à rabattre et coller · {{ $et['matricule'] }} — {{ $et['nom'] }}</div>
          </td>
        @endforeach
        @if ($ligne->count() === 1)<td style="border:none;"></td>@endif
      </tr>
    @endforeach
  </table>
</body>
</html>
