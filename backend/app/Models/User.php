<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\VerrouillageComptes;
use App\Support\Journaux;
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
     * Rôles qui n'ouvrent jamais le panneau d'administration, quel que soit
     * leur périmètre.
     *
     * `candidat` est le rôle des comptes créés par apply.pssfp.org. (`teacher`
     * et `auditor`, rôles d'API jamais attribués, ont été retirés le 10/11/2026.)
     *
     * @var list<string>
     */
    public const NON_PANEL_ROLES = [
        'candidat',
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
            'verrouille_le' => 'datetime',
            'verrouille_jusqua' => 'datetime',
            'echecs_connexion' => 'integer',
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

        if (! $this->hasBackOfficePerimeter()) {
            return false;
        }

        // Lot 14d-2 : un compte verrouillé (échecs répétés) n'ouvre plus le panneau, sessions en cours comprises.
        if (VerrouillageComptes::estVerrouille($this)) {
            return false;
        }

        // Double authentification obligatoire : le compte entre, mais ExigerDoubleAuthentification le conduit sur
        // « Ma sécurité » tant qu'il ne l'a pas activée (lot 14d-4 ; avant, il était refusé sans pouvoir l'activer).

        return true;
    }

    /**
     * L'accès au panneau ne s'appuie plus sur une liste de rôles figée mais sur
     * le périmètre réel : tout rôle back-office porteur d'au moins une
     * permission ouvre l'admin. Un rôle créé depuis l'écran des rôles est donc
     * opérationnel sans toucher au code ; un rôle vidé de son périmètre ferme
     * la porte à ses titulaires.
     */
    public function hasBackOfficePerimeter(): bool
    {
        return $this->roles()
            ->whereNotIn('name', self::NON_PANEL_ROLES)
            ->whereHas('permissions')
            ->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName(Journaux::COMPTES)
            ->logOnly(['name', 'email', 'phone_e164'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function candidatures(): HasMany
    {
        return $this->hasMany(Candidature::class);
    }
}
