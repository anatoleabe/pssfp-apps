{{-- En-tête d'un exemplaire de reçu A4 : $titre, $numero, $date, $copie, $logo. --}}
<table class="tete"><tr>
  <td class="logo">@if ($logo ?? null)<img src="{{ $logo }}" alt="PSSFP">@endif</td>
  <td class="ets"><strong>Programme Supérieur de Spécialisation en Finances Publiques</strong><br>Ministère des Finances · Campus de Messa, Yaoundé<br>Service administratif et financier · Tél. 222 23 37 06</td>
  <td class="droite"><h1>{{ $titre }}</h1><span class="num">N° {{ $numero }}</span><br><span style="font-size:8.2pt;color:#6B6B6B">{{ $date }}</span><br><span class="copie">{{ $copie }}</span></td>
</tr></table>
<div class="filet"></div>
