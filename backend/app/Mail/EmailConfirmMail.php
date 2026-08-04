<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Подтверждение e-mail, добавленного к кабинету без почты (вход через VK).
 * Ставить адрес без подтверждения нельзя: по нему кабинет показывает
 * покупки, сделанные до регистрации.
 */
class EmailConfirmMail extends Mailable
{
    public function __construct(
        public readonly string $token,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Подтверждение электронной почты — Орловский автовокзал');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-confirm',
            with: [
                'url' => config('site.url').'/confirm-email?token='.$this->token,
                'hours' => config('site.password_reset_ttl_hours'),
            ],
        );
    }
}
