<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Сохранение контактов из админки: оба списка приходят целиком, в том
 * порядке, в котором их показывает страница.
 */
class UpdateContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'details' => ['present', 'array'],
            'details.*.label' => ['required', 'string', 'max:255'],
            'details.*.kind' => ['required', Rule::in(['text', 'phone', 'email', 'fax'])],
            'details.*.items' => ['present', 'array'],
            'details.*.items.*' => ['nullable', 'string', 'max:255'],
            'details.*.is_active' => ['boolean'],

            'points' => ['present', 'array'],
            'points.*.name' => ['required', 'string', 'max:255'],
            'points.*.work_hours' => ['present', 'array'],
            'points.*.work_hours.*' => ['nullable', 'string', 'max:255'],
            'points.*.phones' => ['present', 'array'],
            'points.*.phones.*' => ['nullable', 'string', 'max:255'],
            'points.*.address' => ['nullable', 'string', 'max:500'],
            'points.*.breaks' => ['present', 'array'],
            'points.*.breaks.*' => ['nullable', 'string', 'max:255'],
            'points.*.is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'details.*.label.required' => 'У строки реквизитов не заполнено название',
            'details.*.kind.in' => 'Неизвестный тип значения реквизита',
            'points.*.name.required' => 'У остановочного пункта не заполнено название',
        ];
    }
}
