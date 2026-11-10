<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Copie externe d'un message (lot 14b-1) : l'objet et l'invitation à se connecter, jamais le corps. */
class EnvoiMessage extends Model
{
    public const EMAIL = 'email';

    public const SMS = 'sms';

    public const CANAUX = [self::EMAIL, self::SMS];

    public const A_ENVOYER = 'a_envoyer';

    public const ENVOYE = 'envoye';

    public const ECHEC = 'echec';

    public const SANS_ADRESSE = 'sans_adresse';

    public const TENTATIVES_MAX = 3;

    public $timestamps = false;

    protected $table = 'envois_messages';

    protected $fillable = ['message_id', 'user_id', 'canal', 'adresse', 'texte', 'statut', 'tentatives', 'derniere_erreur', 'envoye_le'];

    protected $casts = ['envoye_le' => 'immutable_datetime', 'tentatives' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
