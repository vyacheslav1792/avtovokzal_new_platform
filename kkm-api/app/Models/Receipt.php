<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'raw_response' => 'array',
        'registered_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';       // отправлен в ККТ, результата ещё нет

    public const STATUS_REGISTERED = 'registered'; // чек фискализирован

    public const STATUS_ERROR = 'error';           // ККТ вернула ошибку
}
