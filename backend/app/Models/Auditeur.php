<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Auditeur du programme, à partir de l'attribution de son matricule.
 *
 * Ancrage de la vie académique (ADR-0010). Le compte, l'identité, le matricule
 * et le mot de passe FOAD se lisent à travers la candidature : aucune copie, car
 * `AjoutRetenu::corrigerTelephone` peut changer le compte d'un dossier.
 */
class Auditeur extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUT_ACTIF = 'actif';

    public const STATUT_SUSPENDU = 'suspendu';

    public const STATUT_ABANDON = 'abandon';

    public const STATUT_EXCLU = 'exclu';

    public const STATUT_DIPLOME = 'diplome';

    public const STATUTS = [
        self::STATUT_ACTIF => 'Actif',
        self::STATUT_SUSPENDU => 'Suspendu',
        self::STATUT_ABANDON => 'Abandon',
        self::STATUT_EXCLU => 'Exclu',
        self::STATUT_DIPLOME => 'Diplômé',
    ];

    /** Le statut n'est jamais rempli en masse : seul StatutAuditeur le pose. */
    protected $fillable = ['candidature_id'];

    protected $casts = [
        'statut_depuis' => 'immutable_date',
        'statut_par' => 'integer',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Journal de la fiche : l'auditeur, ses inscriptions, ses versements et son
     * dossier. Pour l'affichage seulement (description, auteur, date).
     *
     * @return Collection<int, Activity>
     */
    public function historique(int $limite = 100): Collection
    {
        $inscriptions = $this->inscriptions()->pluck('id');
        $versements = Versement::query()->whereIn('inscription_annuelle_id', $inscriptions)->pluck('id');

        return Activity::query()
            ->with('causer')
            ->where(function ($q) use ($inscriptions, $versements): void {
                $q->where(fn ($s) => $s->where('subject_type', $this->getMorphClass())->where('subject_id', $this->getKey()))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new InscriptionAnnuelle)->getMorphClass())->whereIn('subject_id', $inscriptions))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new Versement)->getMorphClass())->whereIn('subject_id', $versements))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new Candidature)->getMorphClass())->where('subject_id', $this->candidature_id));
            })
            ->latest('id')
            ->limit($limite)
            ->get();
    }

    public function statutModifiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'statut_par');
    }

    public function candidature(): BelongsTo
    {
        return $this->belongsTo(Candidature::class);
    }

    public function inscriptions(): HasMany
    {
        return $this->hasMany(InscriptionAnnuelle::class);
    }

    /** Tous les versements, toutes années, annulés compris (historique de la fiche). */
    public function versements(): HasManyThrough
    {
        return $this->hasManyThrough(Versement::class, InscriptionAnnuelle::class);
    }

    /** Inscription de l'année académique la plus récente : celle qui commande l'accès. */
    public function inscriptionCourante(): HasOne
    {
        return $this->hasOne(InscriptionAnnuelle::class)->ofMany(['annee_academique' => 'max']);
    }
}
