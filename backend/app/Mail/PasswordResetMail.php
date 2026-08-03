<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Ссылка для смены пароля (перенос legacy url/forgot.php → newpass.php). */
class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $token,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Смена пароля на сайте Орловского автовокзала');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'url' => config('site.url').'/reset?token='.$this->token
                    .'&email='.rawurlencode($this->email),
                'hours' => config('site.password_reset_ttl_hours'),
            ],
        );
    }
}
