<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Destinataire d'un message (lot 14b-1) : seules la lecture et l'archivage changent. */
class MessageDestinataire extends Model
{
    public $timestamps = false;

    protected $table = 'messages_destinataires';

    protected $fillable = ['message_id', 'user_id', 'lu_le', 'archive_le'];

    protected $casts = ['lu_le' => 'immutable_datetime', 'archive_le' => 'immutable_datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
