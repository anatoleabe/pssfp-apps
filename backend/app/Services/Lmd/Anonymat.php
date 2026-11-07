<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\CopieAnonyme;
use App\Models\EpreuveAnonyme;
use App\Models\EtapeSaisie;
use App\Models\InscriptionPedagogique;
use App\Models\MaquetteEcue;
use App\Models\NoteEcue;
use App\Models\RegleEvaluation;
use App\Models\SaisieAnonyme;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Anonymat des copies (spec lot 9 ; D36 ; RG-045 à RG-052).
 *
 * La phrase secrète du gestionnaire n'est jamais stockée : la clé AES-256 en est dérivée
 * (PBKDF2-SHA256), seuls le sel et un vérificateur sont conservés. Sans la phrase, aucune
 * copie ne se relie à un auditeur.
 */
final class Anonymat
{
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PHRASE_MIN = 12;

    private const ITERATIONS = 200000;

    /** Seconde correction pour les notes de la zone 10 à 12 (exclu) ; troisième au-delà de 3 points d'écart (V-10 à V-12). */
    private const ZONE_MIN = 10.0;

    private const ZONE_MAX = 12.0;

    private const SEUIL_TROISIEME = 3.0;

    public function __construct(private readonly SaisieNotes $saisie) {}

    public function creer(MaquetteEcue $ecue, string $session, User $gestionnaire): EpreuveAnonyme
    {
        $this->gestionnaire($gestionnaire);
        $etape = $this->saisie->etape($ecue, $session, EtapeSaisie::EXAMEN);
        if ($etape->statut === EtapeSaisie::VERROUILLEE) {
            throw new InvalidArgumentException('L\'examen de cet ECUE est déjà verrouillé.');
        }
        if (NoteEcue::query()->where('maquette_ecue_id', $ecue->id)->where('session', $session)->whereNotNull('examen')->exists()) {
            throw new InvalidArgumentException('Des notes d\'examen ont déjà été saisies en clair pour cet ECUE.');
        }

        $epreuve = EpreuveAnonyme::query()->firstOrCreate(['maquette_ecue_id' => $ecue->id, 'session' => $session]);
        $epreuve->forceFill(['gestionnaire_id' => $gestionnaire->id])->save();

        return $epreuve;
    }

    /** Attribution des numéros (RG-045, RG-046) : un par candidat attendu, ordre aléatoire, correspondance chiffrée. */
    public function anonymiser(EpreuveAnonyme $epreuve, string $phrase, User $gestionnaire): int
    {
        $this->gestionnaire($gestionnaire);
        if (mb_strlen($phrase) < self::PHRASE_MIN) {
            throw new InvalidArgumentException('Clé d\'anonymat absente ou trop courte ('.self::PHRASE_MIN.' caractères au moins).');
        }

        return DB::transaction(function () use ($epreuve, $phrase, $gestionnaire): int {
            $epreuve = EpreuveAnonyme::query()->lockForUpdate()->with('ecue')->findOrFail($epreuve->id);
            if ($epreuve->statut !== 'preparation') {
                throw new InvalidArgumentException('Les numéros de cette épreuve sont déjà attribués.');
            }
            $candidats = $this->candidats($epreuve);
            if ($candidats->isEmpty()) {
                throw new InvalidArgumentException('Aucun candidat attendu pour cette épreuve.');
            }
            $sel = base64_encode(random_bytes(24));
            $cle = $this->cle($phrase, $sel);
            $numeros = [];
            while (count($numeros) < $candidats->count()) {
                $numeros[self::numero()] = true;
            }
            $ordres = range(1, $candidats->count());
            shuffle($ordres);
            foreach ($candidats->shuffle()->values() as $i => $ip) {
                CopieAnonyme::query()->create([
                    'epreuve_anonyme_id' => $epreuve->id, 'numero' => array_keys($numeros)[$i], 'ordre' => $ordres[$i],
                    'correspondance' => $this->chiffrer((string) $ip->id, $cle),
                ]);
            }
            $epreuve->forceFill(['statut' => 'anonymisee', 'sel' => $sel, 'verificateur' => hash_hmac('sha256', 'verificateur', $cle), 'anonymisee_at' => now()])->save();
            $this->journal($epreuve, $gestionnaire, 'anonymat_attribue', 'Numéros d\'anonymat attribués', ['copies' => $candidats->count()]);

            return $candidats->count();
        });
    }

