<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce d'une requête : déposée par l'auditeur ou réunie par l'agent (élément de vérification, D27). */
class RequetePiece extends Model
{
    public const UPDATED_AT = null;

    public const DOSSIER = 'lmd/requetes';

    public const NATURES = [
        'piece_auditeur' => 'Pièce de l\'auditeur',
        'photocopie_pv_notes' => 'Photocopie du PV des notes',
        'fiche_notes' => 'Fiche de notes',
        'avis_enseignant' => 'Avis de l\'enseignant',
        'copie' => 'Copie d\'examen',
        'emargement' => 'Liste d\'émargement',
        'autre' => 'Autre élément',
    ];

    protected $table = 'requete_pieces';

    protected $guarded = ['id'];

    public function requete(): BelongsTo
    {
        return $this->belongsTo(Requete::class);
    }

    /** Seuls les chemins du dossier des requêtes sont signés. */
    public static function cheminValide(?string $chemin): bool
    {
        return $chemin !== null && preg_match('#^'.preg_quote(self::DOSSIER, '#').'/[0-9A-Za-z]{26}\.(pdf|jpg|jpeg|png)$#', $chemin) === 1;
    }
}
