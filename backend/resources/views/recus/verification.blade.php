<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Vérification d'un reçu — PSSFP</title>
<style>
  body { margin: 0; background: #FAF7F2; color: #3C3C3C; font-family: 'Source Sans 3', system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.5; }
  main { max-width: 34rem; margin: 2.5rem auto; padding: 0 1rem; }
  .carte { background: #FFFFFF; border-radius: 0.75rem; border: 1px solid #E6E0EE; padding: 1.5rem; }
  h1 { font-family: Georgia, serif; color: #4A2E67; font-size: 1.4rem; margin: 0 0 0.25rem; }
  .sous { color: #5C5C5C; margin: 0 0 1.25rem; font-size: 0.95rem; }
  .etat { display: inline-block; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 0.4rem; margin-bottom: 1rem; }
  .valide { background: #E7F3EC; color: #1E5631; }
  .annule { background: #FBEAEA; color: #8B1A1A; }
  dl { display: grid; grid-template-columns: 9rem 1fr; gap: 0.4rem 1rem; margin: 0; }
  dt { color: #5C5C5C; }
  dd { margin: 0; font-weight: 600; }
  .note { font-size: 0.85rem; color: #5C5C5C; margin-top: 1.25rem; }
</style>
</head>
<body>
<main>
  <div class="carte">
    <h1>Vérification d'un reçu</h1>
    <p class="sous">Programme Supérieur de Spécialisation en Finances Publiques — service administratif et financier</p>
    @if ($fiche === null)
      <p class="etat annule" role="status">Aucun reçu ne correspond à ce lien.</p>
      <p class="note">Vérifiez le QR code du reçu, ou adressez-vous au service financier du PSSFP.</p>
    @else
      @if ($fiche['valide'])
        <p class="etat valide" role="status">Reçu valide</p>
      @else
        <p class="etat annule" role="status">Reçu annulé le {{ $fiche['annule_le'] }}@if ($fiche['remplace_par']), remplacé par {{ $fiche['remplace_par'] }}@endif</p>
      @endif
      <dl>
        <dt>Numéro</dt><dd>{{ $fiche['numero'] }}</dd>
        <dt>Émis le</dt><dd>{{ $fiche['emis_le'] }}</dd>
        <dt>Montant</dt><dd>{{ $fiche['montant'] }}</dd>
        <dt>Année académique</dt><dd>{{ $fiche['annee'] }}</dd>
        <dt>Titulaire</dt><dd>{{ $fiche['titulaire'] }}</dd>
        <dt>Matricule</dt><dd>{{ $fiche['matricule'] }}</dd>
      </dl>
      <p class="note">Cette page confirme l'existence et l'état du reçu. Les données personnelles sont masquées.</p>
    @endif
  </div>
</main>
</body>
</html>
