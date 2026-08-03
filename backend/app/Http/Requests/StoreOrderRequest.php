<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'awtcod' => ['required', 'integer'],
            'napcod' => ['required', 'integer'],
            'raicecod' => ['required', 'integer'],
            'statcod' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'to_name' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:10', 'max:32'],
            'agree_pd' => ['accepted'],
            'passengers' => ['required', 'array', 'min:1', 'max:10'],
            'passengers.*.place' => ['required', 'integer', 'min:1'],
            'passengers.*.tariff' => ['sometimes', 'in:adult,child'],
            'passengers.*.last_name' => ['required', 'string', 'min:2', 'max:40'],
            'passengers.*.first_name' => ['required', 'string', 'min:2', 'max:30'],
            'passengers.*.middle_name' => ['nullable', 'string', 'max:30'],
            'passengers.*.birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'passengers.*.gender' => ['sometimes', 'in:М,Ж'],
            'passengers.*.doc_type' => ['sometimes', 'integer'],
            'passengers.*.doc_series' => ['nullable', 'string', 'max:10'],
            'passengers.*.doc_number' => ['required', 'string', 'min:4', 'max:25'],
            'passengers.*.citizenship' => ['sometimes', 'integer', 'exists:citizenships,code'],
            'passengers.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
        ];
    }

    /** Русские сообщения в человеческом формате (не технические). */
    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'passengers.*.place.required' => 'Выберите место для пассажира.',
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
            'phone.min' => 'Номер телефона слишком короткий — укажите его полностью.',
            'agree_pd.accepted' => 'Для покупки необходимо согласие на обработку персональных данных.',
            'min' => 'Поле «:attribute» слишком короткое.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'passengers.*.birth_date.before' => 'Дата рождения не может быть в будущем.',
            'passengers.*.birth_date.after' => 'Проверьте дату рождения — она указана неверно.',
            'passengers.*.birth_date.date' => 'Укажите корректную дату рождения.',
            'passengers.*.doc_number.min' => 'Номер документа слишком короткий.',
            'passengers.*.citizenship.exists' => 'Выберите гражданство из списка.',
            'date.date_format' => 'Некорректная дата поездки.',
            'in' => 'Недопустимое значение поля «:attribute».',
            'integer' => 'Поле «:attribute» заполнено некорректно.',
            'array' => 'Поле «:attribute» заполнено некорректно.',
            'passengers.min' => 'Добавьте хотя бы одного пассажира.',
            'passengers.max' => 'За один заказ можно купить не более 10 билетов.',
        ];
    }

    public function attributes(): array
    {
        $attrs = [
            'email' => 'электронная почта',
            'phone' => 'номер телефона',
            'date' => 'дата поездки',
            'to_name' => 'пункт назначения',
            'passengers' => 'пассажиры',
        ];

        foreach (range(0, 9) as $i) {
            $n = $i + 1;
            $attrs += [
                "passengers.{$i}.place" => "место (пассажир {$n})",
                "passengers.{$i}.last_name" => "фамилия (пассажир {$n})",
                "passengers.{$i}.first_name" => "имя (пассажир {$n})",
                "passengers.{$i}.middle_name" => "отчество (пассажир {$n})",
                "passengers.{$i}.birth_date" => "дата рождения (пассажир {$n})",
                "passengers.{$i}.doc_type" => "тип документа (пассажир {$n})",
                "passengers.{$i}.doc_number" => "серия и номер документа (пассажир {$n})",
                "passengers.{$i}.citizenship" => "гражданство (пассажир {$n})",
                "passengers.{$i}.baggage" => "багаж (пассажир {$n})",
            ];
        }

        return $attrs;
    }
}
