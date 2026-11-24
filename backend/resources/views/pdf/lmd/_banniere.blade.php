{{-- Bannière des documents du LMD. $banniere : « universite » (défaut, documents académiques) ou « pssfp » (documents administratifs). --}}
@php($universite = ($banniere ?? \App\Support\Pdf\Banniere::UNIVERSITE) === \App\Support\Pdf\Banniere::UNIVERSITE)
@php($logoBanniere = $universite ? \App\Support\Pdf\Banniere::logoUniversite() : null)
@php($entetePssfp = $universite ? null : \App\Support\Pdf\Banniere::entetePssfp())
@if ($entetePssfp)
<img src="{{ $entetePssfp }}" alt="RÉPUBLIQUE DU CAMEROUN – MINISTÈRE DES FINANCES – SECRÉTARIAT GÉNÉRAL – PROGRAMME SUPÉRIEUR DE SPÉCIALISATION EN FINANCES PUBLIQUES" style="width:100%;">
@else
<table style="width:100%;border-collapse:collapse;">
  <tr>
    <td style="border:none;padding:0;width:38%;vertical-align:top;text-align:center;font-size:7.5pt;line-height:1.05;">RÉPUBLIQUE DU CAMEROUN<br>Paix – Travail – Patrie<br>
      @if ($universite)UNIVERSITÉ DE YAOUNDÉ II-SOA<br>FACULTÉ DES SCIENCES ÉCONOMIQUES ET DE GESTION<br>@else MINISTÈRE DES FINANCES<br>@endif
      <strong>PROGRAMME SUPÉRIEUR DE SPÉCIALISATION<br>EN FINANCES PUBLIQUES</strong></td>
    <td style="border:none;padding:0;width:24%;vertical-align:middle;text-align:center;">@if ($logoBanniere)<img src="{{ $logoBanniere }}" alt="" style="height:18mm;">@endif</td>
    <td style="border:none;padding:0;width:38%;vertical-align:top;text-align:center;font-size:7.5pt;line-height:1.05;">REPUBLIC OF CAMEROON<br>Peace – Work – Fatherland<br>
      @if ($universite)UNIVERSITY OF YAOUNDE II-SOA<br>FACULTY OF ECONOMICS AND MANAGEMENT<br>@else MINISTRY OF FINANCE<br>@endif
      <strong>ADVANCED PROGRAM OF SPECIALISATION<br>IN PUBLIC FINANCE</strong></td>
  </tr>
</table>
@endif
<div style="border-top:1.5px solid #4A2E67;margin:2mm 0 0;"></div>
