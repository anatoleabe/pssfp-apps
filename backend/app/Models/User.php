<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

final class User extends Authenticatable implements FilamentUser, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * Rôles autorisés à ouvrir le panneau d'administration Filament.
     *
     * `candidat`, `teacher` et `auditor` en sont volontairement exclus : ce sont
     * des rôles d'API / de futurs espaces dédiés, pas des rôles back-office.
     *
     * @var list<string>
     */
    public const PANEL_ROLES = [
        'super_admin',
        'admin',
        'editor',
        'librarian',
        'admission_committee',
        'receptionniste',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_e164',
        'phone_country',
        'date_naissance',
        // Piloté par UserResource : un compte agent créé par l'administration
        // est actif d'emblée, sans parcours de vérification d'email.
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'date_naissance' => 'date',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        if (! $this->hasAnyRole(self::PANEL_ROLES)) {
            return false;
        }

        if (config('pssfp.filament.require_2fa', true) && ! $this->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone_e164'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class);
    }
}
