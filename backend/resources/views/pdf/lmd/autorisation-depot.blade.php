<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Attestation d'autorisation de dépôt {{ $numero }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Attestation d'autorisation de dépôt du mémoire</h1>
  <p class="sous-titre">N° {{ $numero }}</p>
  <p style="font-size:11pt;line-height:1.7;margin-top:8mm;">
    Le Chef de la scolarité du Programme Supérieur de Spécialisation en Finances Publiques atteste que
    <strong>{{ mb_strtoupper((string) $candidat?->nom) }} {{ $candidat?->prenom }}</strong>, matricule {{ $candidat?->matricule }},
    inscrit(e) en Master 2 professionnel en finances publiques{{ $dossier->inscriptionAnnuelle?->parcours ? ', option '.$dossier->inscriptionAnnuelle->parcours->libelle : '' }}
    pour l'année académique {{ $dossier->inscriptionAnnuelle?->annee_academique }}, est autorisé(e) à déposer son mémoire intitulé :
  </p>
  <p style="font-size:11.5pt;text-align:center;margin:4mm 10mm;"><strong>« {{ $dossier->theme }} »</strong></p>
  <p style="font-size:10.5pt;">Encadreur académique : {{ $dossier->encadreur_academique }}@if ($dossier->encadreur_professionnel) — encadreur professionnel : {{ $dossier->encadreur_professionnel }}@endif.</p>
  <p style="font-size:10.5pt;margin-top:4mm;">Conditions vérifiées :</p>
  <ul style="font-size:10pt;line-height:1.5;">
    @foreach ($conditions as $c)
      <li>{{ $c['libelle'] }}@if (! empty($c['detail']) && in_array($c['cle'] ?? '', ['avis', 'similitude'], true)) — {{ $c['detail'] }}@endif</li>
    @endforeach
  </ul>
  <p style="font-size:11pt;">En foi de quoi la présente attestation lui est délivrée pour servir et valoir ce que de droit.</p>
  <table class="signatures"><tr><td>Fait à Yaoundé, le {{ $edite_le->format('d/m/Y') }}</td><td style="text-align:right;">Le Chef de la scolarité</td></tr></table>
  @include('pdf.lmd._verification')
</body>
</html>
