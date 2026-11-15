<?php

namespace App\Mail;

use App\Models\AuthCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо с кодом подтверждения: регистрация, восстановление пароля,
 * подтверждение e-mail у кабинета без почты.
 *
 * **В письме нет ни одной ссылки** — ни кнопки, ни адреса сайта, ни
 * mailto в подписи: письма со ссылками заметно чаще уходят в спам, а
 * ради этого код и заменил одноразовую ссылку. Поэтому письмо не
 * использует markdown-шаблон `mail::message` (его шапка и подвал ведут
 * на сайт) и собирается своим blade без внешних ресурсов.
 */
class AuthCodeMail extends Mailable
{
    public function __construct(
        public readonly AuthCode $authCode,
        /** Открытый код: в самой записи лежит только хэш. */
        public readonly string $code,
        /** Через сколько минут код перестанет действовать. */
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->authCode->type) {
            AuthCode::TYPE_REGISTER => 'Код подтверждения регистрации — Орловский автовокзал',
            AuthCode::TYPE_PASSWORD_RESET => 'Код для смены пароля — Орловский автовокзал',
            default => 'Код подтверждения электронной почты — Орловский автовокзал',
        });
    }

    public function content(): Content
    {
        $with = [
            'code' => $this->code,
            'email' => $this->authCode->email,
            'ttlMinutes' => $this->ttlMinutes,
            'heading' => match ($this->authCode->type) {
                AuthCode::TYPE_REGISTER => 'Код подтверждения регистрации',
                AuthCode::TYPE_PASSWORD_RESET => 'Код для смены пароля',
                default => 'Код подтверждения электронной почты',
            },
            'intro' => match ($this->authCode->type) {
                AuthCode::TYPE_REGISTER => 'Вы начали регистрацию в личном кабинете '
                    .'на сайте Орловского автовокзала. Введите этот код на странице '
                    .'регистрации, чтобы подтвердить адрес '.$this->authCode->email.'.',
                AuthCode::TYPE_PASSWORD_RESET => 'Вы начали восстановление пароля '
                    .'от личного кабинета для адреса '.$this->authCode->email.'. '
                    .'Введите этот код на странице восстановления пароля.',
                default => 'Вы указали адрес '.$this->authCode->email
                    .' в личном кабинете на сайте Орловского автовокзала. '
                    .'Введите этот код в профиле, чтобы подтвердить адрес.',
            },
            'ignore' => match ($this->authCode->type) {
                AuthCode::TYPE_REGISTER => 'Если вы не регистрировались, письмо можно '
                    .'проигнорировать — кабинет создан не будет.',
                AuthCode::TYPE_PASSWORD_RESET => 'Если вы не запрашивали смену пароля, '
                    .'письмо можно проигнорировать — пароль останется прежним.',
                default => 'Если вы этого не делали, письмо можно проигнорировать — '
                    .'адрес к кабинету привязан не будет.',
            },
        ];

        return new Content(
            view: 'mail.auth-code',
            text: 'mail.auth-code-text',
            with: $with,
        );
    }
}
