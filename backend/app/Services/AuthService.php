<?php

namespace App\Services;

use App\Mail\AuthCodeMail;
use App\Mail\RegistrationInviteMail;
use App\Models\AuthCode;
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
 *
 * Регистрация, восстановление пароля и добавление e-mail к кабинету
 * подтверждаются КОДОМ из письма (App\Services\AuthCodeService), а не
 * ссылкой: письма со ссылками чаще попадают в спам. Ссылкой осталось
 * только приглашение в кабинет после первой покупки — оно живёт месяц,
 * и шестизначный код на такой срок выдавать нельзя.
 */
class AuthService
{
    public function __construct(private readonly AuthCodeService $codes) {}

    /**
     * Шаг 1 регистрации: проверяем данные и отправляем код на e-mail.
     * Пользователь ПОКА НЕ создаётся — его данные лежат в auth_codes,
     * иначе неподтверждённый кабинет занимал бы чужой адрес.
     *
     * Исключение — приглашение (ссылка из письма после первой покупки):
     * владение адресом им уже доказано, поэтому кабинет создаётся сразу.
     *
     * @return array{status: 'registered'|'code_sent', user?: User, token?: string, code?: array<string, mixed>}
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

        $this->assertEmailFree($email);

        // Приглашение подтверждает владение почтой: ссылку получили письмом.
        $invite = isset($data['invite_token'])
            ? AuthToken::findValid(AuthToken::TYPE_INVITE, $data['invite_token'])
            : null;

        if ($invite && $invite->email === $email) {
            $user = $this->createUser($email, $password, $phone, verified: true, invite: $invite);

            return [
                'status' => 'registered',
                'user' => $user,
                'token' => $this->issueToken($user, $ip),
            ];
        }

        // Пароль храним уже хэшированным: в payload лежат данные, которые
        // ждут подтверждения адреса, и открытому паролю там не место.
        $this->sendCode(AuthCode::TYPE_REGISTER, $email, null, [
            'password' => Hash::make($password),
            'phone' => $phone,
        ], $ip);

        return [
            'status' => 'code_sent',
            'code' => $this->codes->state(AuthCode::TYPE_REGISTER, $email),
        ];
    }

    /**
     * Шаг 2 регистрации: код из письма подтверждён — создаём кабинет.
     * Пароль и телефон берём из payload кода, повторно их не спрашиваем.
     *
     * @return array{user: User, token: string}
     */
    public function confirmRegistration(string $email, string $code, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'регистрация невозможна');
        // Пока код шёл, адрес могли занять (например, входом через VK).
        $this->assertEmailFree($email);

        $record = $this->codes->verify(AuthCode::TYPE_REGISTER, $email, $code);
        $payload = $record->payload ?? [];

        if (empty($payload['password']) || empty($payload['phone'])) {
            throw ValidationException::withMessages([
                'code' => 'Данные регистрации не сохранились. Заполните форму заново.',
            ]);
        }

        $user = $this->createUser(
            $email,
            $payload['password'],
            $payload['phone'],
            verified: true,
            code: $record,
        );