    /**
     * Correspondance numéro → auditeur, avec la clé ; chaque lecture est tracée (RG-046).
     *
     * @return array<string, InscriptionPedagogique> par numéro
     */
    public function correspondance(EpreuveAnonyme $epreuve, string $phrase, User $gestionnaire, string $usage): array
    {
        $this->gestionnaire($gestionnaire);
        $cle = $this->cleVerifiee($epreuve, $phrase);
        $ids = [];
        foreach ($epreuve->copies()->get() as $copie) {
            $ids[$copie->numero] = (int) $this->dechiffrer($copie->correspondance, $cle);
        }
        $ips = InscriptionPedagogique::query()->whereIn('id', array_values($ids))->with('inscriptionAnnuelle.auditeur.candidature')->get()->keyBy('id');
        $this->journal($epreuve, $gestionnaire, 'correspondance_consultee', 'Correspondance des anonymats consultée', ['usage' => $usage]);

        return array_map(fn (int $id): InscriptionPedagogique => $ips[$id], $ids);
    }

    /** Constat d'une copie (RG-050). « Égarée traitée » et « écartée » exigent une décision motivée. */
    public function constater(EpreuveAnonyme $epreuve, string $numero, string $constat, ?string $motif, User $gestionnaire): void
    {
        $this->gestionnaire($gestionnaire);
        if (! in_array($epreuve->refresh()->statut, ['anonymisee'], true)) {
            throw new InvalidArgumentException('Les constats se font sur une épreuve anonymisée, avant son verrouillage.');
        }
        if (! array_key_exists($constat, CopieAnonyme::CONSTATS) || $constat === 'attribuee') {
            throw new InvalidArgumentException('Constat inconnu.');
        }
        if (in_array($constat, ['egaree_traitee', 'ecartee', 'fraude'], true) && mb_strlen(trim((string) $motif)) < 10) {
            throw new InvalidArgumentException('Ce constat exige une décision motivée (10 caractères au moins).');
        }
        $copie = $epreuve->copies()->where('numero', strtoupper(trim($numero)))->first() ?? throw new InvalidArgumentException('Numéro inconnu pour cette épreuve.');
        if ($constat !== 'presente' && $copie->saisies()->exists()) {
            throw new InvalidArgumentException('Des notes sont déjà saisies sur cette copie.');
        }
        $copie->forceFill(['constat' => $constat, 'constat_motif' => $motif === null ? null : mb_substr(trim($motif), 0, 500)])->save();
    }

    /**
     * Saisie d'une note sur un numéro (RG-048) : deux opérateurs différents par correction ;
     * en cas d'écart, un troisième tranche. L'opérateur ne voit que des numéros.
     *
     * @return array{saisie: int, ecart: bool}
     */
    public function saisir(EpreuveAnonyme $epreuve, string $numero, int $correction, string $note, User $operateur): array
    {
        abort_unless($operateur->can(PermissionsLmd::ANONYMAT_SAISIR), 403);
        if ($operateur->can(PermissionsLmd::ANONYMAT_GERER)) {
            throw new InvalidArgumentException('Le gestionnaire des anonymats ne saisit pas de notes (séparation des tâches).');
        }
        $texte = str_replace(',', '.', trim($note));
        if (! is_numeric($texte) || (float) $texte < 0 || (float) $texte > 20) {
            throw new InvalidArgumentException('La note doit être un nombre entre 0 et 20.');
        }

        return DB::transaction(function () use ($epreuve, $numero, $correction, $texte, $operateur): array {
            $epreuve = EpreuveAnonyme::query()->lockForUpdate()->findOrFail($epreuve->id);
            if ($epreuve->statut !== 'anonymisee') {
                throw new InvalidArgumentException('Saisie fermée : l\'épreuve n\'est pas en cours de correction.');
            }
            $copie = $epreuve->copies()->where('numero', strtoupper(trim($numero)))->first() ?? throw new InvalidArgumentException('Numéro inconnu ou mal formé.');
            if ($copie->constat !== 'presente') {
                throw new InvalidArgumentException('Seule une copie constatée présente reçoit une note.');
            }
            $attendues = $this->correctionsAttendues($copie);
            if (! in_array($correction, $attendues, true)) {
                throw new InvalidArgumentException("La correction {$correction} n'est pas attendue pour cette copie.");
            }
            $existantes = SaisieAnonyme::query()->where('copie_anonyme_id', $copie->id)->where('correction', $correction)->orderBy('saisie')->get();
            if ($existantes->contains(fn (SaisieAnonyme $s): bool => $s->operateur_id === $operateur->id)) {
                throw new InvalidArgumentException('Double saisie : la saisie suivante doit être faite par un autre opérateur.');
            }
            $rang = match (true) {
                $existantes->isEmpty() => 1,
                $existantes->count() === 1 => 2,
                $existantes->count() === 2 && (float) $existantes[0]->note !== (float) $existantes[1]->note => 3,
                default => throw new InvalidArgumentException('Cette correction est déjà saisie deux fois, sans écart.'),
            };
            SaisieAnonyme::query()->create(['copie_anonyme_id' => $copie->id, 'correction' => $correction, 'saisie' => $rang,
                'note' => number_format((float) $texte, 2, '.', ''), 'operateur_id' => $operateur->id]);
            $ecart = $rang === 2 && (float) $existantes[0]->note !== (float) $texte;

            return ['saisie' => $rang, 'ecart' => $ecart];
        });
    }

