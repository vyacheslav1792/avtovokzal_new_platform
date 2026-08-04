<?php

namespace App\Http\Requests;

use App\Models\BlockedEmail;
use App\Services\PassengerDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreOrderRequest extends FormRequest
{
    /** ФИО: буквы (кириллица/латиница), дефис, пробел, апостроф. Без цифр и спецсимволов. */
    private const NAME_REGEX = "regex:/^[А-ЯЁа-яёA-Za-z][А-ЯЁа-яёA-Za-z' -]*$/u";

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
            // Лимиты длины — как в базе станции (SALE: фамилия 20, имя/отчество 14),
            // иначе процедура молча обрежет. Символы: буквы, дефис, пробел, апостроф.
            'passengers.*.last_name' => ['required', 'string', 'min:2', 'max:20', self::NAME_REGEX],
            'passengers.*.first_name' => ['required', 'string', 'min:2', 'max:14', self::NAME_REGEX],
            'passengers.*.middle_name' => ['nullable', 'string', 'max:14', self::NAME_REGEX],
            'passengers.*.birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'passengers.*.gender' => ['sometimes', 'in:М,Ж'],
            'passengers.*.doc_type' => ['sometimes', 'integer'],
            'passengers.*.doc_series' => ['nullable', 'string', 'max:10'],
            'passengers.*.doc_number' => ['required', 'string', 'min:4', 'max:25'],
            'passengers.*.citizenship' => ['sometimes', 'integer', 'exists:citizenships,code'],
            'passengers.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
            // Галочка «Сохранить в профиль» на форме покупки: пассажир
            // попадёт в кабинет и в следующий раз подставится сам.
            // Для гостя игнорируется — сохранять некуда.
            'passengers.*.save_to_profile' => ['sometimes', 'boolean'],
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
            'regex' => 'Поле «:attribute» может содержать только буквы, дефис и пробел.',
            'passengers.*.last_name.max' => 'Фамилия не помещается в билет — не более 20 символов.',
            'passengers.*.first_name.max' => 'Имя не помещается в билет — не более 14 символов.',
            'passengers.*.middle_name.max' => 'Отчество не помещается в билет — не более 14 символов.',
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

    /**
     * Проверка документов по правилам станции (KS_CONTROL_DATA_PAS) —
     * до оплаты, иначе отказ пользователь увидит уже после списания денег.
     */
    public function after(): array
    {
        return [
            // Чёрный список адресов (перенос legacy passengers.php: $CHS →
            // «Ваш e-mail в чёрном списке, покупка невозможна»).
            function (Validator $validator) {
                // Проверяем итоговый адрес заказа: у авторизованного он берётся
                // из профиля (OrderService::create), а не из формы.
                $email = $this->user('sanctum')?->email ?? $this->input('email');

                if (BlockedEmail::blocked($email)) {
                    $validator->errors()->add(
                        'email',
                        'Ваш e-mail в чёрном списке, покупка невозможна. Используйте другой e-mail',
                    );
                }
            },
            function (Validator $validator) {
                foreach ($this->input('passengers', []) as $i => $p) {
                    if (! is_array($p) || empty($p['doc_number'])) {
                        continue;
                    }

                    $docType = (int) ($p['doc_type'] ?? 21);
                    $citizenship = (int) ($p['citizenship'] ?? PassengerDocument::RUSSIA);

                    $parsed = PassengerDocument::parse(
                        $docType,
                        ($p['doc_series'] ?? '').$p['doc_number'],
                    );

                    $error = PassengerDocument::validate(
                        $docType,
                        $citizenship,
                        $parsed['series'],
                        $parsed['number'],
                    );

                    if ($error) {
                        $validator->errors()->add("passengers.{$i}.doc_number", $error);
                    }
                }
            },
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
