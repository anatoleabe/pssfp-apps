<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Étiquette libre posée sur des auditeurs (« Campus B », « Président de
 * l'amicale »…). Toute écriture passe par App\Services\Scolarite\Etiquettes,
 * qui vérifie la permission et journalise.
 */
class Etiquette extends Model
{
    use HasFactory;

    public const PIVOT = 'auditeur_etiquette';

    public const NOM_MAX = 60;

    /** Couleurs Filament proposées, avec leur nom dans la charte du panneau. */
    public const COULEURS = [
        'gray' => 'Gris',
        'primary' => 'Prune',
        'info' => 'Bleu pétrole',
        'success' => 'Vert',
        'warning' => 'Ambre',
        'danger' => 'Rouge',
    ];

    /** Rien n'est rempli en masse : seul le service écrit. */
    protected $fillable = [];

    protected $casts = [
        'cree_par' => 'integer',
    ];

    protected static function booted(): void
    {
        // Clé d'unicité : le nom en minuscules (« Campus B » = « campus b »).
        static::saving(function (Etiquette $etiquette): void {
            $etiquette->cle = self::cle((string) $etiquette->nom);
        });
    }

    /** Nom débarrassé des espaces superflus. */
    public static function normaliser(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nom));
    }

    public static function cle(string $nom): string
    {
        return mb_strtolower(self::normaliser($nom));
    }

    public function auditeurs(): BelongsToMany
    {
        return $this->belongsToMany(Auditeur::class, self::PIVOT)->withPivot(['ajoute_par', 'created_at']);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
