<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Покупатель личного кабинета (перенос legacy site.USERS).
 *
 * Пароль необязателен: способы входа лежат в user_identities, и вход
 * через VK или по коду из SMS заведёт пользователя вообще без пароля.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Способы входа: password / vk / phone. */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * Заказы кабинета: свои (user_id) плюс купленные до регистрации
     * на этот же e-mail — их пользователь видит после регистрации
     * («считается, что покупал он»).
     */
    public function orders(): Builder
    {
        return Order::query()
            ->where(fn ($q) => $q->where('user_id', $this->id)->orWhere('email', $this->email));
    }
}
