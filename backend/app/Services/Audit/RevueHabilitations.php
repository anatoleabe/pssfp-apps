<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\User;
use App\Services\Messagerie\Messagerie;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Lot 14d-3 (D30) : revue annuelle des habilitations. Un rôle d'un compte du personnel est maintenu ou retiré, par un
 * titulaire de `update_user` qui peut modifier ce compte, jamais par le titulaire lui-même.
 */
final class RevueHabilitations
{
    public const MAINTENU = 'maintenu';

    public const RETIRE = 'retire';

    public const VALIDITE_JOURS = 365;

    /**
     * @param  list<string>  $maintenus  rôles conservés ; les autres rôles actuels du compte sont retirés
     */
    public function reviser(User $compte, array $maintenus, ?string $commentaire, User $reviseur): void
    {
        if ($reviseur->is($compte)) {
            throw new InvalidArgumentException('On ne revoit pas ses propres habilitations.');
        }
        abort_unless(Gate::forUser($reviseur)->allows('update', $compte), 403);
        $commentaire = trim((string) $commentaire);
        $roles = $this->rolesRevisables($compte);
        if ($roles === []) {
            throw new InvalidArgumentException('Ce compte n\'a aucun rôle du personnel à revoir.');
        }
        $retires = array_values(array_diff($roles, $maintenus));
        if ($retires !== [] && mb_strlen($commentaire) < 10) {
            throw new InvalidArgumentException('Un retrait de rôle se motive (10 caractères au moins).');
        }
        if (in_array('super_admin', $retires, true)
            && User::query()->role('super_admin')->whereKeyNot($compte->id)->doesntExist()) {
            throw new InvalidArgumentException('Le dernier super administrateur ne perd pas son rôle.');
        }

        DB::transaction(function () use ($compte, $roles, $retires, $commentaire, $reviseur): void {
            $maintenant = now();
            foreach ($roles as $role) {
                $retire = in_array($role, $retires, true);
                DB::table('revues_habilitations')->insert([
                    'user_id' => $compte->id, 'role' => $role, 'decision' => $retire ? self::RETIRE : self::MAINTENU,
                    'commentaire' => $commentaire === '' ? null : mb_substr($commentaire, 0, 1000), 'revu_par' => $reviseur->id, 'revu_le' => $maintenant,
                ]);
                if ($retire) {
                    $compte->removeRole($role);
                    activity('roles')->causedBy($reviseur)->performedOn($compte)->event('role_retire_revue')
                        ->withProperties(['role' => $role, 'motif' => $commentaire])->log('Rôle retiré lors de la revue des habilitations');
                }
            }
            activity('roles')->causedBy($reviseur)->performedOn($compte)->event('habilitations_revues')
                ->withProperties(['maintenus' => array_values(array_diff($roles, $retires)), 'retires' => $retires])->log('Habilitations revues');
        });
    }

    /** @return list<string> rôles du compte soumis à la revue (le rôle candidat n'en fait pas partie) */
    public function rolesRevisables(User $compte): array
    {
        return $compte->roles()->whereNotIn('name', User::NON_PANEL_ROLES)->orderBy('name')->pluck('name')->all();
    }

    /**
     * Habilitations du personnel jamais revues, ou revues depuis plus d'un an.
     *
     * @return list<array{compte: User, role: string, derniere: ?CarbonImmutable}>
     */
    public function aRevoir(): array
    {
        $seuil = CarbonImmutable::now()->subDays(self::VALIDITE_JOURS);
        $dernieres = DB::table('revues_habilitations')->groupBy('user_id', 'role')
            ->selectRaw('user_id, role, max(revu_le) as derniere')->get()->keyBy(fn ($r): string => $r->user_id.'|'.$r->role);
        $resultat = [];
        foreach (Messagerie::personnel()->with('roles:id,name')->orderBy('name')->get() as $compte) {
            foreach ($compte->roles as $r) {
                if (in_array($r->name, User::NON_PANEL_ROLES, true)) {
                    continue;
                }
                $derniere = $dernieres->get($compte->id.'|'.$r->name)?->derniere;
                $date = $derniere === null ? null : CarbonImmutable::parse($derniere);
                if ($date === null || $date->lt($seuil)) {
                    $resultat[] = ['compte' => $compte, 'role' => $r->name, 'derniere' => $date];
                }
            }
        }

        return $resultat;
    }
}
