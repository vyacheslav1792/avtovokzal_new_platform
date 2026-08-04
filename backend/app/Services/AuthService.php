<?php

namespace App\Services;

use App\Mail\EmailConfirmMail;
use App\Mail\PasswordResetMail;
use App\Mail\RegistrationInviteMail;
use App\Models\AuthToken;
use App\Models\BlockedEmail;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Личный кабинет: регистрация, вход, сброс и смена пароля
 * (перенос legacy url/register.php, login.php, forgot.php, newpass.php,
 * change.php — с bcrypt вместо md5 и без хранения пароля открытым текстом).
 *
 * Способы входа лежат в user_identities: пароль ('password') и VK ID
 * ('vk'); позже добавится 'phone' (вход по коду из SMS) — тем же
 * методом issueToken(), без переделки схемы.
 */
class AuthService
{
    /**
     * Регистрация по e-mail и паролю. Токен приглашения (ссылка из письма
     * после первой покупки) необязателен — с ним e-mail сразу считается
     * подтверждённым.
     *
     * @return array{user: User, token: string}
     */
    public function register(array $data, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($data['email']);
        $this->assertNotBlocked($email, 'регистрация невозможна');
        $password = $this->normalizePassword($data['password']);
        $phone = Phone::normalize($data['phone'] ?? null);

        if (! $phone) {
            throw ValidationException::withMessages([
                'phone' => 'Укажите корректный номер телефона, например +7 910 000-00-00',
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => "Пользователь с e-mail {$email} уже зарегистрирован. "
                    .'Если вы не помните пароль — воспользуйтесь восстановлением пароля.',
            ]);
        }

        // Приглашение подтверждает владение почтой: ссылку получили письмом.
        $invite = isset($data['invite_token'])
            ? AuthToken::findValid(AuthToken::TYPE_INVITE, $data['invite_token'])
            : null;

        $verified = $invite && $invite->email === $email;

        $user = DB::transaction(function () use ($email, $password, $phone, $verified, $invite) {
            $user = User::create([
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
                'email_verified_at' => $verified ? now() : null,
                'last_login_at' => now(),
            ]);

            $user->identities()->create([
                'provider' => UserIdentity::PROVIDER_PASSWORD,
                'provider_user_id' => $email,
                'last_login_at' => now(),
            ]);

            $invite?->markUsed();
            $this->claimOrders($user);

            return $user;
        });

        return ['user' => $user, 'token' => $this->issueToken($user, $ip)];
    }

