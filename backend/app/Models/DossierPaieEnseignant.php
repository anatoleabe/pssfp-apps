<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Journaux;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Dossier de paie d'un enseignant (D23, D24) : statut, catégorie, service dû ; NIU, banque et compte de virement
 * (réservés au service financier). Le NIU et le compte sont chiffrés ; ni eux ni la banque ne vont au journal d'activité.
 */
class DossierPaieEnseignant extends Model
{
    use HasUuids;
    use LogsActivity;

    public const STATUTS = ['permanent' => 'Permanent', 'vacataire' => 'Vacataire', 'missionnaire' => 'Missionnaire', 'professionnel' => 'Professionnel'];

    public const CATEGORIES = [
        'professeur' => 'Professeur', 'maitre_conferences' => 'Maître de conférences', 'charge_cours' => 'Chargé de cours',
        'assistant' => 'Assistant', 'docteur' => 'Docteur', 'expert' => 'Expert',
    ];

    protected $table = 'dossiers_paie_enseignant';

    protected $fillable = [];

    protected $attributes = ['statut_paie' => 'vacataire', 'actif' => true];

    protected $casts = ['niu' => 'encrypted', 'compte_paiement' => 'encrypted', 'service_du_heures' => 'float', 'actif' => 'boolean'];

    /** Le NIU et le compte ne sont jamais sérialisés. */
    protected $hidden = ['niu', 'compte_paiement'];

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
            ->logOnly(['statut_paie', 'categorie', 'service_du_heures', 'actif'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /** Payable par virement : NIU et compte renseignés (D24). */
    public function estComplet(): bool
    {
        return filled($this->niu) && filled($this->compte_paiement);
    }

    /** Pièces manquantes pour être payé. @return list<string> */
    public function manques(): array
    {
        return array_values(array_filter([
            blank($this->niu) ? 'NIU' : null,
            blank($this->compte_paiement) ? 'compte bancaire' : null,
        ]));
    }
}
