<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Приглашение завести личный кабинет — уходит после первой покупки
 * на e-mail, за которым ещё нет пользователя (у legacy такого письма
 * не было: кабинет заводили только вручную через /register).
 */
class RegistrationInviteMail extends Mailable
{
    public function __construct(
        public readonly string $token,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Личный кабинет на сайте Орловского автовокзала');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.registration-invite',
            with: [
                'url' => config('site.url').'/register?token='.$this->token
                    .'&email='.rawurlencode($this->email),
            ],
        );
    }
}
