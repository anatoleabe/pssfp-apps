{{-- En-tête officiel bilingue du PSSFP, logo au centre ($logo : data URI ou null). Styles : pdf._entete-pssfp-styles. Partagé par la lettre d'accès FOAD et les listes de la scolarité : tout changement vaut pour tous. --}}<table class="entete">
  <tr>
    <td>
      REPUBLIQUE DU CAMEROUN<br><span class="devise">Paix- Travail- Patrie</span><br><span class="sep">--------------</span><br>
      MINISTERE DES FINANCES<br><span class="sep">--------------</span><br>SECRETARIAT GENERAL<br><span class="sep">--------------</span><br>
      PROGRAMME SUPERIEUR DE SPECIALISATION EN FINANCES PUBLIQUES
    </td>
    <td class="logo">@if ($logo)<img src="{{ $logo }}" alt="PSSFP">@endif</td>
    <td>
      REPUBLIC OF CAMEROON<br><span class="devise">Peace- Work- Fatherland</span><br><span class="sep">--------------</span><br>
      MINISTRY OF FINANCE<br><span class="sep">--------------</span><br>GENERAL SECRETARIAT<br><span class="sep">--------------</span><br>
      ADVANCED PROGRAM OF SPECIALISATION IN PUBLIC FINANCE
    </td>
  </tr>
</table>
