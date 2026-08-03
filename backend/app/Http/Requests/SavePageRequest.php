<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание и правка страницы сайта из админки. Содержимое — HTML целиком,
 * как в legacy-файле url/<slug>.php: разметку не режем, страницы пишет
 * администратор сайта.
 */
class SavePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pageId = $this->route('page')?->id;

        return [
            // Адрес страницы: латиница, цифры и дефис — как /station-sogl-pd.
            'slug' => [
                'required', 'string', 'max:100', 'regex:~^[a-z0-9][a-z0-9\-]*$~',
                Rule::unique('pages', 'slug')->ignore($pageId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'meta_title' => ['required', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'content' => ['present', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Адрес страницы может содержать только латиницу, цифры и дефис',
            'slug.unique' => 'Страница с таким адресом уже есть',
            'title.required' => 'Заполните название страницы',
            'meta_title.required' => 'Заполните заголовок вкладки (title)',
        ];
    }
}
