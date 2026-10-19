<?php

declare(strict_types=1);

namespace App\Services\Library\Import;

use App\Models\MotCle;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Préalables communs à biblio:import et biblio:import-orphans : l'utilisateur système « Import
 * Joomla » (déposant et approbateur des fiches importées) et le thésaurus de la correspondance.
 * Dry-run : simples lectures, rien n'est créé (ce qui manque est compté comme « serait créé »).
 */
final class ImportCatalogSetup
{
    private ?int $systemUserId = null;

    /** @var array<string, int> slug du thésaurus → id */
    private array $keywordIds = [];

    public function __construct(private readonly LegacyMapping $mapping, private readonly bool $dryRun) {}

    /** @return bool l'utilisateur système vient d'être créé (ou le serait, en dry-run) */
    public function ensureSystemUser(): bool
    {
        $user = User::query()->where('email', LegacyImporter::SYSTEM_USER_EMAIL)->first();
        if ($user === null && ! $this->dryRun) {
            // Aucun rôle, e-mail non vérifié, mot de passe aléatoire jamais communiqué : compte inutilisable.
            $user = User::query()->firstOrCreate(
                ['email' => LegacyImporter::SYSTEM_USER_EMAIL],
                ['name' => LegacyImporter::SYSTEM_USER_NAME, 'password' => Str::password(64)],
            );
        }
        $this->systemUserId = $user?->id;

        return $user === null || $user->wasRecentlyCreated;
    }

    /** @return int nombre de mots-clés créés (ou qui le seraient, en dry-run), parents d'abord */
    public function ensureThesaurus(): int
    {
        $created = 0;
        foreach ($this->mapping->thesaurus() as $ref) {
            $keyword = MotCle::query()->where('slug', $ref->slug)->first();
            if ($keyword === null && ! $this->dryRun) {
                $keyword = MotCle::query()->firstOrCreate(['slug' => $ref->slug], [
                    'label' => ['fr' => $ref->label],
                    'parent_id' => $ref->parentSlug === null ? null : ($this->keywordIds[$ref->parentSlug] ?? null),
                ]);
            }
            if ($keyword === null || $keyword->wasRecentlyCreated) {
                $created++;
            }
            if ($keyword !== null) {
                $this->keywordIds[$ref->slug] = $keyword->id;
            }
        }

        return $created;
    }

    /** Utilisateur système (null en dry-run s'il n'existe pas encore). */
    public function systemUserId(): ?int
    {
        return $this->systemUserId;
    }

    /** @return list<int> identifiants des mots-clés de la correspondance présents en base */
    public function keywordIdsFor(MappedCategory $mapped): array
    {
        return array_values(array_filter(array_map(
            fn (KeywordRef $keyword): ?int => $this->keywordIds[$keyword->slug] ?? null,
            $mapped->keywords,
        )));
    }
}
