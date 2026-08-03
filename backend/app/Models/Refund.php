<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'money_refunded_at' => 'datetime',
    ];

    public const STATUS_REFUNDED = 'refunded';           // билет аннулирован на станции

    public const STATUS_MONEY_REFUNDED = 'money_refunded'; // платёж возвращён банком

    public const STATUS_BANK_FAILED = 'bank_failed';     // вернёт бухгалтерия вручную

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
