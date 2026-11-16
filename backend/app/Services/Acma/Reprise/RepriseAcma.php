<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Models\User;
use App\Services\Acma\CorrespondancesAcma;
use App\Services\Acma\ErreurAcma;
use App\Services\Acma\LectureTransit;
use App\Services\Acma\VerrouAcma;
use App\Support\Journaux;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * `acma:reprendre` (spec du lot 15, §10.2 à §10.5 ; ADR-0014) : transit → archive, dans une seule transaction en mode
 * reprise (`app.reprise_acma`), puis contrôles d'équivalence.
 *
 * - À blanc par défaut : tout est fait puis annulé ; seuls restent la trace de l'essai, les rapports et le journal.
 * - `--appliquer` : la transaction est validée si aucun contrôle bloquant n'échoue, sinon tout est annulé.
 * - Idempotente : la même source rejouée n'écrit rien. Refusée après le gel.
 * - Aucun envoi : aucun service d'envoi n'est appelé, et C10 le vérifie.
 */
final class RepriseAcma
{
    /** Étapes dans l'ordre du §10.3. */
    public const ETAPES = [
        'referentiels' => EtapeReferentiels::class,
        'dossiers' => EtapeDossiers::class,
        'rapprochement' => EtapeRapprochement::class,
        'inscriptions' => EtapeInscriptions::class,
        'resultats' => EtapeResultats::class,
        'versions_notes' => EtapeVersionsNotes::class,
        'baremes' => EtapeBaremes::class,
        'paiements' => EtapePaiements::class,
        'soutenances' => EtapeSoutenances::class,
        'documents' => EtapeDocuments::class,
    ];

    private static ?ContexteReprise $enCours = null;

    private static ?int $ecouteSur = null;

    public function __construct(
        private readonly ControlesAcma $controles,
        private readonly RapportsReprise $rapports,
    ) {}

