<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Virement annoncé (spec virements-bordereaux §2) : hors compte tant qu'il n'est pas validé à sa date de paiement.
 * Seuls App\Services\Scolarite\VirementsAnnonces et RegistreVersements écrivent ici.
 */
class VirementAnnonce extends Model
{
    use HasUuids;

    public const EN_ATTENTE = 'en_attente';

    public const VALIDE = 'valide';

    public const ABANDONNE = 'abandonne';

    public const STATUTS = [self::EN_ATTENTE => 'En attente de crédit', self::VALIDE => 'Validé', self::ABANDONNE => 'Abandonné'];

    protected $table = 'virements_annonces';

    protected $fillable = [];

    protected $hidden = ['justificatif_path'];

    protected $casts = [
        'montant' => 'integer', 'date_ordre' => 'immutable_date', 'date_paiement' => 'immutable_date', 'traite_le' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function versement(): BelongsTo
    {
        return $this->belongsTo(Versement::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
    }
}