    /**
     * Note retenue d'une correction : deux saisies concordantes, ou la troisième ; null sinon.
     */
    public function noteCorrection(CopieAnonyme $copie, int $correction): ?float
    {
        $s = $copie->saisies->where('correction', $correction)->keyBy('saisie');
        if ($s->has(3)) {
            return (float) $s[3]->note;
        }
        if ($s->has(1) && $s->has(2) && (float) $s[1]->note === (float) $s[2]->note) {
            return (float) $s[1]->note;
        }

        return null;
    }

    /** @return list<int> corrections attendues pour une copie, selon les corrections déjà arrêtées */
    public function correctionsAttendues(CopieAnonyme $copie): array
    {
        $copie->loadMissing('saisies');
        $c1 = $this->noteCorrection($copie, 1);
        $attendues = [1];
        if ($c1 !== null && $c1 >= self::ZONE_MIN && $c1 < self::ZONE_MAX) {
            $attendues[] = 2;
            $c2 = $this->noteCorrection($copie, 2);
            if ($c2 !== null && abs($c1 - $c2) > self::SEUIL_TROISIEME) {
                $attendues[] = 3;
            }
        }

        return $attendues;
    }

    /** Note finale d'une copie : 3e correction, sinon moyenne des deux, sinon la première ; blanche = 0 ; sinon null. */
    public function noteFinale(CopieAnonyme $copie): ?float
    {
        if ($copie->constat === 'blanche') {
            return 0.0;
        }
        if ($copie->constat !== 'presente') {
            return null;
        }
        $attendues = $this->correctionsAttendues($copie);
        $notes = array_map(fn (int $c): ?float => $this->noteCorrection($copie, $c), $attendues);
        if (in_array(null, $notes, true)) {
            return null;
        }

        return in_array(3, $attendues, true) ? $notes[2] : round(array_sum($notes) / count($notes), 2);
    }

    /** @return list<string> motifs qui interdisent le verrouillage (RG-051) */
    public function blocages(EpreuveAnonyme $epreuve): array
    {
        $copies = $epreuve->copies()->with('saisies')->get();
        $motifs = [];
        $compte = fn (callable $f): int => $copies->filter($f)->count();
        if (($n = $compte(fn (CopieAnonyme $c): bool => $c->constat === 'attribuee')) > 0) {
            $motifs[] = "{$n} copie(s) non constatée(s)";
        }
        if (($n = $compte(fn (CopieAnonyme $c): bool => $c->constat === 'egaree')) > 0) {
            $motifs[] = "{$n} copie(s) égarée(s) non traitée(s)";
        }
        if (($n = $compte(fn (CopieAnonyme $c): bool => $c->constat === 'hors_liste')) > 0) {
            $motifs[] = "{$n} candidat(s) hors liste sans décision";
        }
        if (($n = $compte(fn (CopieAnonyme $c): bool => $c->constat === 'presente' && $this->noteFinale($c) === null)) > 0) {
            $motifs[] = "{$n} copie(s) présente(s) sans note arrêtée (saisie manquante ou écart à trancher)";
        }

        return $motifs;
    }

