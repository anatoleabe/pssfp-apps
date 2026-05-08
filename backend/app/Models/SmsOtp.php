<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsOtp extends Model
{
    use HasFactory;

    public const PURPOSE_RESET_PIN = 'reset_pin';

    public const PURPOSE_VERIFY_PHONE = 'verify_phone';

    protected $fillable = [
        'phone_e164',
        'code_hash',
        'purpose',
        'attempts',
        'max_attempts',
        'expires_at',
        'used_at',
        'cancelled_at',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function scopeActiveFor(Builder $query, string $phone, string $purpose): Builder
    {
        return $query->where('phone_e164', $phone)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->latest('id');
    }
}