        return ['user' => $user, 'token' => $this->issueToken($user, $ip)];
    }

    /**
     * Прислать код заново — тем же данным регистрации. Пароль и телефон
     * берём из прежней записи: заново заполнять форму незачем, а хранить
     * их на фронте до подтверждения не нужно.
     *
     * @return array<string, mixed> состояние кода для формы ввода
     */
    public function resendRegistrationCode(string $email, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'регистрация невозможна');
        $this->assertEmailFree($email);

        $last = AuthCode::query()
            ->where('type', AuthCode::TYPE_REGISTER)
            ->where('email', $email)
            ->latest('created_at')
            ->first();

        $payload = $last?->payload ?? [];

        if (empty($payload['password']) || empty($payload['phone'])) {
            throw ValidationException::withMessages([
                'email' => 'Регистрация не начата или данные устарели. Заполните форму заново.',
            ]);
        }

        $this->sendCode(AuthCode::TYPE_REGISTER, $email, null, $payload, $ip);

        return $this->codes->state(AuthCode::TYPE_REGISTER, $email);
    }

    /**
     * Создание кабинета с паролем. `$password` может быть и открытым, и
     * уже хэшированным — cast 'hashed' модели повторно не хэширует.
     */
    private function createUser(
        string $email,
        string $password,
        string $phone,
        bool $verified,
        ?AuthToken $invite = null,
        ?AuthCode $code = null,
    ): User {
        return DB::transaction(function () use ($email, $password, $phone, $verified, $invite, $code) {
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
            $code?->markUsed();
            $this->claimOrders($user);

            return $user;
        });
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
    public function requestEmailConfirmation(User $user, string $email, ?string $ip = null): array
    {
        if ($user->email) {
            throw ValidationException::withMessages([
                'email' => 'В кабинете уже указана электронная почта.',
            ]);
        }

        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'указать его нельзя');
        $this->assertEmailFree($email, 'Войдите в него по паролю — или укажите другой адрес.');

        $this->sendCode(AuthCode::TYPE_EMAIL_CONFIRM, $email, $user->id, [], $ip);

        return $this->codes->state(AuthCode::TYPE_EMAIL_CONFIRM, $email);
    }

    /**
     * Подтверждение e-mail кодом из письма. Код проверяется вместе с
     * адресом и обязан принадлежать этому кабинету: иначе кодом, пришедшим
     * на свой адрес, можно было бы подтвердить чужой кабинет.
     */
    public function confirmEmail(User $user, string $email, string $code): User
    {
        if ($user->email) {
            throw ValidationException::withMessages([
                'email' => 'В кабинете уже указана электронная почта.',
            ]);
        }

        $email = $this->normalizeEmail($email);
        // Пока письмо шло, адрес мог занять кто-то ещё.
        $this->assertEmailFree($email, 'Укажите другой адрес.');

        $record = $this->codes->verify(AuthCode::TYPE_EMAIL_CONFIRM, $email, $code);

        if ($record->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'code' => 'Код выдан другому кабинету. Запросите код заново.',
            ]);
        }

        DB::transaction(function () use ($user, $record, $email) {
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => now(),
            ])->save();

            $record->markUsed();
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
    public function requestPasswordReset(string $email, ?string $ip = null): array
    {
        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'восстановление доступа невозможно');
        $user = User::where('email', $email)->first();

        if ($user) {
            $this->sendCode(AuthCode::TYPE_PASSWORD_RESET, $email, $user->id, [], $ip);

            return $this->codes->state(AuthCode::TYPE_PASSWORD_RESET, $email);
        }

        /*
         * Кабинета нет — код всё равно ВЫПУСКАЕМ, но письмо с ним не
         * отправляем: иначе лимиты выдачи отвечали бы на незарегистрированный
         * адрес иначе, чем на зарегистрированный, и по ним можно было бы
         * перебирать базу покупателей. Воспользоваться таким кодом нельзя —
         * он никому не известен, а менять пароль некому.
         */
        $this->codes->issue(AuthCode::TYPE_PASSWORD_RESET, $email, null, [], $ip);

        // Покупателю без кабинета, но с покупками, уходит приглашение
        // зарегистрироваться — письмом без ссылки, как и все остальные.
        if (Order::where('email', $email)->where('status', Order::STATUS_COMPLETED)->exists()) {
            Mail::to($email)->send(new RegistrationInviteMail($email));
        }

        return $this->codes->state(AuthCode::TYPE_PASSWORD_RESET, $email);
    }

    /**
     * Прислать код для смены пароля заново — это тот же запрос, что и
     * первый: лимиты и приватность ответа уже внутри.
     */
    public function resendPasswordResetCode(string $email, ?string $ip = null): array
    {
        return $this->requestPasswordReset($email, $ip);
    }

    /** Новый пароль по коду из письма. */
    public function resetPassword(string $email, string $code, string $password): User
    {
        $email = $this->normalizeEmail($email);
        $this->assertNotBlocked($email, 'восстановление доступа невозможно');

        $record = $this->codes->verify(AuthCode::TYPE_PASSWORD_RESET, $email, $code);
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => "Кабинет с e-mail {$email} не зарегистрирован.",
            ]);
        }

        $user->forceFill([
            'password' => $this->normalizePassword($password),
            // Код пришёл на этот адрес — владение им доказано.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $record->markUsed();

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

    /**
     * Проверка ссылки-приглашения: по ней страница регистрации знает
     * e-mail и считает его подтверждённым.
     *
     * Новые приглашения ссылок не содержат, но выданные ранее ещё живут
     * (месяц), поэтому обработка остаётся.
     */
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
     * e-mail, за которым ещё нет пользователя.
     *
     * Письмо БЕЗ ссылки и без одноразового токена: регистрация и так
     * подтверждается кодом, отправленным на этот же адрес, поэтому
     * доказывать владение им отдельной ссылкой больше незачем.
     */
    public function sendInvite(string $email): void
    {
        $email = $this->normalizeEmail($email);

        if (User::where('email', $email)->exists() || $this->isBlocked($email)) {
            return;
        }

        Mail::to($email)->send(new RegistrationInviteMail($email));
    }

    /**
     * Выпустить код и отправить письмо. Письма кабинета уходят СРАЗУ, а не
     * через очередь `emails`: покупатель ждёт код на экране, а воркер
     * очереди писем может быть и не запущен.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendCode(
        string $type,
        string $email,
        ?int $userId,
        array $payload = [],
        ?string $ip = null,
    ): void {
        $record = $this->codes->issue($type, $email, $userId, $payload, $ip);

        Mail::to($email)->send(new AuthCodeMail(
            $record,
            (string) $record->plainCode,
            (int) config('site.auth_code.ttl_minutes'),
        ));
    }

    /** Адрес не должен быть занят другим кабинетом. */
    private function assertEmailFree(string $email, ?string $hint = null): void
    {
        if (! User::where('email', $email)->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => "Пользователь с e-mail {$email} уже зарегистрирован. "
                .($hint ?? 'Если вы не помните пароль — воспользуйтесь восстановлением пароля.'),
        ]);
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
