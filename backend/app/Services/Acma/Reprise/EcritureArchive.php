<?php

declare(strict_types=1);

namespace App\Services\Acma\Reprise;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Écriture idempotente d'une table d'archive (spec du lot 15, §10.4), par lots et par clé naturelle :
 * - ligne nouvelle : insérée ;
 * - empreinte de la source inchangée : rien n'est écrit ;
 * - empreinte différente, ou ligne revenue dans la source : mise à jour ;
 * - ligne disparue de la source : `retire_de_la_source_at` posé, jamais supprimée.
 *
 * Les colonnes absentes des lignes fournies (lien d'un dossier, recalculs) ne sont jamais touchées.
 */
final class EcritureArchive
{
    private const LOT = 500;

    /** @var array<string, true> */
    private array $vues = [];

    /** @var list<array<string, mixed>> */
    private array $tampon = [];

    /** @var array{lues: int, inserees: int, modifiees: int, inchangees: int, retirees: int} */
    private array $comptes = ['lues' => 0, 'inserees' => 0, 'modifiees' => 0, 'inchangees' => 0, 'retirees' => 0];

    public function __construct(
        private readonly string $table,
        private readonly string $cle,
        private readonly CarbonInterface $maintenant,
        private readonly bool $avecUuid = false,
    ) {}

    /** @param  array<string, mixed>  $ligne  colonnes de la source (clé comprise), sans horodatage */
    public function ajouter(array $ligne): void
    {
        $this->tampon[] = $ligne;
        if (count($this->tampon) >= self::LOT) {
            $this->vider();
        }
    }

    /** Écrit le reste, marque les lignes disparues, et rend les comptes. @return array<string, int> */
    public function terminer(): array
    {
        $this->vider();
        $absents = [];
        foreach (DB::table($this->table)->whereNull('retire_de_la_source_at')->select(['id', $this->cle])->lazyById(2000) as $r) {
            if (! isset($this->vues['k'.$r->{$this->cle}])) {
                $absents[] = $r->id;
            }
        }
        foreach (array_chunk($absents, 1000) as $ids) {
            DB::table($this->table)->whereIn('id', $ids)->update(['retire_de_la_source_at' => $this->maintenant, 'updated_at' => $this->maintenant]);
        }
        $this->comptes['retirees'] = count($absents);

        return $this->comptes;
    }

    private function vider(): void
    {
        if ($this->tampon === []) {
            return;
        }
        $cles = array_map(fn (array $l): string => (string) $l[$this->cle], $this->tampon);
        $existants = [];
        foreach (DB::table($this->table)->whereIn($this->cle, $cles)->get([$this->cle, 'empreinte_source', 'retire_de_la_source_at']) as $e) {
            $existants['k'.$e->{$this->cle}] = $e;
        }

        $aEcrire = [];
        foreach ($this->tampon as $ligne) {
            $k = 'k'.$ligne[$this->cle];
            $this->comptes['lues']++;
            if (isset($this->vues[$k])) {
                throw new \LogicException("Clé {$this->cle} en double dans la source de {$this->table}.");
            }
            $this->vues[$k] = true;
            $empreinte = self::empreinte($ligne);
            $existant = $existants[$k] ?? null;
            if ($existant !== null && $existant->empreinte_source === $empreinte && $existant->retire_de_la_source_at === null) {
                $this->comptes['inchangees']++;

                continue;
            }
            $this->comptes[$existant === null ? 'inserees' : 'modifiees']++;
            $aEcrire[] = $ligne + [
                'empreinte_source' => $empreinte,
                'retire_de_la_source_at' => null,
                'created_at' => $this->maintenant,
                'updated_at' => $this->maintenant,
            ] + ($this->avecUuid ? ['uuid' => (string) Str::uuid()] : []);
        }
        $this->tampon = [];
        if ($aEcrire !== []) {
            $miseAJour = array_values(array_diff(array_keys($aEcrire[0]), [$this->cle, 'uuid', 'created_at']));
            DB::table($this->table)->upsert($aEcrire, [$this->cle], $miseAJour);
        }
    }

    /** @param  array<string, mixed>  $ligne */
    public static function empreinte(array $ligne): string
    {
        ksort($ligne);

        return hash('sha256', json_encode($ligne, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }
}