    /**
     * Вход по e-mail и паролю.
     *
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        $user = User::where('email', $email)->first();

        // trim пароля — как при регистрации: пробел, «прилипший» при
        // копировании, не должен мешать войти.
        if (! $user || ! $user->password || ! Hash::check(trim($password), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Пользователь с таким e-mail не зарегистрирован или пароль неверен',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $user->identities()
            ->where('provider', UserIdentity::PROVIDER_PASSWORD)
            ->update(['last_login_at' => now()]);

        return ['user' => $user, 'token' => $this->issueToken($user, $ip)];
    }

    /**
     * Вход через VK ID. Профиль уже получен от VK (VkIdClient::complete).
     *
     * Три случая:
     *  1. этот VK уже привязан — просто впускаем;
     *  2. VK отдал e-mail, и такой пользователь есть — привязываем VK к нему
     *     (адрес подтверждён самим VK, поэтому владение доказано и второй
     *     кабинет с теми же покупками не заводится);
     *  3. иначе заводим нового. E-mail может и не прийти — пользователь
     *     остаётся без него, добавить предложим потом (attachEmail).
     *
     * @param  array{user_id: string, first_name?: ?string, last_name?: ?string, email?: ?string, phone?: ?string, avatar?: ?string}  $profile
     * @return array{user: User, token: string, created: bool}
     */
    public function loginWithVk(array $profile, ?string $ip = null): array
    {
        $vkId = trim($profile['user_id']);
        $email = isset($profile['email']) && $profile['email'] !== null
            ? $this->normalizeEmail($profile['email'])
            : null;

        if ($email) {
            $this->assertNotBlocked($email, 'вход через VK невозможен');
        }

        $phone = Phone::normalize($profile['phone'] ?? null);
        $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? '')) ?: null;

        return DB::transaction(function () use ($vkId, $email, $phone, $name, $profile, $ip) {
            $identity = UserIdentity::query()
                ->where('provider', UserIdentity::PROVIDER_VK)
                ->where('provider_user_id', $vkId)
                ->first();

            $user = $identity?->user;
            $created = false;

            if (! $user && $email) {
                $user = User::where('email', $email)->first();
            }

            if (! $user) {
                $user = User::create([
                    'email' => $email,
                    'phone' => $phone,
                    'name' => $name,
                    // VK подтверждает и почту, и телефон — повторно их
                    // не проверяем.
                    'email_verified_at' => $email ? now() : null,
                    'phone_verified_at' => $phone ? now() : null,
                    'last_login_at' => now(),
                ]);
                $created = true;
            } else {
                // У существующего кабинета заполненное не трогаем — только
                // дописываем пустое (VK подтверждает контакты, поэтому
                // добавленные им адрес и номер сразу считаются проверенными).
                if (! $user->email && $email) {
                    $user->email = $email;
                    $user->email_verified_at = now();
                } elseif ($user->email === $email && ! $user->email_verified_at) {
                    $user->email_verified_at = now();
                }

                if (! $user->phone && $phone) {
                    $user->phone = $phone;
                    $user->phone_verified_at = now();
                }

                $user->name = $user->name ?: $name;
                $user->last_login_at = now();
                $user->save();
            }

            $identity
                ? $identity->update(['payload' => $profile, 'last_login_at' => now()])
                : $user->identities()->create([
                    'provider' => UserIdentity::PROVIDER_VK,
                    'provider_user_id' => $vkId,
                    'payload' => $profile,
                    'last_login_at' => now(),
                ]);

            $this->claimOrders($user);

            return ['user' => $user, 'token' => $this->issueToken($user, $ip), 'created' => $created];
        });
    }

    /**
     * Добавить e-mail кабинету, у которого его ещё нет (вход через VK без
     * почты). Сразу записать адрес нельзя: по e-mail кабинет показывает
     * заказы, сделанные до регистрации, — чужой адрес открыл бы чужие
     * покупки. Поэтому отправляем ссылку-подтверждение, как при сбросе
     * пароля, и ставим адрес только по ней (confirmEmail).
     */
    public function requestEmailConfirmation(User $user, string $email, ?string $ip = null): void
    {
        if ($user->email) {
            throw ValidationException::withMessages([
                'email' => 'В кабинете уже указана электронная почта.',
            ]);
        }

        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'указать его нельзя');

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => "Кабинет с e-mail {$email} уже зарегистрирован. "
                    .'Войдите в него по паролю — или укажите другой адрес.',
            ]);
        }

        $token = AuthToken::issue(
            AuthToken::TYPE_EMAIL_CONFIRM,
            $email,
            $user->id,
            config('site.password_reset_ttl_hours'),
            $ip,
        );

        Mail::to($email)->send(new EmailConfirmMail($token, $email));
    }

    /** Подтверждение e-mail по ссылке из письма. */
    public function confirmEmail(string $token): User
    {
        $authToken = AuthToken::findValid(AuthToken::TYPE_EMAIL_CONFIRM, $token);

        if (! $authToken || ! $authToken->user) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка подтверждения недействительна или устарела. Запросите письмо заново.',
            ]);
        }

        $user = $authToken->user;

        // Пока письмо шло, адрес мог занять кто-то ещё.
        if (User::where('email', $authToken->email)->where('id', '!=', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'token' => "Кабинет с e-mail {$authToken->email} уже зарегистрирован. Укажите другой адрес.",
            ]);
        }

        DB::transaction(function () use ($user, $authToken) {
            $user->forceFill([
                'email' => $authToken->email,
                'email_verified_at' => now(),
            ])->save();

            $authToken->markUsed();
            $this->claimOrders($user);
        });

        return $user->refresh();
    }

    /**
     * Запрос на сброс пароля. Ответ одинаков независимо от того, есть ли
     * такой пользователь (legacy сообщал «не зарегистрирован» — это
     * раскрывало базу e-mail'ов). Незарегистрированному покупателю,
     * у которого есть заказы, вместо сброса уходит приглашение.
     */
    public function requestPasswordReset(string $email, ?string $ip = null): void
    {
        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'восстановление доступа невозможно');
        $user = User::where('email', $email)->first();

        if ($user) {
            $token = AuthToken::issue(
                AuthToken::TYPE_PASSWORD_RESET,
                $email,
                $user->id,
                config('site.password_reset_ttl_hours'),
                $ip,
            );

            // Письма кабинета отправляем сразу, а не через очередь `emails`:
            // пользователь ждёт ссылку на экране.
            Mail::to($email)->send(new PasswordResetMail($token, $email));

            return;
        }

        if (Order::where('email', $email)->where('status', Order::STATUS_COMPLETED)->exists()) {
            $token = AuthToken::issue(
                AuthToken::TYPE_INVITE,
                $email,
                null,
                config('site.invite_ttl_hours'),
                $ip,
            );

            Mail::to($email)->send(new RegistrationInviteMail($token, $email));
        }
    }

    /** Установка нового пароля по ссылке из письма. */
    public function resetPassword(string $token, string $password): User
    {
        $authToken = AuthToken::findValid(AuthToken::TYPE_PASSWORD_RESET, $token);

        if (! $authToken || ! $authToken->user) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка для смены пароля недействительна или устарела. Запросите сброс пароля заново.',
            ]);
        }

        $user = $authToken->user;
        $user->forceFill([
            'password' => $this->normalizePassword($password),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $authToken->markUsed();

        // Смена пароля разлогинивает все устройства (у legacy этого не было).
        $user->tokens()->delete();

        return $user;
    }

    /**
     * Смена пароля из кабинета (перенос legacy url/change.php). Если пароля
     * ещё не было (кабинет заведён через VK), покупатель задаёт первый —
     * текущий не спрашиваем, но заводим способ входа 'password'.
     */
    public function changePassword(User $user, string $current, string $password): void
    {
        if ($user->password && ! Hash::check(trim($current), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Текущий пароль указан неверно',
            ]);
        }

        // Логин по паролю — это e-mail: без адреса такой вход некуда вести.
        if (! $user->password && ! $user->email) {
            throw ValidationException::withMessages([
                'password' => 'Сначала укажите электронную почту — она будет логином при входе по паролю.',
            ]);
        }

        $first = ! $user->password;
        $user->forceFill(['password' => $this->normalizePassword($password)])->save();

        if ($first) {
            $user->identities()->create([
                'provider' => UserIdentity::PROVIDER_PASSWORD,
                'provider_user_id' => $user->email,
                'last_login_at' => now(),
            ]);
        }
    }

    /** Проверка ссылки-приглашения: по ней страница регистрации знает e-mail. */
    public function inviteEmail(string $token): string
    {
        $authToken = AuthToken::findValid(AuthToken::TYPE_INVITE, $token);

        if (! $authToken) {
            throw ValidationException::withMessages([
                'token' => 'Ссылка-приглашение недействительна или устарела. Зарегистрируйтесь обычным способом.',
            ]);
        }

        return $authToken->email;
    }

    /**
     * Пригласить покупателя завести кабинет — после первой покупки на
     * e-mail, за которым ещё нет пользователя. Повторное приглашение
     * гасит предыдущую ссылку (AuthToken::issue).
     */
    public function sendInvite(string $email): void
    {
        $email = $this->normalizeEmail($email);

        if (User::where('email', $email)->exists() || $this->isBlocked($email)) {
            return;
        }

        $token = AuthToken::issue(
            AuthToken::TYPE_INVITE,
            $email,
            null,
            config('site.invite_ttl_hours'),
        );

        Mail::to($email)->send(new RegistrationInviteMail($token, $email));
    }

    /**
     * Покупки, сделанные на этот e-mail до регистрации, закрепляем за
     * пользователем (в ЛК они и так видны по e-mail, но связь нужна, чтобы
     * заказ не потерялся при смене адреса). У кабинета без почты — нечего.
     */
    private function claimOrders(User $user): void
    {
        if (! $user->email) {
            return;
        }

        Order::where('email', $user->email)->whereNull('user_id')->update(['user_id' => $user->id]);
    }

    private function issueToken(User $user, ?string $ip = null): string
    {
        return $user->createToken('cabinet:'.($ip ?? 'web'))->plainTextToken;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Чёрный список адресов — таблица blocked_emails (legacy $CHS). */
    private function isBlocked(string $email): bool
    {
        return BlockedEmail::blocked($email);
    }

    /** @param  string  $action  «регистрация невозможна» / «восстановление доступа невозможно» */
    private function assertNotBlocked(string $email, string $action): void
    {
        if ($this->isBlocked($email)) {
            throw ValidationException::withMessages([
                'email' => "Ваш e-mail в чёрном списке, {$action}. Используйте другой e-mail",
            ]);
        }
    }

    /**
     * Пароль: обрезаем пробелы по краям (их часто прихватывают при
     * копировании), минимум 8 символов — сложность сознательно не проверяем.
     */
    private function normalizePassword(string $password): string
    {
        $password = trim($password);

        if (mb_strlen($password) < 8) {
            throw ValidationException::withMessages([
                'password' => 'Слишком короткий пароль: минимальная длина 8 символов',
            ]);
        }

        if (mb_strlen($password) > 72) {
            throw ValidationException::withMessages([
                'password' => 'Слишком длинный пароль: максимальная длина 72 символа',
            ]);
        }

        return $password; // хеширование — cast 'hashed' модели User
    }
}
