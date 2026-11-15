<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Письмо с билетами после оформления заказа (перенос legacy
 * `emails/buy_success.php`, тема — legacy `mail_success_buy_subject`).
 *
 * PDF билетов приходит готовым телом файла, а не рендерится здесь: Gotenberg
 * может не ответить, и тогда письмо должно уйти без вложения, а не упасть
 * вместе с джобом (см. App\Jobs\SendPurchaseEmail).
 */
class PurchaseMail extends Mailable
{
    public function __construct(
        public readonly Order $order,
        private readonly ?string $pdf = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Успешная покупка билетов на сайте Орловского автовокзала.',
        );
    }

    public function content(): Content
    {
        // Ссылок в письме нет (решение заказчика, 15.11.2026): из-за них
        // письма уходили в спам, а билеты обязаны доходить. Вместо кнопки
        // «Открыть билеты» к письму прикладывается тот же PDF.
        return new Content(
            markdown: 'mail.purchase',
            with: ['pdfAttached' => $this->pdf !== null],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if ($this->pdf === null) {
            return [];
        }

        // Имя файла — как у выгрузки PDF из личного кабинета (OrderController::pdf).
        return [
            Attachment::fromData(fn () => $this->pdf, 'Билеты на '.$this->order->date_start->format('d.m.Y').'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
