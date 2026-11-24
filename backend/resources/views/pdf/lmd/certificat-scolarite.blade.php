<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Certificat de scolarité {{ $numero }}</title>
@include('pdf.lmd._administratif')
</head>
<body>
  @php
    $e = match ($dossier?->genre) { 'F' => 'e', 'M' => '', default => '(e)' };
    $date = $dossier?->date_naissance?->locale('fr')->translatedFormat('j F Y');
    $lieu = $dossier?->lieu_naissance ? ' à <strong>'.e(mb_strtoupper((string) $dossier->lieu_naissance)).'</strong>' : '';
    $naissance = $date ? ', né'.$e.' le <strong>'.e($date).'</strong>'.$lieu : '';
  @endphp
  <h1 class="acte">CERTIFICAT DE SCOLARITÉ</h1>
  <p class="corps">
    Je soussigné, le Président du Comité de Pilotage du <strong>Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)</strong>
    du Ministère des Finances, certifie que l'étudiant{{ $e }} :
    <strong>{{ mb_strtoupper((string) $dossier?->nom) }} {{ $dossier?->prenom }}</strong>, matricule <strong>{{ $dossier?->matricule }}</strong>{!! $naissance !!},
    est régulièrement inscrit{{ $e }} au <strong>Programme Supérieur de Spécialisation en Finances Publiques (PSSFP)</strong>,
    au titre de l'année académique <strong>{{ $inscription->annee_academique }}</strong>, cycle <strong>MASTER EN FINANCES PUBLIQUES</strong>,
    niveau {{ $inscription->niveau === 'M1' ? '1' : '2' }}{{ $inscription->parcours && $inscription->niveau === 'M2' ? ', option '.$inscription->parcours->libelle : '' }}.
  </p>
  <p class="corps">En foi de quoi le présent certificat de scolarité lui est délivré pour servir et valoir ce que de droit. /-</p>
  <p class="signataire">Le Président du Comité de Pilotage</p>
  @include('pdf.lmd._verification')
</body>
</html>
