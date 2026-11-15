<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Journal de la fiche : l'auditeur, ses inscriptions, ses versements, ses
     * accès anticipés et son dossier. Pour l'affichage seulement (description,
     * auteur, date).
     *
     * @return Collection<int, Activity>
     */
    public function historique(int $limite = 100, bool $avecDossier = true): Collection
    {
        $inscriptions = $this->inscriptions()->pluck('id');
        $versements = Versement::query()->where('auditeur_id', $this->getKey())->pluck('id');
        $deblocages = DeblocageAcces::query()->whereIn('inscription_annuelle_id', $inscriptions)->pluck('id');

        return Activity::query()
            ->with('causer')
            ->where(function ($q) use ($inscriptions, $versements, $deblocages, $avecDossier): void {
                $q->where(fn ($s) => $s->where('subject_type', $this->getMorphClass())->where('subject_id', $this->getKey()))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new InscriptionAnnuelle)->getMorphClass())->whereIn('subject_id', $inscriptions))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new Versement)->getMorphClass())->whereIn('subject_id', $versements))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new DeblocageAcces)->getMorphClass())->whereIn('subject_id', $deblocages))
                    ->when($avecDossier, fn ($w) => $w->orWhere(fn ($s) => $s->where('subject_type', (new Candidature)->getMorphClass())->where('subject_id', $this->candidature_id)));
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

    /** Tous les versements du compte, toutes années, annulés compris (historique de la fiche). */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }

    /**
     * Ajoute la colonne calculée `avance` : versements valides moins imputations actives
     * (spec D1, §7.3). Exacte grâce au déclencheur de cohérence : un versement annulé n'a
     * aucune imputation active. SQL brut autorisé par l'ADR-0013 ; aucune valeur saisie.
     */
    public function scopeAvecAvance(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->getTable().'.*');
        }

        return $query->selectRaw(self::AVANCE.' AS avance');
    }

    private const AVANCE = '((SELECT COALESCE(SUM(v.montant), 0) FROM versements v WHERE v.auditeur_id = auditeurs.id AND v.annule_at IS NULL)'
        .' - (SELECT COALESCE(SUM(x.montant), 0) FROM imputations_versements x WHERE x.auditeur_id = auditeurs.id AND x.annule_at IS NULL))';

    /** Inscription de l'année académique la plus récente : celle qui commande l'accès. */
    public function inscriptionCourante(): HasOne
    {
        return $this->hasOne(InscriptionAnnuelle::class)->ofMany(['annee_academique' => 'max']);
    }

    /** Étiquettes libres, par ordre alphabétique (cf. App\Services\Scolarite\Etiquettes). */
    public function etiquettes(): BelongsToMany
    {
        return $this->belongsToMany(Etiquette::class, Etiquette::PIVOT)->withPivot(['ajoute_par', 'created_at'])->orderBy('etiquettes.nom');
    }
}
