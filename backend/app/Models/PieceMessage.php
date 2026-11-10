<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce jointe d'un message (lot 14b-1), rangée sur le disque privé `local`. Ajout seul. */
class PieceMessage extends Model
{
    public const DISQUE = 'local';

    public const DOSSIER = 'messagerie';

    public const TAILLE_MAX = 10485760;

    public const NOMBRE_MAX = 5;

    public const TYPES = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word',
    ];

    /** Extension imposée au fichier rangé et au nom de téléchargement, d'après le type lu dans le contenu. */
    public const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public $timestamps = false;

    protected $table = 'messages_pieces';

    protected $fillable = ['message_id', 'nom_fichier', 'type_mime', 'taille_octets', 'sha256', 'chemin'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
