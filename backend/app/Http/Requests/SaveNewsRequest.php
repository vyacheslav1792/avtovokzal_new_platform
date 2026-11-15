<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание и правка новости из админки. Содержимое — HTML целиком, как у
 * страниц сайта: разметку не режем, новости пишет администратор сайта.
 */
class SaveNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $newsId = $this->route('news')?->id;

        return [
            // Адрес новости: латиница, цифры и дефис — как у страниц.
            'slug' => [
                'required', 'string', 'max:120', 'regex:~^[a-z0-9][a-z0-9\-]*$~',
                Rule::unique('news', 'slug')->ignore($newsId),
            ],
            'title' => ['required', 'string', 'max:255'],
            // Заголовок вкладки и описание необязательны: пустые заменяются
            // заголовком и кратким содержанием — иначе ради срочного
            // объявления пришлось бы заполнять четыре поля.
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'lead' => ['nullable', 'string'],
            'content' => ['present', 'string'],
            // Обложка — адрес файла сайта (/upload/…) либо внешний адрес.
            'cover_url' => ['nullable', 'string', 'max:500'],
            'cover_alt' => ['nullable', 'string', 'max:255'],
            'published_at' => ['required', 'date'],
            'publish_from' => ['nullable', 'date'],
            'publish_to' => ['nullable', 'date', 'after:publish_from'],
            'is_active' => ['boolean'],
            'on_index' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Адрес новости может содержать только латиницу, цифры и дефис',
            'slug.unique' => 'Новость с таким адресом уже есть',
            'title.required' => 'Заполните заголовок новости',
            'published_at.required' => 'Укажите дату новости',
            'publish_to.after' => 'Конец показа должен быть позже начала',
        ];
    }
}
