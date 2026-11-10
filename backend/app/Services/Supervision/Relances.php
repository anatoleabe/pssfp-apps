<?php

declare(strict_types=1);

namespace App\Services\Supervision;

use App\Models\AnomalieSysteme;
use App\Models\Message;
use App\Models\RelanceAnomalie;
use App\Services\Messagerie\Messagerie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lot 14b-2 (D30, D31) : relances graduées des anomalies du contrôle interne, envoyées par la messagerie interne
 * (port de `generer_relances` et `relance_vers_message`, sql/19 et sql/20).
 */
final class Relances
{
    /** La direction : le rôle qui signe le calendrier et les actes. */
    public const DIRECTION = 'chef_structure';

    public const ESCALADE = 'scolarite_responsable';

    /** Faute de titulaire du rôle visé, dans cet ordre. */
    public const REPLIS = ['admin', 'super_admin'];

    public const INTERNE = 'interne';

    /**
     * Barème (D30) : criticité => niveau => [jours après l'échéance, rôle visé (null = responsable du contrôle), canaux].
     *
     * @var array<string, array<int, array{0: int, 1: ?string, 2: list<string>}>>
     */
    public const BAREME = [
        Controle::BLOQUANT => [
            1 => [0, null, [self::INTERNE, 'email', 'sms']],
            2 => [2, self::ESCALADE, [self::INTERNE, 'email', 'sms']],
            3 => [5, self::DIRECTION, [self::INTERNE, 'email']],
        ],
        Controle::MAJEUR => [
            1 => [0, null, [self::INTERNE, 'email']],
            2 => [5, self::ESCALADE, [self::INTERNE, 'email']],
            3 => [15, self::DIRECTION, [self::INTERNE]],
        ],
        Controle::MINEUR => [
            1 => [0, null, [self::INTERNE]],
            2 => [15, self::ESCALADE, [self::INTERNE]],
        ],
    ];

    public const PRIORITES = [1 => Message::NORMALE, 2 => Message::HAUTE, 3 => Message::URGENTE];

    /** Anomalies listées au plus dans un message ; les suivantes sont comptées. */
    public const LIGNES_MAX = 100;

    public function __construct(
        private readonly Supervision $supervision,
        private readonly Messagerie $messagerie,
    ) {}

    /**
     * Envoie les relances dues : une par anomalie et par niveau, regroupées en un message par niveau et par rôle.
     *
     * @return array{relances: int, messages: int, sans_destinataire: int}
     */
    public function generer(): array
    {
        $bilan = ['relances' => 0, 'messages' => 0, 'sans_destinataire' => 0];
        Cache::lock('supervision:relances', 300)->block(30, function () use (&$bilan): void {
            foreach ($this->dues() as $lot) {
                $resultat = $this->envoyer($lot['role'], $lot['niveau'], $lot['relances']);
                $bilan['relances'] += count($lot['relances']);
                $bilan['messages'] += $resultat ? 1 : 0;
                $bilan['sans_destinataire'] += $resultat ? 0 : count($lot['relances']);
            }
        });

        return $bilan;
    }

    /** Rôle relancé à un niveau : jamais, en escalade, le responsable déjà relancé au niveau 1. */
    public static function roleVise(string $responsable, int $niveau, ?string $vise): string
    {
        if ($niveau === 1) {
            return $responsable;
        }
        $role = $vise ?? $responsable;
        if ($role === $responsable) {
            $role = self::DIRECTION;
        }

        return $role === $responsable ? self::REPLIS[0] : $role;
    }

    /** @return array<string, int> code du contrôle => relances envoyées */
    public function parControle(): array
    {
        return RelanceAnomalie::query()->join('anomalies_systeme', 'anomalies_systeme.id', '=', 'relances_anomalies.anomalie_id')
            ->groupBy('anomalies_systeme.controle_code')->selectRaw('anomalies_systeme.controle_code as code, count(*) as n')
            ->pluck('n', 'code')->map(fn ($n): int => (int) $n)->all();
    }

    /**
     * Relances dues, groupées par rôle visé et par niveau.
     *
     * @return list<array{role: string, niveau: int, relances: list<array{anomalie: AnomalieSysteme, controle: Controle, canaux: list<string>}>}>
     */
    private function dues(): array
    {
        $controles = $this->supervision->controles();
        $aujourdhui = CarbonImmutable::now(Supervision::FUSEAU)->startOfDay();
        $anomalies = AnomalieSysteme::query()->aTraiter()->whereIn('controle_code', array_keys($controles))
            ->where('echeance', '<=', $aujourdhui->toDateString())->with('relances:id,anomalie_id,niveau')->orderBy('id')->get();
        $lots = [];
        foreach ($anomalies as $a) {
            $controle = $controles[$a->controle_code];
            $deja = $a->relances->pluck('niveau')->all();
            $echeance = CarbonImmutable::parse($a->echeance->toDateString(), Supervision::FUSEAU);
            foreach (self::BAREME[$controle->criticite()] ?? [] as $niveau => [$jours, $vise, $canaux]) {
                if (in_array($niveau, $deja, true) || $aujourdhui->lt($echeance->addDays($jours))) {
                    continue;
                }
                $role = self::roleVise($controle->responsable(), $niveau, $vise);
                $cle = $role.'|'.$niveau;
                $lots[$cle] ??= ['role' => $role, 'niveau' => $niveau, 'relances' => []];
                $lots[$cle]['relances'][] = ['anomalie' => $a, 'controle' => $controle, 'canaux' => $canaux];
            }
        }
        ksort($lots);

        return array_values($lots);
    }

