<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Calendrier d'une année académique, versionné (D18, D21) : brouillon → soumis → signé → publié ; remplacé par une version suivante. */
class CalendrierAcademique extends Model
{
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const SOUMIS = 'soumis';

    public const SIGNE = 'signe';

    public const PUBLIE = 'publie';

    public const REMPLACE = 'remplace';

    public const STATUTS = [self::BROUILLON => 'Brouillon', self::SOUMIS => 'Soumis à la signature', self::SIGNE => 'Signé',
        self::PUBLIE => 'Publié', self::REMPLACE => 'Remplacé'];

    protected $table = 'calendriers_academiques';

    protected $fillable = ['annee_academique', 'date_debut', 'date_fin', 'acte_validation'];

    protected $attributes = ['statut' => self::BROUILLON];

    protected $casts = [
        'date_debut' => 'immutable_date',
        'date_fin' => 'immutable_date',
        'signe_le' => 'immutable_date',
        'soumis_at' => 'datetime',
        'reference_figee_at' => 'datetime',
        'publie_at' => 'datetime',
        'version' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['statut', 'date_debut', 'date_fin', 'acte_validation', 'signataire', 'signe_le'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(EvenementCalendrier::class, 'calendrier_id');
    }

    public function libelle(): string
    {
        return 'Calendrier '.$this->annee_academique.' — version '.$this->version;
    }

    /** Signé ou publié : les dates ne se modifient plus que par réajustement motivé (D20). */
    public function estSigne(): bool
    {
        return in_array($this->statut, [self::SIGNE, self::PUBLIE], true);
    }

    public function estModifiable(): bool
    {
        return $this->statut !== self::REMPLACE && $this->statut !== self::SOUMIS;
    }
}
