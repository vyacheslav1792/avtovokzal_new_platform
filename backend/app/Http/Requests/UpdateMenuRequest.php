<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Сохранение меню сайта из админки: списки шапки и подвала приходят
 * целиком, порядок в массиве — порядок ссылок на сайте.
 */
class UpdateMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'header' => ['present', 'array'],
            'header.*.title' => ['required', 'string', 'max:255'],
            'header.*.url' => ['required', 'string', 'max:500'],
            // Раздел выпадающего меню шапки («Пассажирам» в legacy).
            'header.*.group' => ['nullable', 'string', 'max:255'],
            'header.*.is_active' => ['boolean'],

            'footer' => ['present', 'array'],
            'footer.*.title' => ['required', 'string', 'max:255'],
            'footer.*.url' => ['required', 'string', 'max:500'],
            'footer.*.is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'header.*.title.required' => 'У пункта верхнего меню не заполнено название',
            'header.*.url.required' => 'У пункта верхнего меню не заполнен адрес',
            'footer.*.title.required' => 'У пункта меню в подвале не заполнено название',
            'footer.*.url.required' => 'У пункта меню в подвале не заполнен адрес',
        ];
    }
}
