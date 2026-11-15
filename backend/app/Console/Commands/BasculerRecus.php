<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\InscriptionAnnuelle;
use App\Services\Scolarite\RecusVersements;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Bascule ACMA des reçus (spec Scolarité lot 4 §5, décision du 15/11/2026) : pose, une fois, la date
 * à partir de laquelle la liste d'attente des reçus compte les versements. Idempotente ; une date
 * posée ne se déplace qu'avec --force et un motif. Chaque écriture va au journal `scolarite`.
 */
final class BasculerRecus extends Command
{
    private const MOTIF_MIN = 5;

    protected $signature = 'scolarite:bascule-recus
        {--date= : Date et heure de l\'arrêt d\'ACMA, heure de Yaoundé (défaut : maintenant)}
        {--force : Déplace une date déjà posée}
        {--motif= : Motif du déplacement, obligatoire avec --force}';

    protected $description = 'Pose la date de bascule ACMA : la liste des reçus en attente ne compte plus que les versements enregistrés depuis.';

    public function handle(RecusVersements $recus): int
    {
        try {
            $date = $this->dateDemandee();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $posee = $recus->bascule();
        if ($posee === null) {
            $this->ecrire($date, null, $this->motif());
            $this->info('Bascule des reçus posée au '.self::afficher($date).' (heure de Yaoundé) : la liste d\'attente ne compte plus que les versements enregistrés depuis.');

            return self::SUCCESS;
        }

        $sansDemande = $this->option('date') === null && ! $this->option('force');
        if ($sansDemande || $date->equalTo($posee)) {
            $this->info('Bascule des reçus déjà posée au '.self::afficher($posee).' (heure de Yaoundé) : rien à faire.');

            return self::SUCCESS;
        }
        if (! $this->option('force')) {
            $this->error('Bascule des reçus déjà posée au '.self::afficher($posee).' (heure de Yaoundé) : pour la déplacer, relancez avec --force et --motif.');

            return self::FAILURE;
        }
        $motif = $this->motif();
        if ($motif === null || mb_strlen($motif) < self::MOTIF_MIN) {
            $this->error('Le déplacement de la bascule exige un motif d\'au moins '.self::MOTIF_MIN.' caractères (--motif=…).');

            return self::FAILURE;
        }

        $this->ecrire($date, $posee, $motif);
        $this->info('Bascule des reçus déplacée au '.self::afficher($date).' (heure de Yaoundé ; elle était au '.self::afficher($posee).').');

        return self::SUCCESS;
    }

    /** --date lue à l'heure de Yaoundé (sauf fuseau explicite) ; maintenant par défaut ; jamais dans le futur. */
    private function dateDemandee(): CarbonImmutable
    {
        $texte = $this->option('date');
        if ($texte === null) {
            return CarbonImmutable::now();
        }
        try {
            $date = CarbonImmutable::parse((string) $texte, InscriptionAnnuelle::FUSEAU);
        } catch (Throwable) {
            throw new InvalidArgumentException("Date illisible : « {$texte} ». Format attendu : AAAA-MM-JJ HH:MM (heure de Yaoundé).");
        }
        if ($date->greaterThan(CarbonImmutable::now())) {
            throw new InvalidArgumentException('La bascule ne se pose pas dans le futur ('.self::afficher($date).') : lancez la commande au moment de l\'arrêt d\'ACMA.');
        }

        return $date;
    }

    private function motif(): ?string
    {
        $motif = trim((string) $this->option('motif'));

        return $motif === '' ? null : mb_substr($motif, 0, 500);
    }

    private function ecrire(CarbonImmutable $date, ?CarbonImmutable $ancienne, ?string $motif): void
    {
        $iso = $date->setTimezone((string) config('app.timezone'))->toIso8601String();

        DB::transaction(function () use ($iso, $ancienne, $motif): void {
            AppSettings::set(RecusVersements::CLE_BASCULE, $iso);
            activity('scolarite')
                ->event($ancienne === null ? 'recus_bascule_posee' : 'recus_bascule_deplacee')
                ->withProperties(array_filter(['date' => $iso, 'ancienne' => $ancienne?->toIso8601String(), 'motif' => $motif], fn ($v) => $v !== null))
                ->log($ancienne === null ? 'Bascule ACMA des reçus posée' : 'Bascule ACMA des reçus déplacée');
        });
    }

    private static function afficher(CarbonImmutable $date): string
    {
        return $date->setTimezone(InscriptionAnnuelle::FUSEAU)->format('d/m/Y H:i');
    }
}
