<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Приглашение завести личный кабинет — уходит после первой покупки
 * на e-mail, за которым ещё нет пользователя (у legacy такого письма
 * не было: кабинет заводили только вручную через /register).
 *
 * **Без ссылки** (решение заказчика, 15.11.2026): письма со ссылками
 * чаще попадают в спам. Одноразовый токен для этого письма больше не
 * нужен и по другой причине — регистрация и так подтверждается кодом,
 * отправленным на тот же адрес, то есть владение им доказывается
 * самой регистрацией.
 */
class RegistrationInviteMail extends Mailable
{
    public function __construct(public readonly string $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Личный кабинет на сайте Орловского автовокзала');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.registration-invite');
    }
}
