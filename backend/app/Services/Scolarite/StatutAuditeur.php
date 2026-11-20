<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Statut de l'auditeur (spec module 4, lot 2 §4). « Suspendu » se pose et se lève par SuspensionsAuditeurs, avec la
 * demande écrite de l'auditeur (20/11/2026). Les autres statuts restent informatifs : ils ne
 * bloquent ni les versements ni l'accès. Chaque changement porte un motif, une
 * date d'effet et finit au journal avec l'ancien et le nouveau statut.
 */
final class StatutAuditeur
{
    public const PERMISSION = 'scolarite.statut';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function changer(Auditeur $auditeur, string $statut, string $motif, string $depuis, User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION), 403);

        if (! array_key_exists($statut, Auditeur::STATUTS)) {
            throw new InvalidArgumentException('Choisissez un statut de la liste.');
        }
        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Indiquez le motif du changement de statut.');
        }
        if (blank($depuis)) {
            throw new InvalidArgumentException('Indiquez la date d\'effet.');
        }
        $date = CarbonImmutable::parse($depuis, InscriptionAnnuelle::FUSEAU)->startOfDay();
        if ($date->greaterThan(InscriptionAnnuelle::aujourdhui())) {
            throw new InvalidArgumentException('La date d\'effet ne peut pas être dans le futur.');
        }
        // Une suspension porte la demande écrite de l'auditeur et suspend son compte eCampus (20/11/2026).
        if ($statut === Auditeur::STATUT_SUSPENDU) {
            throw new InvalidArgumentException('Une suspension se pose par « Suspendre pour l\'année », avec la demande écrite de l\'auditeur.');
        }

        $this->db->transaction(function () use ($auditeur, $statut, $motif, $date, $auteur): void {
            $verrouille = Auditeur::query()->whereKey($auditeur->id)->lockForUpdate()->firstOrFail();
            $ancien = (string) $verrouille->statut;
            if ($verrouille->suspensionEnCours()->exists()) {
                throw new InvalidArgumentException('Une suspension est en cours : levez-la d\'abord (« Lever la suspension »).');
            }
            if ($ancien === $statut) {
                throw new InvalidArgumentException('L\'auditeur est déjà « '.Auditeur::STATUTS[$statut].' ».');
            }

            $verrouille->forceFill([
                'statut' => $statut,
                'statut_motif' => mb_substr($motif, 0, 500),
                'statut_depuis' => $date->toDateString(),
                'statut_par' => $auteur->id,
            ])->save();

            activity('scolarite')->causedBy($auteur)->performedOn($verrouille)
                ->event('statut_change')
                ->withProperties(['ancien' => $ancien, 'nouveau' => $statut, 'motif' => $motif, 'depuis' => $date->toDateString()])
                ->log('Statut de l\'auditeur modifié');
        });

        $auditeur->refresh();
    }
}
