<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\Auth\Authenticatable;
use ReflectionClass;

/**
 * Point unique du cloisonnement du back-office par module.
 *
 * Un compte n'ouvre les écrans d'un module que s'il porte au moins une
 * permission de ce module (lecture, gestion ou action sensible, autonomes
 * comprises — cf. ModulePermissionMap::modulePermissions()). Le contrôle
 * s'ajoute aux Policies, il ne les remplace jamais : un rôle « bibliothèque »
 * seul ne voit ni les pages du site, ni les candidatures, ni l'administration,
 * même si une Policy venait un jour à être trop large.
 *
 * Les écrans déclarent leur module par l'attribut #[BelongsToModule] ; les
 * écrans fournis par Filament, qu'on ne peut pas annoter, sont rattachés ici.
 */
final class ModuleAccess
{
    /** Socle commun à tout compte du panneau : tableau de bord, profil. */
    public const CORE = 'core';

    /**
     * Écrans de Filament enregistrés dans le panneau : pas d'attribut
     * possible, rattachement explicite. Seul le socle commun est admis.
     *
     * @var array<class-string, string>
     */
    private const FRAMEWORK_SCREENS = [
        Dashboard::class => self::CORE,
        AccountWidget::class => self::CORE,
    ];

    /** @var array<class-string, ?string> */
    private static array $resolved = [];

    /** Module déclaré par un écran, ou null s'il n'en déclare aucun. */
    public static function moduleOf(string $screen): ?string
    {
        if (array_key_exists($screen, self::$resolved)) {
            return self::$resolved[$screen];
        }

        $module = self::FRAMEWORK_SCREENS[$screen] ?? null;

        if ($module === null && class_exists($screen)) {
            $attribute = (new ReflectionClass($screen))->getAttributes(BelongsToModule::class)[0] ?? null;
            $module = $attribute?->newInstance()->module;
        }

        return self::$resolved[$screen] = $module;
    }

    /**
     * L'utilisateur a-t-il accès au module ?
     *
     * Refuse un module inconnu (faute de frappe dans un attribut) : mieux vaut
     * un écran fermé qu'un écran ouvert à tous.
     */
    public static function allows(?Authenticatable $user, string $module): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($module === self::CORE) {
            return true;
        }

        if (! array_key_exists($module, ModulePermissionMap::modules())) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->intersect(ModulePermissionMap::modulePermissions($module))
            ->isNotEmpty();
    }

    /** L'utilisateur peut-il ouvrir cet écran, au regard de son module ? */
    public static function allowsScreen(?Authenticatable $user, string $screen): bool
    {
        $module = self::moduleOf($screen);

        return $module !== null && self::allows($user, $module);
    }

    /**
     * Modules ouverts à l'utilisateur (hors socle commun).
     *
     * @return list<string>
     */
    public static function accessibleModules(?Authenticatable $user): array
    {
        return array_values(array_filter(
            array_keys(ModulePermissionMap::modules()),
            fn (string $module): bool => self::allows($user, $module),
        ));
    }
}
