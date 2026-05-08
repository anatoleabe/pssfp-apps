<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<title>{{ $candidature->numero_dossier }} — vérification candidat</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #FAF8FC; color: #333; margin: 0; padding: 24px; }
  .container { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 18px rgba(107,47,160,0.08); }
  h1 { color: #6B2FA0; margin: 0 0 4px; font-size: 22px; }
  .num { color: #6B2FA0; font-weight: 700; letter-spacing: 0.5px; font-size: 18px; }
  .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; letter-spacing: 0.5px; text-transform: uppercase; margin-top: 8px; }
  .badge.postulant { background: #FFF8E1; color: #C9A227; }
  .badge.candidat { background: #EDE7F6; color: #6B2FA0; }
  .badge.accepte { background: #E8F5E9; color: #2E7D32; }
  .badge.refuse { background: #FFEBEE; color: #C62828; }
  table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  td { padding: 6px 0; border-bottom: 1px dashed #EDE7F6; vertical-align: top; }
  td.k { color: #6B2FA0; font-weight: 600; width: 45%; }
  .hash { margin-top: 16px; font-family: SFMono-Regular, Consolas, monospace; font-size: 11px; word-break: break-all; color: #777; }
  .footer { margin-top: 16px; font-size: 12px; color: #777; }
</style>
</head>
<body>
<div class="container">
  <h1>Programme PSSFP</h1>
  <div class="num">{{ $candidature->numero_dossier }}</div>
  <span class="badge {{ $candidature->statut }}">{{ strtoupper($candidature->statut) }}</span>

  <table>
    <tr><td class="k">Candidat</td><td>{{ $candidature->prenom }} {{ $candidature->nom }}</td></tr>
    @if($candidature->date_naissance)
      <tr><td class="k">Né(e) le</td><td>{{ $candidature->date_naissance->format('d/m/Y') }}</td></tr>
    @endif
    <tr><td class="k">Téléphone</td><td>{{ $maskedPhone }}</td></tr>
    @if($candidature->specialite)
      <tr><td class="k">Spécialité demandée</td><td>{{ $candidature->specialite }}</td></tr>
    @endif
    @if($candidature->submitted_at)
      <tr><td class="k">Dossier soumis le</td><td>{{ $candidature->submitted_at->format('d/m/Y') }}</td></tr>
    @endif
    <tr><td class="k">Frais payés</td><td>{{ $candidature->frais_paye ? 'Oui' : 'Non' }}</td></tr>
  </table>

  @if($candidature->recipisse_hash_sha256)
    <div class="hash">
      <strong>Empreinte SHA256 du récépissé :</strong><br>
      {{ $candidature->recipisse_hash_sha256 }}
    </div>
  @endif

  <p class="footer">
    Page de vérification rapide à l'usage des agents PSSFP au dépôt physique.
    Aucune donnée nominative n'est exposée au-delà du strict minimum.
  </p>
</div>
</body>
</html>
