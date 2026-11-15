<?php

namespace App\Mail;

use App\Models\Refund;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо о возврате билета (перенос legacy `url/backbycode.php` и
 * `ticketback.php`, тема — legacy `mail_success_back_subject`).
 */
class RefundMail extends Mailable
{
    public function __construct(public readonly Refund $refund) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Возврат билетов на поездку');
    }

    public function content(): Content
    {
        // Без ссылок — как и остальные письма сайта (см. PurchaseMail).
        return new Content(
            markdown: 'mail.refund',
            with: ['order' => $this->refund->order],
        );
    }
}
