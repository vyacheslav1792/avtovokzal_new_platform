<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Способ входа пользователя. Сейчас используется только 'password',
 * строки 'vk' и 'phone' добавятся при подключении соответствующих
 * авторизаций — схема менять не потребуется.
 */
class UserIdentity extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'last_login_at' => 'datetime',
    ];

    public const PROVIDER_PASSWORD = 'password';

    public const PROVIDER_VK = 'vk';

    public const PROVIDER_PHONE = 'phone';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
