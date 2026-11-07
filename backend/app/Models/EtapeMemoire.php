<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Étape d'un mémoire (D32) : datée, pondérée ; à faire, déposée, validée, ajournée, dispensée. */
class EtapeMemoire extends Model
{
    public const STATUTS = ['a_faire' => 'À faire', 'depose' => 'Déposée', 'valide' => 'Validée', 'ajourne' => 'Ajournée', 'dispense' => 'Dispensée'];

    public const DOSSIER = 'lmd/memoires';

    protected $table = 'etapes_memoire';

    protected $guarded = ['id'];

    protected $casts = [
        'date_prevue' => 'immutable_date',
        'realisee_at' => 'datetime',
        'retard_autorise_at' => 'datetime',
        'note' => 'decimal:2',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(DossierMemoire::class, 'dossier_memoire_id');
    }

    public function libelle(): string
    {
        return DossierMemoire::ETAPES[$this->etape][0] ?? $this->etape;
    }

    /** Validée après sa date prévue, sans retard autorisé (D34). */
    public function enRetard(): bool
    {
        return $this->statut === 'valide' && $this->date_prevue !== null && $this->realisee_at !== null
            && $this->realisee_at->timezone('Africa/Douala')->startOfDay()->gt($this->date_prevue) && $this->retard_autorise_at === null;
    }

    /** Non faite et dont la date prévue est passée, sans retard autorisé. */
    public function enAttenteDepassee(): bool
    {
        return ! in_array($this->statut, ['valide', 'dispense'], true) && $this->date_prevue !== null && $this->retard_autorise_at === null
            && now('Africa/Douala')->startOfDay()->gt($this->date_prevue);
    }
}