    public function verrouiller(EpreuveAnonyme $epreuve, User $gestionnaire): void
    {
        $this->gestionnaire($gestionnaire);
        DB::transaction(function () use ($epreuve, $gestionnaire): void {
            $epreuve = EpreuveAnonyme::query()->lockForUpdate()->findOrFail($epreuve->id);
            if ($epreuve->statut !== 'anonymisee') {
                throw new InvalidArgumentException('Seule une épreuve anonymisée se verrouille.');
            }
            $blocages = $this->blocages($epreuve);
            if ($blocages !== []) {
                throw new InvalidArgumentException('Verrouillage impossible : '.implode(' ; ', $blocages).'.');
            }
            $epreuve->forceFill(['statut' => 'verrouillee', 'verrouillee_at' => now()])->save();
            $this->journal($epreuve, $gestionnaire, 'anonymat_verrouille', 'Épreuve anonyme verrouillée');
        });
    }

    /** Autorisation de la levée par un témoin (V-15) : titulaire du droit de verrouiller les notes, pas gestionnaire. */
    public function autoriserLevee(EpreuveAnonyme $epreuve, User $temoin): void
    {
        abort_unless($temoin->can(PermissionsLmd::NOTES_VERROUILLER), 403);
        if ($temoin->can(PermissionsLmd::ANONYMAT_GERER) || $temoin->id === $epreuve->gestionnaire_id) {
            throw new InvalidArgumentException('Le témoin de la levée ne peut pas être le gestionnaire des anonymats.');
        }
        if ($epreuve->refresh()->statut !== 'verrouillee') {
            throw new InvalidArgumentException('Seule une épreuve verrouillée se lève.');
        }
        $epreuve->forceFill(['autorisee_par' => $temoin->id, 'autorisee_at' => now()])->save();
        $this->journal($epreuve, $temoin, 'levee_autorisee', 'Levée de l\'anonymat autorisée par le témoin');
    }

    /**
     * Levée (RG-052) : notes reportées dans les notes d'examen, étape d'examen verrouillée,
     * PV de levée scellé. Irréversible.
     */
    public function lever(EpreuveAnonyme $epreuve, string $phrase, User $gestionnaire): int
    {
        $this->gestionnaire($gestionnaire);

        return DB::transaction(function () use ($epreuve, $phrase, $gestionnaire): int {
            $epreuve = EpreuveAnonyme::query()->lockForUpdate()->with('ecue')->findOrFail($epreuve->id);
            if ($epreuve->statut === 'levee') {
                throw new InvalidArgumentException('L\'anonymat de cette épreuve est déjà levé.');
            }
            if ($epreuve->statut !== 'verrouillee' || $epreuve->autorisee_at === null) {
                throw new InvalidArgumentException('Levée non autorisée par le témoin.');
            }
            $correspondance = $this->correspondance($epreuve, $phrase, $gestionnaire, 'levee');
            $copies = $epreuve->copies()->with('saisies')->get()->keyBy('numero');
            $lignes = [];
            foreach ($correspondance as $numero => $ip) {
                $copie = $copies[$numero];
                $note = $this->noteFinale($copie);
                if ($note !== null) {
                    $this->saisie->corrigerParVoieAutorisee($ip, $epreuve->ecue, $epreuve->session, EtapeSaisie::EXAMEN,
                        number_format($note, 2, '.', ''), 'Levée de l\'anonymat', $gestionnaire);
                } elseif ($copie->constat !== 'ecartee') {
                    $absence = $copie->constat === 'egaree_traitee' ? NoteEcue::ABSENT_JUSTIFIE : NoteEcue::ABSENT_NON_JUSTIFIE;
                    NoteEcue::query()->firstOrNew(['inscription_pedagogique_id' => $ip->id, 'maquette_ecue_id' => $epreuve->ecue->id, 'session' => $epreuve->session])
                        ->forceFill(['examen' => null, 'presence_examen' => $absence, 'modifie_par' => $gestionnaire->id])->save();
                }
                $lignes[] = $numero.'|'.$ip->id.'|'.($note ?? $copie->constat);
            }
            sort($lignes);
            $etape = $this->saisie->etape($epreuve->ecue, $epreuve->session, EtapeSaisie::EXAMEN);
            $etape->forceFill(['statut' => EtapeSaisie::VERROUILLEE, 'verrouillee_par' => $gestionnaire->id, 'verrouillee_at' => now()])->save();
            $epreuve->forceFill(['statut' => 'levee', 'levee_par' => $gestionnaire->id, 'levee_at' => now(), 'pv_sha256' => hash('sha256', implode("\n", $lignes))])->save();
            $this->journal($epreuve, $gestionnaire, 'anonymat_leve', 'Anonymat levé, notes reportées', ['copies' => count($lignes)]);

            return count($lignes);
        });
    }