    /**
     * Un message pour le lot, puis une relance par anomalie. Faux si personne n'est joignable.
     *
     * @param  list<array{anomalie: AnomalieSysteme, controle: Controle, canaux: list<string>}>  $relances
     */
    private function envoyer(string $role, int $niveau, array $relances): bool
    {
        [$roleUtilise, $ids] = $this->destinataires($role);

        return DB::transaction(function () use ($role, $roleUtilise, $ids, $niveau, $relances): bool {
            $message = null;
            if ($ids !== []) {
                $canaux = collect($relances)->pluck('canaux')->flatten()->unique()->reject(self::INTERNE)->values()->all();
                $message = $this->messagerie->envoyer(null, $ids, $this->objet($niveau, count($relances)),
                    $this->corps($role, $roleUtilise, $niveau, $relances), Message::RELANCE, self::PRIORITES[$niveau], $canaux);
            }
            foreach ($relances as $r) {
                RelanceAnomalie::query()->create([
                    'anomalie_id' => $r['anomalie']->id, 'niveau' => $niveau, 'destinataire_role' => $message === null ? $role : $roleUtilise,
                    'canaux' => $r['canaux'], 'message_id' => $message?->id, 'destinataires' => count($ids), 'cree_le' => now(),
                ]);
            }

            return $message !== null;
        });
    }

    /**
     * Comptes du personnel qui détiennent le rôle ; à défaut, l'administrateur, puis le super administrateur.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function destinataires(string $role): array
    {
        foreach (array_values(array_unique([$role, ...self::REPLIS])) as $r) {
            $ids = Messagerie::personnel()->whereHas('roles', fn (Builder $query) => $query->where('name', $r))
                ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($ids !== []) {
                return [$r, $ids];
            }
        }

        return [$role, []];
    }

    private function objet(int $niveau, int $nombre): string
    {
        $anomalies = $nombre.' anomalie'.($nombre > 1 ? 's' : '');

        return $niveau === 1
            ? 'Relance du contrôle interne : '.$anomalies.' à traiter'
            : 'Escalade (niveau '.$niveau.') du contrôle interne : '.$anomalies.' en retard';
    }

    /**
     * Sans donnée personnelle : contrôle, numéro, ancienneté, échéance, criticité.
     *
     * @param  list<array{anomalie: AnomalieSysteme, controle: Controle, canaux: list<string>}>  $relances
     */
    private function corps(string $role, string $roleUtilise, int $niveau, array $relances): string
    {
        $aujourdhui = CarbonImmutable::now(Supervision::FUSEAU)->startOfDay();
        $lignes = [$niveau === 1
            ? 'Les anomalies suivantes, confiées à votre rôle, ont atteint leur échéance :'
            : 'Les anomalies suivantes restent ouvertes après leur échéance. Elles vous sont signalées en escalade (niveau '.$niveau.') :'];
        if ($roleUtilise !== $role) {
            $lignes[] = 'Aucun compte ne détient le rôle « '.$role.' » : la relance vous parvient à sa place.';
        }
        $lignes[] = '';
        foreach (array_slice($relances, 0, self::LIGNES_MAX) as $r) {
            $a = $r['anomalie'];
            $depuis = (int) CarbonImmutable::parse($a->premiere_detection)->setTimezone(Supervision::FUSEAU)->startOfDay()->diffInDays($aujourdhui);
            $lignes[] = '• '.$r['controle']->libelle().' ('.$r['controle']->criticite().') — anomalie n° '.$a->id
                .' : détectée il y a '.$depuis.' jour'.($depuis > 1 ? 's' : '').', échéance du '.$a->echeance->format('d/m/Y').'.';
        }
        if (count($relances) > self::LIGNES_MAX) {
            $lignes[] = '… et '.(count($relances) - self::LIGNES_MAX).' autre(s).';
        }
        $lignes[] = '';
        $lignes[] = 'À traiter dans Administration › Contrôle interne : '.route('filament.admin.resources.controle-interne.index');
        $lignes[] = 'Message automatique du contrôle interne : une anomalie corrigée se ferme seule au contrôle suivant ; un cas accepté se justifie.';

        return implode("\n", $lignes);
    }
}
