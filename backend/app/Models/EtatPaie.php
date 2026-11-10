<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Journaux;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * État de paie mensuel des enseignants (D24) : brouillon → vérifié → [visé] → transmis → pris en charge → payé ;
 * rejet motivé avant la prise en charge ; état rectificatif ensuite.
 */
class EtatPaie extends Model
{
    use HasUuids;
    use LogsActivity;

    public const BROUILLON = 'brouillon';

    public const VERIFIE = 'verifie';

    public const VISE = 'vise';

    public const TRANSMIS = 'transmis';

    public const PRIS_EN_CHARGE = 'pris_en_charge';

    public const PAYE = 'paye';

    public const REJETE = 'rejete';

    public const STATUTS = [
        self::BROUILLON => 'Brouillon', self::VERIFIE => 'Vérifié', self::VISE => 'Visé', self::TRANSMIS => 'Transmis au service financier',
        self::PRIS_EN_CHARGE => 'Pris en charge', self::PAYE => 'Payé', self::REJETE => 'Rejeté',
    ];

    /** États qui se rejettent encore (avant la prise en charge). */
    public const REJETABLES = [self::BROUILLON, self::VERIFIE, self::VISE, self::TRANSMIS];

    protected $table = 'etats_paie';

    protected $fillable = [];

    protected $attributes = ['statut' => self::BROUILLON];

    protected $casts = [
        'periode_du' => 'immutable_date', 'periode_au' => 'immutable_date', 'paye_le' => 'immutable_date', 'ecartes' => 'array',
        'genere_at' => 'datetime', 'verifie_at' => 'datetime', 'vise_at' => 'datetime', 'transmis_at' => 'datetime', 'pris_en_charge_at' => 'datetime',
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
        return LogOptions::defaults()->useLogName(Journaux::PAIE)
            ->logOnly(['statut', 'reference_bordereau', 'paye_le', 'reference_paiement', 'motif_rejet'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneEtatPaie::class, 'etat_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function generePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'genere_par');
    }

    public function verifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifie_par');
    }

    public function visePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vise_par');
    }

    public function transmisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transmis_par');
    }

    public function prisEnChargePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pris_en_charge_par');
    }

    public function rectifie(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectificatif_de');
    }

    public function rectificatifs(): HasMany
    {
        return $this->hasMany(self::class, 'rectificatif_de');
    }

    public function mois(): string
    {
        return $this->periode_du->format('m/Y');
    }
}
