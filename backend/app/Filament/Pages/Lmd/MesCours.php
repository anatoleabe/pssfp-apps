<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Models\User;
use App\Services\Lmd\EspaceEnseignant;
use App\Support\BelongsToModule;
use Filament\Pages\Page;

/** Séances et surveillances de l'enseignant connecté, en lecture seule (lot 29). */
#[BelongsToModule('lmd')]
class MesCours extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = GroupeNavigation::PLANIFICATION;

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Mes cours';

    protected static ?string $slug = 'lmd/mes-cours';

    protected static string $view = 'filament.lmd.mes-cours';

    public static function canAccess(): bool
    {
        return static::moduleAllows() && EspaceEnseignant::concerne(auth()->user());
    }

    /** Le compte connecté : aucun paramètre ne désigne un autre enseignant (spec R2). */
    public function enseignant(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return $u;
    }

    public function espace(): EspaceEnseignant
    {
        return app(EspaceEnseignant::class);
    }
}
