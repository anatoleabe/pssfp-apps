<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use App\Models\User;
use App\Services\Acma\ChargementTransit;
use App\Services\Acma\ErreurAcma;
use App\Services\Acma\VerrouAcma;
use App\Support\Journaux;
use Illuminate\Support\Facades\DB;

/**
 * Gel de l'archive et purge du transit (spec du lot 15, §7.3, §10.6). Le gel est irréversible par l'application : il
 * exige la dernière reprise appliquée sans contrôle bloquant en échec, et des contrôles encore conformes au moment du
 * gel. La purge du transit n'est possible qu'après le gel.
 */
final class GelArchive
{
    public function __construct(private readonly ControlesAcma $controles) {}

    /** Vérifie que le gel est possible ; rend la reprise à geler. */
    public function verifier(): object
    {
        RepriseAcma::refuserSiGelee();
        $reprise = DB::table('acma_reprises')->where('statut', 'appliquee')->orderByDesc('id')->first();
        if ($reprise === null || ! $reprise->controles_ok) {
            throw new ErreurAcma('Aucune reprise appliquée sans contrôle bloquant en échec : gel refusé.');
        }
        $echecs = array_filter($this->controles->executer(null), fn (ResultatControle $c): bool => $c->enEchec());
        if ($echecs !== []) {
            throw new ErreurAcma('Contrôles bloquants en échec ('.implode(', ', array_map(fn (ResultatControle $c): string => $c->code, $echecs)).') : gel refusé.');
        }

        return $reprise;
    }

    public function geler(User $auteur): string
    {
        try {
            return DB::transaction(function () use ($auteur): string {
                // Sous le verrou commun : aucune reprise ni chargement en cours ; l'état se revérifie ici.
                VerrouAcma::prendre();
                $reprise = $this->verifier();
                RepriseAcma::mode(true);
                DB::table('acma_reprises')->where('id', $reprise->id)->update(['statut' => 'gelee', 'gelee_at' => now(), 'gelee_par' => $auteur->getKey(), 'updated_at' => now()]);
                RepriseAcma::mode(false);
                activity(Journaux::ARCHIVES)->causedBy($auteur)->event('archive_gelee')
                    ->withProperties(['reprise' => $reprise->uuid, 'manifeste_sha256' => $reprise->manifeste_sha256])->log('Archive ACMA gelée');

                return (string) $reprise->uuid;
            });
        } catch (\Throwable $e) {
            throw ErreurAcma::sansDonnees($e, 'du gel');
        }
    }

    public function verifierPurge(): void
    {
        if (! DB::table('acma_reprises')->where('statut', 'gelee')->exists()) {
            throw new ErreurAcma('Archive non gelée : le transit ne se purge qu\'après le gel.');
        }
        if (! ChargementTransit::charge()) {
            throw new ErreurAcma('Transit ACMA absent : rien à purger.');
        }
    }

    public function purgerTransit(User $auteur): void
    {
        try {
            DB::transaction(function () use ($auteur): void {
                VerrouAcma::prendre();
                $this->verifierPurge();
                $empreinte = DB::table(ChargementTransit::SCHEMA.'._chargement')->value('manifeste_sha256');
                ChargementTransit::purger();
                activity(Journaux::ARCHIVES)->causedBy($auteur)->event('transit_purge')
                    ->withProperties(['manifeste_sha256' => $empreinte])->log('Transit ACMA purgé');
            });
        } catch (\Throwable $e) {
            throw ErreurAcma::sansDonnees($e, 'de la purge du transit');
        }
    }
}
