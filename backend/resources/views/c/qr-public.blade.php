@php
    // Libellés lisibles plutôt que les codes internes (ACCEPTE, REFUSE…).
    $statut = $candidature->withdrawn_at !== null ? 'retire' : $candidature->statut;
    $libelleStatut = [
        'postulant' => 'Dossier non soumis',
        'candidat' => 'Dossier soumis',
        'accepte' => 'Retenu(e)',
        'refuse' => 'Non retenu(e)',
        'retire' => 'Candidature retirée',
    ][$statut] ?? $statut;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $candidature->numero_dossier }} — vérification candidat · PSSFP</title>
<style>
  :root {
    --prune: #4A2E67; --prune-profonde: #3A2452; --lavande: #A592BD; --lavande-pale: #F4EFFA;
    --petrole: #0F3A4A; --or: #D4AF6A; --ivoire: #FAF7F2; --graphite: #3C3C3C; --gris: #5C5C5C;
  }
  * { box-sizing: border-box; }
  body { font-family: "Source Sans 3", -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--ivoire); color: var(--graphite); margin: 0; padding: 32px 16px; line-height: 1.5; }
  .carte { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(74,46,103,0.10); border: 1px solid #EEE8F4; }
  .bandeau { background: var(--petrole); color: #fff; font-size: 12px; letter-spacing: 0.04em; text-align: center; padding: 8px 16px; }
  .entete { display: flex; align-items: center; gap: 16px; padding: 24px 28px 20px; border-bottom: 3px solid var(--or); }
  .entete img { width: 72px; height: auto; flex-shrink: 0; }
  .entete .sigle { font-family: "Cormorant Garamond", Georgia, serif; font-size: 26px; font-weight: 700; color: var(--prune); line-height: 1; margin: 0; }
  .entete .nom { margin: 4px 0 0; font-size: 14px; color: var(--gris); }
  .corps { padding: 24px 28px 28px; }
  .surtitre { font-size: 11px; letter-spacing: 0.16em; text-transform: uppercase; color: var(--prune); margin: 0; }
  .ligne-num { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 6px; }
  .num { font-family: SFMono-Regular, Consolas, monospace; font-size: 22px; font-weight: 700; color: #1A1A1A; letter-spacing: 0.02em; }
  .badge { display: inline-block; padding: 5px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; border: 1px solid transparent; }
  .badge.postulant { background: #FFF8E6; color: #7A5A00; border-color: #F1DFA8; }
  .badge.candidat { background: var(--lavande-pale); color: var(--prune-profonde); border-color: #DCD0EA; }
  .badge.accepte { background: #E7F4EC; color: #1E6B3A; border-color: #B9DEC6; }
  .badge.refuse { background: var(--lavande-pale); color: var(--prune-profonde); border-color: var(--lavande); }
  .badge.retire { background: #F1F1F1; color: #444; border-color: #DDD; }
  dl { margin: 20px 0 0; }
  .champ { display: grid; grid-template-columns: 42% 1fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid #EFEAF5; }
  dt { color: var(--gris); font-size: 14px; }
  dd { margin: 0; font-weight: 600; color: #1A1A1A; }
  .empreinte { margin-top: 20px; padding: 12px 14px; background: var(--ivoire); border-radius: 10px; font-size: 12px; color: var(--gris); }
  .empreinte code { display: block; margin-top: 4px; font-family: SFMono-Regular, Consolas, monospace; font-size: 11px; word-break: break-all; color: var(--graphite); }
  .pied { margin: 20px 0 0; font-size: 12px; color: var(--gris); }
  @media (max-width: 420px) {
    .champ { grid-template-columns: 1fr; gap: 2px; }
    .entete { padding: 20px; }
    .corps { padding: 20px; }
  }
</style>
</head>
<body>
<main class="carte">
  <div class="bandeau">République du Cameroun — Ministère des Finances</div>
  <header class="entete">
    <img src="{{ asset('images/pssfp-logo.png') }}" alt="Logo du PSSFP" width="72" height="57">
    <div>
      <p class="sigle">PSSFP</p>
      <p class="nom">Programme Supérieur de Spécialisation en Finances Publiques</p>
    </div>
  </header>

  <section class="corps" aria-labelledby="titre-dossier">
    <p class="surtitre">Vérification d'un dossier de candidature</p>
    <div class="ligne-num">
      <h1 id="titre-dossier" class="num">{{ $candidature->numero_dossier }}</h1>
      <span class="badge {{ $statut }}">{{ $libelleStatut }}</span>
    </div>

    <dl>
      <div class="champ"><dt>Candidat</dt><dd>{{ $candidature->prenom }} {{ $candidature->nom }}</dd></div>
      @if($candidature->date_naissance)
        <div class="champ"><dt>Né(e) le</dt><dd>{{ $candidature->date_naissance->format('d/m/Y') }}</dd></div>
      @endif
      <div class="champ"><dt>Téléphone</dt><dd>{{ $maskedPhone }}</dd></div>
      @if($candidature->specialite)
        <div class="champ">
          <dt>{{ $statut === 'accepte' ? 'Spécialité retenue' : 'Spécialité demandée' }}</dt>
          <dd>{{ $candidature->specialite }}</dd>
        </div>
      @endif
      @if($candidature->submitted_at)
        <div class="champ"><dt>Dossier soumis le</dt><dd>{{ $candidature->submitted_at->format('d/m/Y') }}</dd></div>
      @endif
      @if($candidature->decided_at)
        <div class="champ"><dt>Décision du</dt><dd>{{ $candidature->decided_at->format('d/m/Y') }}</dd></div>
      @endif
      <div class="champ"><dt>Frais de dossier</dt><dd>{{ $candidature->frais_paye ? 'Payés' : 'Non payés' }}</dd></div>
    </dl>

    @if($candidature->recipisse_hash_sha256)
      <div class="empreinte">
        Empreinte SHA-256 du récépissé
        <code>{{ $candidature->recipisse_hash_sha256 }}</code>
      </div>
    @endif

    <p class="pied">
      Page de vérification à l'usage des agents du PSSFP lors du dépôt physique.
      Seules les informations strictement nécessaires y figurent.
    </p>
  </section>
</main>
</body>
</html>
