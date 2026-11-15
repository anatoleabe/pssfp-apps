<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accès à eCampus ouvert avant le paiement des droits d'inscription (spec
 * module 4, lot A1). Jamais rempli en masse : seul DeblocagesAcces l'écrit,
 * et un montant n'est jamais tenu pour payé tant qu'il n'est pas reçu.
 */
class DeblocageAcces extends Model
{
    use HasFactory;

    public const VIREMENT_ANNONCE = 'virement_annonce';

    public const PRISE_EN_CHARGE = 'prise_en_charge';

    public const MORATOIRE = 'moratoire';

    public const TYPES = [
        self::VIREMENT_ANNONCE => 'Virement annoncé',
        self::PRISE_EN_CHARGE => 'Prise en charge',
        self::MORATOIRE => 'Moratoire ou dérogation',
    ];

    public const EN_COURS = 'en_cours';

    public const REGULARISE = 'regularise';

    public const REVOQUE = 'revoque';

    public const STATUTS = [
        self::EN_COURS => 'En cours',
        self::REGULARISE => 'Régularisé',
        self::REVOQUE => 'Révoqué',
    ];

    protected $table = 'deblocages_acces';

    protected $casts = [
        'echeance' => 'immutable_date',
        'accorde_par' => 'integer',
        'clos_par' => 'integer',
        'clos_le' => 'datetime',
        'prolongations' => 'integer',
        'relances' => 'integer',
        'derniere_relance_le' => 'datetime',
        'engagement_id' => 'integer',
    ];

    protected $hidden = ['justificatif_path'];

    /** Même dossier et même règle que les bordereaux : un chemin choisi par le client n'est jamais signé. */
    public static function cheminPieceValide(?string $chemin): bool
    {
        return Versement::cheminJustificatifValide($chemin);
    }

    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    /** Engagement de prise en charge qui porte la lettre (lot A2) ; nul pour les autres cas. */
    public function engagement(): BelongsTo
    {
        return $this->belongsTo(EngagementPriseEnCharge::class, 'engagement_id');
    }

    /** Pièce du déblocage : la sienne, ou la lettre de son engagement (lot A2). */
    public function piece(): ?string
    {
        return $this->justificatif_path ?? $this->engagement?->lettre_path;
    }

    public function accordePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accorde_par');
    }

    public function closPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clos_par');
    }

    public function scopeEnCours(Builder $query): Builder
    {
        return $query->where('statut', self::EN_COURS);
    }

    /** En cours et échéance dépassée (le jour de l'échéance compte encore dans le délai). */
    public function scopeEchus(Builder $query): Builder
    {
        return $query->enCours()->where('echeance', '<', InscriptionAnnuelle::aujourdhui()->toDateString());
    }

    public function estEnCours(): bool
    {
        return $this->statut === self::EN_COURS;
    }

    public function estEchu(): bool
    {
        // Dates comparées en texte : l'échéance est un jour, sans fuseau.
        return $this->estEnCours() && $this->echeance->toDateString() < InscriptionAnnuelle::aujourdhui()->toDateString();
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
