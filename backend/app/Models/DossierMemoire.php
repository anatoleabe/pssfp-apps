<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Dossier de mémoire d'un auditeur autorisé à soutenir (D32). */
class DossierMemoire extends Model
{
    use HasUuids;
    use LogsActivity;

    public const STATUTS = ['en_redaction' => 'En rédaction', 'pret' => 'Prêt à soutenir', 'soutenu' => 'Soutenu', 'suspendu' => 'Suspendu', 'abandon' => 'Abandon'];

    /** Étapes et poids (D32), dans l'ordre. */
    public const ETAPES = [
        'debut' => ['Début de rédaction', 0],
        'cadrage' => ['Cadrage', 5],
        'depot_projet' => ['Dépôt du projet validé par les encadreurs', 15],
        'presoutenance1' => ['1re pré-soutenance', 30],
        'presoutenance2' => ['2e pré-soutenance', 30],
        'depot_final' => ['Dépôt final', 10],
        'soutenance' => ['Soutenance', 10],
    ];

    protected $table = 'dossiers_memoire';

    protected $fillable = ['theme', 'structure_accueil', 'stage_debut', 'stage_fin', 'encadreur_academique', 'encadreur_academique_id',
        'encadreur_professionnel', 'vague', 'date_soutenance'];

    protected $attributes = ['statut' => 'en_redaction'];

    protected $casts = [
        'stage_debut' => 'immutable_date',
        'stage_fin' => 'immutable_date',
        'date_soutenance' => 'immutable_date',
        'avancement_estime' => 'integer',
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
        return LogOptions::defaults()->useLogName('lmd')->logOnly([...$this->fillable, 'statut', 'statut_motif'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function inscriptionAnnuelle(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class);
    }

    public function encadreurCompte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encadreur_academique_id');
    }

    public function etapes(): HasMany
    {
        return $this->hasMany(EtapeMemoire::class)->orderBy('ordre');
    }

    public function suivis(): HasMany
    {
        return $this->hasMany(SuiviMemoire::class)->orderByDesc('date_suivi');
    }

    public function avis(): HasMany
    {
        return $this->hasMany(AvisMemoire::class)->orderByDesc('id');
    }

    public function rapports(): HasMany
    {
        return $this->hasMany(RapportSimilitude::class)->orderByDesc('date_rapport')->orderByDesc('id');
    }
}
