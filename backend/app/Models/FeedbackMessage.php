<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Обращение с сайта (форма обратной связи, перенос legacy url/contact.php).
 * В legacy сообщение только уходило письмом и нигде не хранилось — здесь оно
 * живёт в базе и разбирается в админке АИС.
 */
class FeedbackMessage extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_SPAM = 'spam';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ANSWERED,
        self::STATUS_SPAM,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'captcha_passed' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSpam(): bool
    {
        return $this->status === self::STATUS_SPAM;
    }
}
