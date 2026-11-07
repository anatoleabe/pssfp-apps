<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Jury désigné par note de service (D17) : président, vice-présidents, rapporteur, membres.
 * Sa composition est figée dès l'ouverture d'une séance de délibération (D37).
 */
class Jury extends Model
{
    use LogsActivity;

    public const NATURES = [
        'deliberation' => 'Délibération (session normale)',
        'rattrapage' => 'Délibération (rattrapage)',
        'synthese_memoires' => 'Synthèse des notes de mémoire',
        'requetes' => 'Jury des requêtes',
        'special' => 'Jury spécial',
    ];

    /** Dossier des notes de service numérisées, dans le stockage privé des pièces. */
    public const DOSSIER_ACTES = 'lmd/actes-jury';

    protected $table = 'jurys';

    protected $fillable = ['annee_academique', 'niveau', 'parcours_id', 'nature', 'objet', 'acte_reference', 'acte_date', 'acte_signataire', 'seance_le'];

    protected $casts = [
        'acte_date' => 'immutable_date',
        'seance_le' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('lmd')->logOnly([...$this->fillable, 'acte_piece_path'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function membres(): HasMany
    {
        return $this->hasMany(MembreJury::class)->orderByRaw("CASE role WHEN 'president' THEN 1 WHEN 'vice_president' THEN 2 WHEN 'rapporteur' THEN 3 ELSE 4 END")->orderBy('rang')->orderBy('id');
    }

    public function parcours(): BelongsTo
    {
        return $this->belongsTo(Parcours::class);
    }

    public function deliberations(): HasMany
    {
        return $this->hasMany(Deliberation::class);
    }

    /** Composition figée : une séance a été ouverte sur une de ses délibérations (D37). */
    public function compositionFigee(): bool
    {
        return $this->deliberations()->whereNotNull('seance_ouverte_at')->exists();
    }

    /** Seuls les chemins du dossier des actes sont signés (l'état d'un champ de fichier se modifie depuis le navigateur). */
    public static function cheminActeValide(?string $chemin): bool
    {
        return $chemin !== null
            && preg_match('#^'.preg_quote(self::DOSSIER_ACTES, '#').'/[0-9A-Za-z]{26}\.pdf$#', $chemin) === 1;
    }

    public function libelle(): string
    {
        return $this->objet.' — '.$this->acte_reference;
    }
}
