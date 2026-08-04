<?php

namespace App\Mail;

use App\Models\FeedbackMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Уведомление службы поддержки о новом обращении с сайта.
 * В legacy (url/contact.php) это письмо БЫЛО самим обращением — если оно
 * терялось, терялось и сообщение. Теперь обращение хранится в базе и видно
 * в АИС, а письмо лишь сообщает, что оно появилось.
 */
class FeedbackNotificationMail extends Mailable
{
    public function __construct(public readonly FeedbackMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Сообщение с сайта автовокзал57.рф от '
                .$this->message->created_at->format('d.m.Y H:i:s'),
            // Ответить можно прямо из почты — как в legacy (Reply-To).
            replyTo: [new Address($this->message->email, $this->message->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.feedback-notification',
            with: [
                'topic' => $this->message->topic
                    ? (config('feedback.topics')[$this->message->topic] ?? $this->message->topic)
                    : null,
            ],
        );
    }
}
