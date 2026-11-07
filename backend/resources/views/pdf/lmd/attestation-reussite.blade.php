<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Attestation de réussite {{ $numero }}</title>
@include('pdf.lmd._entete')
</head>
<body>
  <h1>Attestation de réussite</h1>
  <p class="sous-titre"><em>Certificate of achievement</em></p>
  <p style="font-size:11pt;line-height:1.7;margin-top:10mm;">
    Le Chef de la scolarité du Programme Supérieur de Spécialisation en Finances Publiques atteste que
    <strong>{{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}</strong>, matricule {{ $dossier?->matricule }},
    @if ($resultat->decision === 'AS')
      a validé la partie écrite du <strong>Master 2 professionnel en finances publiques</strong>{{ $inscription->parcours ? ', option '.$inscription->parcours->libelle : '' }},
      au titre de l'année académique {{ $d->annee_academique }}, et est <strong>autorisé(e) à soutenir</strong> son mémoire.
    @else
      est <strong>admis(e)</strong> en {{ $inscription->niveau === 'M1' ? 'Master 1' : 'Master 2' }} professionnel en finances publiques{{ $inscription->parcours && $inscription->niveau === 'M2' ? ', option '.$inscription->parcours->libelle : '' }},
      au titre de l'année académique {{ $d->annee_academique }}, avec {{ $resultat->credits_acquis }} crédits{{ $resultat->mention ? ', mention '.$resultat->mention : '' }}.
    @endif
  </p>
  <p style="font-size:11pt;">En foi de quoi la présente attestation lui est délivrée pour servir et valoir ce que de droit.</p>
  <p style="font-size:8pt;color:#5C5C5C;">Délibération {{ $d->numero_pv }}.</p>
  <table class="signatures"><tr><td>Fait à Yaoundé, le {{ $edite_le->format('d/m/Y') }}</td><td style="text-align:right;">Le Chef de la scolarité</td></tr></table>
  @include('pdf.lmd._verification')
</body>
</html>
