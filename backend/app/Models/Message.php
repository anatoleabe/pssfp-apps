<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Message de la messagerie interne (lot 14b-1, D31). Ajout seul ; seul App\Services\Messagerie\Messagerie écrit ici. */
class Message extends Model
{
    use HasUuids;

    public const MESSAGE = 'message';

    public const RELANCE = 'relance';

    public const DEMANDE = 'demande';

    public const DECISION = 'decision';

    public const NOTIFICATION = 'notification';

    public const CATEGORIES = [
        self::MESSAGE => 'Message', self::RELANCE => 'Relance', self::DEMANDE => 'Demande',
        self::DECISION => 'Décision', self::NOTIFICATION => 'Notification',
    ];

    /** Catégories envoyées par le système, sans expéditeur. */
    public const DU_SYSTEME = [self::RELANCE, self::NOTIFICATION];

    public const NORMALE = 'normale';

    public const HAUTE = 'haute';

    public const URGENTE = 'urgente';

    public const PRIORITES = [self::NORMALE => 'Normale', self::HAUTE => 'Haute', self::URGENTE => 'Urgente'];

    public const OBJET_MAX = 200;

    public const CORPS_MAX = 10000;

    public $timestamps = false;

    protected $table = 'messages';

    protected $fillable = ['fil', 'expediteur_id', 'categorie', 'priorite', 'objet', 'corps', 'reponse_a', 'envoye_le'];

    protected $casts = ['envoye_le' => 'immutable_datetime'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reponse_a');
    }

    public function destinataires(): HasMany
    {
        return $this->hasMany(MessageDestinataire::class);
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(PieceMessage::class);
    }

    public function envois(): HasMany
    {
        return $this->hasMany(EnvoiMessage::class);
    }

    public function estDuSysteme(): bool
    {
        return $this->expediteur_id === null && in_array($this->categorie, self::DU_SYSTEME, true);
    }
}
