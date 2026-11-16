<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала: кто и когда работал в АИС от имени другого сотрудника.
 *
 * Сессия такой работы при выходе удаляется, поэтому журнал отдельной
 * таблицей: вопрос «кто заходил под этим сотрудником» должен иметь ответ
 * и через месяц. Логины обеих сторон копируются в запись — сотрудника
 * могут переименовать или удалить.
 */
class EmployeeImpersonation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'employee_id', 'employee_login', 'admin_id', 'admin_login',
        'ip', 'user_agent', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
