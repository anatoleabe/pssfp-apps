<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ModulePermissionMap;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rôle back-office.
 *
 * Étend le modèle Spatie pour porter un libellé lisible et journaliser les
 * changements de périmètre — un rôle modifié, c'est un droit d'accès qui
 * bouge : ça doit se retrouver dans l'Activity Log.
 *
 * `name` reste l'identifiant technique (`hasRole('admin')`), jamais traduit.
 */
class Role extends SpatieRole
{
    use LogsActivity;

    /** Rôles structurants que l'interface interdit de renommer ou supprimer. */
    public const LOCKED = ModulePermissionMap::LOCKED_ROLES;

    protected $fillable = [
        'name',
        'label',
        'description',
        'guard_name',
    ];

    public function getDisplayNameAttribute(): string
    {
        return $this->label ?: $this->name;
    }

    public function isLocked(): bool
    {
        return in_array($this->name, self::LOCKED, true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('roles')
            ->logOnly(['name', 'label', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
