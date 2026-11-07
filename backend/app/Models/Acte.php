<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Acte produit à partir d'un événement du calendrier (D18) : à produire → brouillon → soumis → signé → diffusé ; annulé. */
class Acte extends Model
{
    use HasUuids;
    use LogsActivity;

    public const A_PRODUIRE = 'a_produire';

    public const BROUILLON = 'brouillon';

    public const SOUMIS = 'soumis';

    public const SIGNE = 'signe';

    public const DIFFUSE = 'diffuse';

    public const ANNULE = 'annule';

    public const STATUTS = [self::A_PRODUIRE => 'À produire', self::BROUILLON => 'Brouillon', self::SOUMIS => 'Soumis à la signature',
        self::SIGNE => 'Signé', self::DIFFUSE => 'Diffusé', self::ANNULE => 'Annulé'];

    /** Statuts d'un acte encore à produire : son échéance suit l'événement et il entre dans le suivi. */
    public const EN_COURS = [self::A_PRODUIRE, self::BROUILLON, self::SOUMIS];

    /** Horizon du tableau de suivi (D18). */
    public const HORIZON_JOURS = 15;

    public const ALERTES = ['en_retard' => 'En retard', 'a_traiter' => 'À traiter (15 jours)', 'ok' => 'Sans alerte'];

    public const DOSSIER = 'lmd/actes';

    protected $table = 'actes';

    protected $fillable = [];

    protected $attributes = ['statut' => self::A_PRODUIRE];

    protected $casts = [
        'echeance' => 'immutable_date',
        'date_signature' => 'immutable_date',
        'redige_at' => 'datetime',
        'soumis_at' => 'datetime',
        'signature_at' => 'datetime',
        'diffuse_at' => 'datetime',
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
        return LogOptions::defaults()->useLogName('lmd')->logOnly(['statut', 'echeance', 'numero', 'date_signature', 'signataire', 'motif'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(ModeleActe::class, 'modele_id');
    }

    public function evenement(): BelongsTo
    {
        return $this->belongsTo(EvenementCalendrier::class, 'evenement_id');
    }

    public function estEnCours(): bool
    {
        return in_array($this->statut, self::EN_COURS, true);
    }

    /** Alerte du tableau de suivi : en retard (échéance passée), à traiter (dans les 15 jours) ou sans alerte. */
    public function alerte(): string
    {
        if (! $this->estEnCours()) {
            return 'ok';
        }
        $aujourdhui = now('Africa/Douala')->startOfDay();

        return match (true) {
            $this->echeance->lt($aujourdhui) => 'en_retard',
            $this->echeance->lte($aujourdhui->copy()->addDays(self::HORIZON_JOURS)) => 'a_traiter',
            default => 'ok',
        };
    }
}
