<?php

declare(strict_types=1);

namespace App\Services\Lmd;

use App\Models\InscriptionAnnuelle;
use App\Models\InscriptionPedagogique;
use App\Models\Maquette;
use App\Models\Parcours;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Inscription pédagogique (spec lot 4, § 3.5) : une ligne par UE de la maquette
 * de référence (parcours et année de l'inscription), validée seulement.
 * Rejouable sans doublon.
 */
final class InscriptionsPedagogiques
{
    public const INSCRITE = 'inscrite';

    public const SANS_PARCOURS = 'sans_parcours';

    public const SANS_MAQUETTE = 'sans_maquette';

    public function __construct(private readonly ParcoursAuditeur $parcours) {}

    /**
     * @return array{issue: string, parcours: ?string, ues: int}
     */
    public function inscrire(InscriptionAnnuelle $inscription, ?User $auteur = null): array
    {
        if ($auteur !== null) {
            abort_unless($auteur->can(PermissionsLmd::INSCRIRE), 403);
        }
        $inscription->loadMissing(['parcours', 'auditeur.candidature']);
        $parcours = $this->parcours->pour($inscription);
        if ($parcours === null) {
            return ['issue' => self::SANS_PARCOURS, 'parcours' => null, 'ues' => 0];
        }
        $maquette = Maquette::reference($inscription->annee_academique, $parcours->id);
        if ($maquette === null) {
            return ['issue' => self::SANS_MAQUETTE, 'parcours' => $parcours->code, 'ues' => 0];
        }

        $creees = DB::transaction(function () use ($inscription, $parcours, $maquette, $auteur): int {
            if ($inscription->parcours_id === null) {
                $inscription->forceFill(['parcours_id' => $parcours->id])->save();
            }
            $lignes = $maquette->ues()->pluck('id')->map(fn (int $id): array => [
                'inscription_annuelle_id' => $inscription->id,
                'maquette_ue_id' => $id,
                'statut' => InscriptionPedagogique::INSCRIT,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();
            $creees = InscriptionPedagogique::query()->insertOrIgnore($lignes);
            if ($creees > 0) {
                activity('lmd')->causedBy($auteur)->performedOn($inscription)->event('inscription_pedagogique')
                    ->withProperties(['parcours' => $parcours->code, 'ues' => $creees])
                    ->log('Inscription pédagogique');
            }

            return $creees;
        });

        return ['issue' => self::INSCRITE, 'parcours' => $parcours->code, 'ues' => $creees];
    }

    /**
     * À la validation d'une maquette : inscrit les auditeurs de son année dont le
     * parcours est le sien (ou s'y résout). Rejouable sans doublon.
     *
     * @return array{inscrites: int, ues: int}
     */
    public function inscrireMaquette(Maquette $maquette, ?User $auteur = null): array
    {
        $bilan = ['inscrites' => 0, 'ues' => 0];
        if ($maquette->statut !== Maquette::VALIDEE) {
            return $bilan;
        }
        $niveau = $maquette->parcours()->value('niveau');
        InscriptionAnnuelle::query()->where('annee_academique', $maquette->annee_academique)->where('niveau', $niveau)
            ->where(fn ($q) => $q->whereNull('parcours_id')->orWhere('parcours_id', $maquette->parcours_id))
            ->with(['parcours', 'auditeur.candidature'])
            ->chunkById(200, function ($inscriptions) use ($maquette, $auteur, &$bilan): void {
                foreach ($inscriptions as $inscription) {
                    if ($this->parcours->pour($inscription)?->id !== $maquette->parcours_id) {
                        continue;
                    }
                    $r = $this->inscrire($inscription, $auteur);
                    if ($r['ues'] > 0) {
                        $bilan['inscrites']++;
                        $bilan['ues'] += $r['ues'];
                    }
                }
            });

        return $bilan;
    }

    /**
     * Change le parcours d'une inscription (option de M2) et refait l'inscription
     * pédagogique. Les UE de l'ancien parcours sont retirées : sans note au lot 4.
     *
     * @return array{issue: string, parcours: ?string, ues: int}
     *
     * @throws InvalidArgumentException motif absent, niveau différent, même parcours
     */
    public function changerParcours(InscriptionAnnuelle $inscription, Parcours $nouveau, User $auteur, string $motif): array
    {
        abort_unless($auteur->can(PermissionsLmd::CHANGER_PARCOURS), 403);
        $motif = trim($motif);
        if (mb_strlen($motif) < 10) {
            throw new InvalidArgumentException('Indiquez le motif du changement de parcours (10 caractères au moins).');
        }
        if ($nouveau->niveau !== $inscription->niveau) {
            throw new InvalidArgumentException('Ce parcours n\'est pas du niveau de l\'inscription.');
        }
        if ($inscription->parcours_id === $nouveau->id) {
            throw new InvalidArgumentException('L\'auditeur suit déjà ce parcours.');
        }

        $ancien = $inscription->parcours?->code;
        DB::transaction(function () use ($inscription, $nouveau, $auteur, $motif, $ancien): void {
            $inscription->inscriptionsPedagogiques()->delete();
            $inscription->forceFill(['parcours_id' => $nouveau->id])->save();
            activity('lmd')->causedBy($auteur)->performedOn($inscription)->event('parcours_change')
                ->withProperties(['ancien' => $ancien, 'nouveau' => $nouveau->code, 'motif' => $motif])
                ->log('Parcours changé');
        });

        return $this->inscrire($inscription->refresh(), null);
    }
}
