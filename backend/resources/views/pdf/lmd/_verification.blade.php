<table style="margin-top:8mm;">
  <tr>
    <td style="border:none;width:32mm;vertical-align:top;"><img src="{{ $qr }}" alt="" style="width:28mm;height:28mm;"></td>
    <td style="border:none;vertical-align:top;font-size:8pt;color:#5C5C5C;">
      Document n° <strong>{{ $numero }}</strong>, édité le {{ $edite_le->format('d/m/Y') }}.<br>
      Vérifiez son authenticité en scannant le code ou sur {{ $url }}<br>
      (code de vérification : {{ $code }}). Ce document est révocable ; il n'est jamais modifié.<br>
      <em>Document number {{ $numero }}. Check its authenticity by scanning the code.</em>
    </td>
  </tr>
</table>
