<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versement d'un auditeur. Jamais supprimé : une annulation renseigne les champs
 * `annule_*`, et seul RegistreVersements écrit ici.
 */
class Versement extends Model
{
    use HasFactory;
    use HasUuids;

    public const MODE_REPRISE = 'reprise';

    /** Modes proposés aux agents. `reprise` en est exclu : réservé à la migration. */
    public const MODES = [
        'cremincam_agence' => 'Agence CREMINCAM',
        'virement' => 'Virement bancaire',
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
        'mtn_money' => 'MTN Mobile Money',
        'carte_visa' => 'Carte Visa / Mastercard',
        'autre' => 'Autre',
    ];

    public const PAYEURS = [
        'auditeur' => 'L\'auditeur',
        'employeur' => 'Son employeur',
        'autre' => 'Autre',
    ];

    protected $fillable = [
        'inscription_annuelle_id',
        'montant',
        'date_versement',
        'mode',
        'reference',
        'payeur',
        'justificatif_path',
    ];

    protected $casts = [
        'montant' => 'integer',
        'date_versement' => 'immutable_date',
        'saisi_par' => 'integer',
        'annule_at' => 'datetime',
        'annule_par' => 'integer',
    ];

    /** Le chemin privé ne sort jamais du back-office. */
    protected $hidden = ['justificatif_path'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public const DOSSIER_JUSTIFICATIFS = 'scolarite/justificatifs';

    /**
     * Seuls les fichiers déposés par le formulaire du versement sont acceptés :
     * même bucket que les pièces des candidats, un chemin choisi par le client
     * ouvrirait un lien signé vers n'importe quel dossier.
     */
    public static function cheminJustificatifValide(?string $chemin): bool
    {
        return $chemin !== null
            && preg_match('#^'.preg_quote(self::DOSSIER_JUSTIFICATIFS, '#').'/[0-9A-Za-z]{26}\.(pdf|jpg|jpeg|png)$#', $chemin) === 1;
    }

    public static function normaliserReference(string $reference): string
    {
        return mb_strtoupper(trim($reference));
    }

    public static function libelleMode(?string $mode): string
    {
        return $mode === self::MODE_REPRISE ? 'Reprise du pointage' : (self::MODES[$mode] ?? (string) $mode);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function annulePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    /** Reçus émis pour ce versement (Scolarité lot 4) ; un seul en vigueur. */
    public function recus(): HasMany
    {
        return $this->hasMany(RecuVersement::class);
    }

    public function recuEnVigueur(): ?RecuVersement
    {
        return $this->recus->first(fn (RecuVersement $r): bool => $r->annulation === null);
    }

    public function estAnnule(): bool
    {
        return $this->annule_at !== null;
    }
}
