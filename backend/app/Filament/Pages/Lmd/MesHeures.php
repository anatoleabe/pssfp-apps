<?php

declare(strict_types=1);

namespace App\Filament\Pages\Lmd;

use App\Filament\Concerns\ChecksModuleAccess;
use App\Filament\Navigation\GroupeNavigation;
use App\Models\User;
use App\Services\Lmd\EspaceEnseignant;
use App\Support\BelongsToModule;
use Filament\Pages\Page;

/** Heures de l'enseignant connecté et leur mise en paie, en lecture seule (lot 29). */
#[BelongsToModule('lmd')]
class MesHeures extends Page
{
    use ChecksModuleAccess;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = GroupeNavigation::ENSEIGNANTS_PAIE;

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Mes heures';

    protected static ?string $slug = 'lmd/mes-heures';

    protected static string $view = 'filament.lmd.mes-heures';

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
