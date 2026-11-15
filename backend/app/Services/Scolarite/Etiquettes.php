<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\Auditeur;
use App\Models\Etiquette;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Étiquettes libres sur les auditeurs (« Campus B », « Président de
 * l'amicale »…), pour les retrouver dans la liste.
 *
 * Les voir n'exige que de voir les auditeurs ; tout geste (poser, retirer,
 * créer, renommer, supprimer) exige la permission, revérifiée ici, et finit au
 * journal « scolarite » : sur la fiche pour une pose ou un retrait, sur
 * l'étiquette pour sa création, sa modification ou sa suppression.
 */
final class Etiquettes
{
    public const PERMISSION = 'scolarite.etiquettes';

    public function __construct(private readonly ConnectionInterface $db) {}

    /** Étiquette qui porte déjà ce nom, à la casse et aux espaces près. */
    public static function existante(string $nom, ?int $sauf = null): ?Etiquette
    {
        return Etiquette::query()->where('cle', Etiquette::cle($nom))
            ->when($sauf !== null, fn ($q) => $q->whereKeyNot($sauf))
            ->first();
    }

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function creer(string $nom, string $couleur, User $auteur): Etiquette
    {
        $this->autoriser($auteur);
        $nom = $this->valider($nom, $couleur);

        try {
            return $this->db->transaction(function () use ($nom, $couleur, $auteur): Etiquette {
                $etiquette = new Etiquette;
                $etiquette->forceFill(['nom' => $nom, 'couleur' => $couleur, 'cree_par' => $auteur->id])->save();

                activity('scolarite')->causedBy($auteur)->performedOn($etiquette)->event('etiquette_creee')
                    ->withProperties(['nom' => $nom, 'couleur' => $couleur])
                    ->log('Étiquette créée : '.$nom);

                return $etiquette;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux créations simultanées du même nom : la base a tranché.
            throw $this->doublon(self::existante($nom)?->nom ?? $nom);
        }
    }

    /** @throws InvalidArgumentException message destiné à l'agent */
    public function modifier(Etiquette $etiquette, string $nom, string $couleur, User $auteur): Etiquette
    {
        $this->autoriser($auteur);
        $nom = $this->valider($nom, $couleur, $etiquette->id);

        try {
            $this->db->transaction(function () use ($etiquette, $nom, $couleur, $auteur): void {
                $verrouillee = $this->verrouiller($etiquette);
                $ancien = ['nom' => $verrouillee->nom, 'couleur' => $verrouillee->couleur];
                if ($ancien === ['nom' => $nom, 'couleur' => $couleur]) {
                    return;
                }
                $verrouillee->forceFill(['nom' => $nom, 'couleur' => $couleur])->save();

                activity('scolarite')->causedBy($auteur)->performedOn($verrouillee)->event('etiquette_modifiee')
                    ->withProperties(['ancien' => $ancien, 'nouveau' => ['nom' => $nom, 'couleur' => $couleur]])
                    ->log('Étiquette modifiée : '.$nom);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->doublon(self::existante($nom)?->nom ?? $nom);
        }

        return $etiquette->refresh();
    }

    /**
     * Supprime l'étiquette et la retire de tous ses auditeurs (chaque fiche le
     * garde dans son historique).
     *
     * @return int nombre d'auditeurs qui la portaient
     */
    public function supprimer(Etiquette $etiquette, User $auteur): int
    {
        $this->autoriser($auteur);

        return $this->db->transaction(function () use ($etiquette, $auteur): int {
            $verrouillee = $this->verrouiller($etiquette);
            $porteurs = $verrouillee->auditeurs()->get();
            foreach ($porteurs as $auditeur) {
                $this->journaliser($auditeur, $verrouillee, 'etiquette_retiree', 'Étiquette retirée (supprimée)', $auteur);
            }

            activity('scolarite')->causedBy($auteur)->performedOn($verrouillee)->event('etiquette_supprimee')
                ->withProperties(['nom' => $verrouillee->nom, 'couleur' => $verrouillee->couleur, 'auditeurs' => $porteurs->count()])
                ->log('Étiquette supprimée : '.$verrouillee->nom);
            $verrouillee->delete();

            return $porteurs->count();
        });
    }

    /**
     * Pose l'étiquette sur les auditeurs qui ne la portent pas encore.
     *
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return int nombre de poses réelles
     */
    public function poser(Collection $auditeurs, Etiquette $etiquette, User $auteur): int
    {
        $this->autoriser($auteur);

        return $this->db->transaction(function () use ($auditeurs, $etiquette, $auteur): int {
            $verrouillee = $this->verrouiller($etiquette);
            $deja = $verrouillee->auditeurs()->whereKey($this->ids($auditeurs))->pluck('auditeurs.id')->all();
            $nouveaux = Auditeur::query()->whereKey(array_diff($this->ids($auditeurs), $deja))->get();
            $this->attacher($verrouillee, $nouveaux, $auteur);

            return $nouveaux->count();
        });
    }

    /**
     * Retire l'étiquette des auditeurs qui la portent.
     *
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return int nombre de retraits réels
     */
    public function retirer(Collection $auditeurs, Etiquette $etiquette, User $auteur): int
    {
        $this->autoriser($auteur);

        return $this->db->transaction(function () use ($auditeurs, $etiquette, $auteur): int {
            $verrouillee = $this->verrouiller($etiquette);
            $porteurs = $verrouillee->auditeurs()->whereKey($this->ids($auditeurs))->get();
            $this->detacher($verrouillee, $porteurs, $auteur);

            return $porteurs->count();
        });
    }

    /**
     * Fixe les étiquettes d'une fiche : pose celles qui manquent, retire celles
     * qui ne sont plus choisies. Les identifiants inconnus sont ignorés.
     *
     * @param  list<int|string>  $etiquetteIds
     * @return array{posees: int, retirees: int}
     */
    public function definir(Auditeur $auditeur, array $etiquetteIds, User $auteur): array
    {
        $this->autoriser($auteur);

        return $this->db->transaction(function () use ($auditeur, $etiquetteIds, $auteur): array {
            $verrouille = Auditeur::query()->whereKey($auditeur->getKey())->lockForUpdate()->firstOrFail();
            $voulues = Etiquette::query()->whereKey(array_map('intval', $etiquetteIds))->get();
            $actuelles = $verrouille->etiquettes()->get();

            $aPoser = $voulues->diff($actuelles);
            $aRetirer = $actuelles->diff($voulues);
            foreach ($aPoser as $etiquette) {
                $this->attacher($etiquette, new EloquentCollection([$verrouille]), $auteur);
            }
            foreach ($aRetirer as $etiquette) {
                $this->detacher($etiquette, new EloquentCollection([$verrouille]), $auteur);
            }

            return ['posees' => $aPoser->count(), 'retirees' => $aRetirer->count()];
        });
    }

    /** @param EloquentCollection<int, Auditeur> $auditeurs */
    private function attacher(Etiquette $etiquette, EloquentCollection $auditeurs, User $auteur): void
    {
        if ($auditeurs->isEmpty()) {
            return;
        }
        $maintenant = now();
        // Sans erreur si une pose simultanée (fiche et sélection) a déjà inscrit la paire.
        $this->db->table(Etiquette::PIVOT)->insertOrIgnore($auditeurs->map(fn (Auditeur $a): array => [
            'auditeur_id' => $a->getKey(), 'etiquette_id' => $etiquette->getKey(), 'ajoute_par' => $auteur->id, 'created_at' => $maintenant,
        ])->all());
        foreach ($auditeurs as $auditeur) {
            $this->journaliser($auditeur, $etiquette, 'etiquette_posee', 'Étiquette posée', $auteur);
        }
    }

    /** @param EloquentCollection<int, Auditeur> $auditeurs */
    private function detacher(Etiquette $etiquette, EloquentCollection $auditeurs, User $auteur): void
    {
        if ($auditeurs->isEmpty()) {
            return;
        }
        $etiquette->auditeurs()->detach($auditeurs->modelKeys());
        foreach ($auditeurs as $auditeur) {
            $this->journaliser($auditeur, $etiquette, 'etiquette_retiree', 'Étiquette retirée', $auteur);
        }
    }

    private function journaliser(Auditeur $auditeur, Etiquette $etiquette, string $evenement, string $libelle, User $auteur): void
    {
        activity('scolarite')->causedBy($auteur)->performedOn($auditeur)->event($evenement)
            ->withProperties(['etiquette_id' => $etiquette->getKey(), 'etiquette' => $etiquette->nom])
            ->log($libelle.' : '.$etiquette->nom);
    }

    /** @throws InvalidArgumentException l'étiquette a été supprimée entre-temps */
    private function verrouiller(Etiquette $etiquette): Etiquette
    {
        return Etiquette::query()->whereKey($etiquette->getKey())->lockForUpdate()->first()
            ?? throw new InvalidArgumentException('Cette étiquette n\'existe plus : rechargez la page.');
    }

    /**
     * @param  Collection<int, Auditeur>  $auditeurs
     * @return list<int>
     */
    private function ids(Collection $auditeurs): array
    {
        return $auditeurs->map(fn (Auditeur $a): int => (int) $a->getKey())->unique()->values()->all();
    }

    /**
     * @return string le nom nettoyé
     *
     * @throws InvalidArgumentException
     */
    private function valider(string $nom, string $couleur, ?int $sauf = null): string
    {
        $nom = Etiquette::normaliser($nom);
        if ($nom === '') {
            throw new InvalidArgumentException('Indiquez le nom de l\'étiquette.');
        }
        if (mb_strlen($nom) > Etiquette::NOM_MAX) {
            throw new InvalidArgumentException('Le nom d\'une étiquette tient en '.Etiquette::NOM_MAX.' caractères au plus.');
        }
        if (! array_key_exists($couleur, Etiquette::COULEURS)) {
            throw new InvalidArgumentException('Choisissez une couleur de la liste.');
        }
        $existante = self::existante($nom, $sauf);
        if ($existante !== null) {
            throw $this->doublon($existante->nom);
        }

        return $nom;
    }

    /** @param string $nom nom de l'étiquette existante, tel qu'elle s'affiche */
    private function doublon(string $nom): InvalidArgumentException
    {
        return new InvalidArgumentException('L\'étiquette « '.$nom.' » existe déjà.');
    }

    private function autoriser(User $auteur): void
    {
        abort_unless($auteur->can(self::PERMISSION), 403);
    }
}