    /** Une épreuve anonyme en cours interdit la saisie de l'examen en clair (SAI-07). */
    public static function enCours(int $ecueId, string $session): bool
    {
        return EpreuveAnonyme::query()->where('maquette_ecue_id', $ecueId)->where('session', $session)->where('statut', '!=', 'levee')->exists();
    }

    public static function numero(): string
    {
        $corps = '';
        for ($i = 0; $i < 8; $i++) {
            $corps .= self::ALPHABET[random_int(0, 31)];
        }

        return $corps.self::controle($corps);
    }

    /** Caractère de contrôle : somme pondérée des positions, modulo 32. */
    public static function controle(string $corps): string
    {
        $somme = 0;
        foreach (str_split($corps) as $i => $c) {
            $somme += ($i + 1) * strpos(self::ALPHABET, $c);
        }

        return self::ALPHABET[$somme % 32];
    }

    public static function numeroValide(string $numero): bool
    {
        return preg_match('/^['.self::ALPHABET.']{9}$/', $numero) === 1 && self::controle(substr($numero, 0, 8)) === $numero[8];
    }

    /** @return Collection<int, InscriptionPedagogique> candidats attendus : inscrits ; en rattrapage, les éligibles */
    private function candidats(EpreuveAnonyme $epreuve): Collection
    {
        $inscrits = $this->saisie->inscrits($epreuve->ecue);
        if ($epreuve->session !== EtapeSaisie::RATTRAPAGE) {
            return $inscrits;
        }
        $regle = RegleEvaluation::pourAnnee($epreuve->ecue->maquetteUe->maquette->annee_academique);

        return $inscrits->filter(fn (InscriptionPedagogique $ip): bool => $this->saisie->resultat($ip, $regle)->eligibleRattrapage)->values();
    }

    private function cle(string $phrase, string $sel): string
    {
        return hash_pbkdf2('sha256', $phrase, base64_decode($sel, true) ?: '', self::ITERATIONS, 32, true);
    }

    private function cleVerifiee(EpreuveAnonyme $epreuve, string $phrase): string
    {
        if ($epreuve->sel === null || mb_strlen($phrase) < self::PHRASE_MIN) {
            throw new InvalidArgumentException('Clé d\'anonymat absente ou trop courte.');
        }
        $cle = $this->cle($phrase, $epreuve->sel);
        if (! hash_equals((string) $epreuve->verificateur, hash_hmac('sha256', 'verificateur', $cle))) {
            throw new InvalidArgumentException('Clé d\'anonymat incorrecte pour cette épreuve.');
        }

        return $cle;
    }

    private function chiffrer(string $valeur, string $cle): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $chiffre = openssl_encrypt($valeur, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv.$tag.$chiffre);
    }

    private function dechiffrer(string $donnees, string $cle): string
    {
        $brut = base64_decode($donnees, true) ?: '';
        $clair = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, substr($brut, 0, 12), substr($brut, 12, 16));
        if ($clair === false) {
            throw new InvalidArgumentException('Clé d\'anonymat incorrecte pour cette épreuve.');
        }

        return $clair;
    }

    private function gestionnaire(User $user): void
    {
        abort_unless($user->can(PermissionsLmd::ANONYMAT_GERER), 403);
    }

    /** @param  array<string, mixed>  $extra */
    private function journal(EpreuveAnonyme $epreuve, User $auteur, string $evenement, string $libelle, array $extra = []): void
    {
        activity('lmd')->causedBy($auteur)->performedOn($epreuve)->event($evenement)
            ->withProperties(['epreuve' => $epreuve->libelle(), ...$extra])->log($libelle);
    }
}
