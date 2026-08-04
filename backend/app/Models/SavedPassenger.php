<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Пассажир, сохранённый покупателем в профиле: подставляется в форму
 * покупки, чтобы не вводить ФИО и документ заново. Состав полей — тот же,
 * что уходит в билет.
 */
class SavedPassenger extends Model
{
    protected $guarded = [];

    protected $casts = [
        'birth_date' => 'date',
        'doc_type' => 'integer',
        'citizenship' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Серия и номер одной строкой — так документ вводится на форме покупки. */
    public function documentLine(): string
    {
        return trim(($this->doc_series ?? '').' '.$this->doc_number);
    }

    public function fullName(): string
    {
        return trim("{$this->last_name} {$this->first_name} {$this->middle_name}");
    }
}
