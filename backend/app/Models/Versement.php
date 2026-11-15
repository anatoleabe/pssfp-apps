<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Versement d'un auditeur, rattaché à son compte (`auditeur_id`, ADR-0013). Jamais
 * supprimé : une annulation renseigne les champs `annule_*`, et seul RegistreVersements
 * écrit ici. `inscription_annuelle_id` est l'inscription désignée à la saisie : elle sert
 * de référence au reçu et, pour un versement d'employeur, borne son imputation (§4 de la spec D1).
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

    public const PAYEUR_EMPLOYEUR = 'employeur';

    /**
     * Payeurs dont l'excédent reste réservé à l'inscription désignée (décisions 2 et 7) ;
     * « autre » se traite comme l'auditeur (décision du 15/11/2026).
     */
    public const PAYEURS_RESERVES = [self::PAYEUR_EMPLOYEUR];

    protected $fillable = [
        'auditeur_id',
        'inscription_annuelle_id',
        'montant',
        'date_versement',
        'mode',
        'reference',
        'payeur',
        'justificatif_path',
    ];

    protected $casts = [
        'auditeur_id' => 'integer',
        'montant' => 'integer',
        'date_versement' => 'immutable_date',
        'saisi_par' => 'integer',
        'annule_at' => 'datetime',
        'annule_par' => 'integer',
        'reglement_organisme_id' => 'integer',
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
            // \z et non $ : « $ » accepterait un saut de ligne final.
            && preg_match('#^'.preg_quote(self::DOSSIER_JUSTIFICATIFS, '#').'/[0-9A-Za-z]{26}\.(pdf|jpg|jpeg|png)\z#', $chemin) === 1;
    }

    /**
     * Une pièce du dossier des justificatifs ne sert qu'une fois : jamais le
     * bordereau d'un autre versement, la pièce d'un accès anticipé, la lettre
     * d'un engagement de prise en charge, la pièce d'un règlement (lot A2) ni le
     * scan d'une confirmation par la scolarité.
     */
    public static function pieceDejaJointe(string $chemin): bool
    {
        return self::query()->where('justificatif_path', $chemin)->exists()
            || ConfirmationVersement::query()->where('justificatif_path', $chemin)->exists()
            || DeblocageAcces::query()->where('justificatif_path', $chemin)->exists()
            || EngagementPriseEnCharge::query()->where('lettre_path', $chemin)->exists()
            || ReglementOrganisme::query()->where('piece_path', $chemin)->exists();
    }

    public static function normaliserReference(string $reference): string
    {
        return mb_strtoupper(trim($reference));
    }

    public static function libelleMode(?string $mode): string
    {
        return $mode === self::MODE_REPRISE ? 'Reprise du pointage' : (self::MODES[$mode] ?? (string) $mode);
    }

    /** Inscription désignée à la saisie. */
    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionAnnuelle::class, 'inscription_annuelle_id');
    }

    public function auditeur(): BelongsTo
    {
        return $this->belongsTo(Auditeur::class);
    }

    /** Parts du versement qui règlent des inscriptions, annulées comprises. */
    public function imputations(): HasMany
    {
        return $this->hasMany(ImputationVersement::class);
    }

    public function imputationsActives(): HasMany
    {
        return $this->hasMany(ImputationVersement::class)->whereNull('annule_at');
    }

    /** L'excédent d'un versement réservé n'est jamais imputé d'office sur une autre année. */
    public function estReserve(): bool
    {
        return in_array($this->payeur, self::PAYEURS_RESERVES, true);
    }

    /** Règlement d'organisme dont ce versement est une part (lot A2) ; il ne s'annule qu'avec lui. */
    public function reglement(): BelongsTo
    {
        return $this->belongsTo(ReglementOrganisme::class, 'reglement_organisme_id');
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

    /** Confirmation par la scolarité (15/11/2026) : seul l'argent confirmé rend l'auditeur « à jour ». */
    public function confirmation(): HasOne
    {
        return $this->hasOne(ConfirmationVersement::class);
    }
}