    /**
     * @param  list<string>|null  $etapes  null : toutes
     * @return array{uuid: string, statut: string, comptes: array<string, mixed>, controles: list<ResultatControle>, rapport: string, classeur: string|null}
     */
    public function executer(User $auteur, ?array $etapes, bool $appliquer): array
    {
        LectureTransit::chargement(formatCourant: true);
        self::refuserSiGelee();
        $etapes = self::etapes($etapes);
        $this->ecouterHttp();

        $maintenant = CarbonImmutable::now();
        $uuid = (string) Str::uuid();
        $etape = 'des préalables';
        $ouverte = false;

        try {
            DB::beginTransaction();
            $ouverte = true;
            // Exclusion mutuelle avec charger, geler et purger ; l'état se revérifie sous le verrou.
            VerrouAcma::prendre();
            self::refuserSiGelee();
            $chargement = LectureTransit::chargement();
            $this->verifierNiveaux();
            $ligne = [
                'uuid' => $uuid,
                'export_fichier' => $chargement['manifeste']['source']['fichier'] ?? null,
                'export_date' => $chargement['manifeste']['source']['date_export'] ?? null,
                'manifeste_sha256' => $chargement['manifeste_sha256'],
                'etapes' => json_encode($etapes, JSON_THROW_ON_ERROR),
                'mode' => $appliquer ? 'appliquee' : 'essai',
                'lance_par' => $auteur->getKey(),
                'debut_at' => $maintenant,
            ];
            self::mode(true);
            $repriseId = (int) DB::table('acma_reprises')->insertGetId($ligne + ['statut' => 'a_blanc', 'comptes' => '{}', 'controles' => '[]', 'controles_ok' => false, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            $contexte = new ContexteReprise($maintenant, $auteur, $repriseId);
            $contexte->vivantAvant = ControlesAcma::compterVivant();
            self::$enCours = $contexte;

            $comptes = [];
            foreach ($etapes as $nom) {
                $etape = "de l'étape {$nom}";
                $comptes[$nom] = app(self::ETAPES[$nom])->executer($contexte);
            }
            $etape = 'des contrôles';
            $controles = $this->controles->executer($contexte);
            $ok = array_filter($controles, fn (ResultatControle $c): bool => $c->enEchec()) === [];
            $statut = $appliquer ? ($ok ? 'appliquee' : 'refusee') : 'a_blanc';
            $trace = [
                'statut' => $statut,
                'comptes' => json_encode($comptes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'controles' => json_encode(array_map(fn (ResultatControle $c): array => $c->versTableau(), $controles), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'controles_ok' => $ok,
                'fin_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ];

            if ($statut === 'appliquee') {
                $etape = 'de la validation';
                DB::table('acma_reprises')->where('id', $repriseId)->update($trace);
                self::mode(false);
                DB::commit();
                $ouverte = false;
            } else {
                self::mode(false);
                DB::rollBack();
                $ouverte = false;
                $etape = 'de la trace de l\'essai';
                DB::transaction(function () use ($ligne, $trace, $maintenant): void {
                    VerrouAcma::prendre();
                    if (DB::table('acma_reprises')->where('statut', 'gelee')->exists()) {
                        return;
                    }
                    self::mode(true);
                    DB::table('acma_reprises')->insert($ligne + $trace + ['created_at' => $maintenant]);
                    self::mode(false);
                });
            }
        } catch (Throwable $e) {
            if ($ouverte) {
                DB::rollBack();
            }
            // Aucune donnée dans le message : SQLSTATE, table, étape (ErreurAcma).
            throw ErreurAcma::sansDonnees($e, $etape);
        } finally {
            self::$enCours = null;
        }

        $rapport = $this->rapports->ecrireControles($controles, $comptes, [
            'Reprise' => $uuid,
            'Mode' => $appliquer ? 'appliquer' : 'à blanc',
            'Statut' => $statut,
            'Export' => $ligne['export_fichier'].' ('.($ligne['export_date'] ?? 'date inconnue').')',
            'Empreinte du manifeste' => $ligne['manifeste_sha256'],
            'Étapes' => implode(', ', $etapes),
            'Date' => $maintenant->toIso8601String(),
        ], $maintenant);
        $classeur = $contexte->listes === [] ? null : $this->rapports->ecrireClasseur($contexte->listes, $maintenant);

        activity(Journaux::ARCHIVES)->causedBy($auteur)->event($statut === 'appliquee' ? 'reprise_appliquee' : 'reprise_essai')
            ->withProperties([
                'reprise' => $uuid, 'statut' => $statut, 'export' => $ligne['export_fichier'], 'manifeste_sha256' => $ligne['manifeste_sha256'],
                'etapes' => $etapes, 'comptes' => $comptes,
                'controles' => array_combine(array_map(fn (ResultatControle $c): string => $c->code, $controles), array_map(fn (ResultatControle $c): string => $c->statut(), $controles)),
            ])
            ->log($statut === 'appliquee' ? 'Reprise d\'ACMA appliquée' : 'Reprise d\'ACMA à blanc');

        return ['uuid' => $uuid, 'statut' => $statut, 'comptes' => $comptes, 'controles' => $controles, 'rapport' => $rapport, 'classeur' => $classeur];
    }

    /** Pose ou retire le mode reprise, local à la transaction (ADR-0014). */
    public static function mode(bool $actif): void
    {
        DB::select("SELECT set_config('app.reprise_acma', ?, true)", [$actif ? 'on' : 'off']);
    }

    public static function refuserSiGelee(): void
    {
        $gel = DB::table('acma_reprises')->where('statut', 'gelee')->value('gelee_at');
        if ($gel !== null) {
            throw new ErreurAcma('Archive gelée le '.CarbonImmutable::parse($gel)->format('d/m/Y à H:i').' : aucune reprise possible.');
        }
    }

    /**
     * @param  list<string>|null  $demandees
     * @return list<string>
     */
    private static function etapes(?array $demandees): array
    {
        if ($demandees === null || $demandees === []) {
            return array_keys(self::ETAPES);
        }
        $inconnues = array_diff($demandees, array_keys(self::ETAPES));
        if ($inconnues !== []) {
            throw new ErreurAcma('Étapes inconnues : '.implode(', ', $inconnues).'. Étapes : '.implode(', ', array_keys(self::ETAPES)).'.');
        }

        return array_values(array_intersect(array_keys(self::ETAPES), $demandees));
    }

    /** Étape 0 : tout code de niveau d'une année reprise doit être connu (§5.2), sinon rien ne s'écrit. */
    private function verifierNiveaux(): void
    {
        $codes = [];
        foreach ([['programe', 'codniveau', 'anneeacademic'], ['inscription_academic', 'niveau', 'anneeacademic'], ['configurationdu', 'niveau', 'anneaacademic'], ['du', 'Niveau', 'anneacademic']] as [$table, $niveau, $annee]) {
            foreach (LectureTransit::table($table)->distinct()->get([$niveau.' as n', $annee.' as a']) as $r) {
                if (CorrespondancesAcma::anneeReprise($r->a) && trim((string) $r->n) !== '' && ! CorrespondancesAcma::niveauConnu(trim((string) $r->n))) {
                    $codes[trim((string) $r->n)] = true;
                }
            }
        }
        if ($codes !== []) {
            throw new ErreurAcma('Codes de niveau ACMA inconnus : '.implode(', ', array_keys($codes)).'. Reprise bloquée.');
        }
    }

    /** C10 : compte les appels HTTP sortants pendant une reprise. */
    private function ecouterHttp(): void
    {
        if (self::$ecouteSur === spl_object_id(app('events'))) {
            return;
        }
        Event::listen(RequestSending::class, function (): void {
            if (self::$enCours !== null) {
                self::$enCours->requetesHttp++;
            }
        });
        self::$ecouteSur = spl_object_id(app('events'));
    }
}
