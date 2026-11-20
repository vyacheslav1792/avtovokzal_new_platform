<?php

namespace App\Http\Requests;

use App\Models\BlockedEmail;
use App\Services\PassengerDocument;
use App\Support\VendorCode;
use App\Support\VendorText;
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

    /**
     * Самая ранняя дата рождения, которую примет станция.
     *
     * Вендорная процедура `KS_CONTROL_BIRTH_PAS` считает дату верной, когда
     * `birth_day >= Current_Date - 100 лет` и `birth_day < Current_Date`.
     * Проверяем это ДО оплаты: иначе отказ станции («Дата рождения …
     * указана неверно») приходит на оформлении, когда деньги уже списаны.
     */
    public static function oldestBirthDate(): string
    {
        return now()->subYears(100)->toDateString();
    }

    public function rules(): array
    {
        return [
            'awtcod' => VendorCode::rules(),
            'napcod' => VendorCode::rules(),
            'raicecod' => VendorCode::rules(),
            'statcod' => VendorCode::rules(),
            'date' => ['required', 'date_format:Y-m-d'],
            // Уходит в базу станции (поиск рейса по названию остановки) —
            // отсюда и проверка кодировки WIN1251, см. VendorText.
            'to_name' => VendorText::rules(VendorText::MAX_STATION_NAME),
            /*
             * Пункт посадки (остановка на трассе рейса): его ключ в
             * справочнике АИС. Название остановки в базе станции берём не
             * из запроса, а из справочника — иначе подменой параметра
             * можно было бы купить билет от другой остановки по чужой
             * цене (docs/BOARDING_POINTS.md).
             */
            'from_point' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            // Гостю почта обязательна. У покупателя из кабинета она берётся
            // из профиля, а у кабинета через VK её может не быть вовсе —
            // тогда поле необязательно, а в чек уйдёт служебный адрес
            // (`site.fiscal_fallback_email`).
            'email' => [$this->user('sanctum') ? 'nullable' : 'required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'min:10', 'max:32'],
            // Галочка «Сохранить номер в профиль» — показывается только
            // покупателю из кабинета, у которого номера ещё нет.
            'save_phone' => ['sometimes', 'boolean'],
            'agree_pd' => ['accepted'],
            'passengers' => ['required', 'array', 'min:1', 'max:10'],
            // Место — SMALLINT в базе станции (SALONINFO/SALE.N_PLACE):
            // без верхней границы мусорный номер доходил до станции и она
            // отвечала «-303 numeric overflow» (то есть 500 покупателю).
            'passengers.*.place' => ['required', 'integer', 'min:1', 'max:'.VendorCode::MAX],
            'passengers.*.tariff' => ['sometimes', 'in:adult,child'],
            // Лимиты длины — как в базе станции (SALE: фамилия 20, имя/отчество 14),
            // иначе процедура молча обрежет. Символы: буквы, дефис, пробел, апостроф.
            'passengers.*.last_name' => ['required', 'string', 'min:2', 'max:20', self::NAME_REGEX],
            'passengers.*.first_name' => ['required', 'string', 'min:2', 'max:14', self::NAME_REGEX],
            'passengers.*.middle_name' => ['nullable', 'string', 'max:14', self::NAME_REGEX],
            // Границы — ровно как у вендорной процедуры KS_CONTROL_BIRTH_PAS:
            // `birth_day >= today - 100 лет` И `birth_day < today`. Раньше
            // стояло `after:1900-01-01`, и покупатель с датой вроде
            // 12.12.1922 упирался в отказ станции УЖЕ ПОСЛЕ оплаты.
            'passengers.*.birth_date' => [
                'required', 'date', 'before:today',
                'after_or_equal:'.self::oldestBirthDate(),
            ],
            'passengers.*.gender' => ['sometimes', 'in:М,Ж'],
            // DOCUMCOD_PASS в SALE — SMALLINT.
            'passengers.*.doc_type' => ['sometimes', ...VendorCode::boundsFrom(0)],
            'passengers.*.doc_series' => ['nullable', 'string', 'max:10'],
            'passengers.*.doc_number' => ['required', 'string', 'min:4', 'max:25'],
            'passengers.*.citizenship' => ['sometimes', 'integer', 'exists:citizenships,code'],
            'passengers.*.baggage' => ['sometimes', 'integer', 'min:0', 'max:5'],
            // Галочка «Сохранить в профиль» на форме покупки: пассажир
            // попадёт в кабинет и в следующий раз подставится сам.
            // Для гостя игнорируется — сохранять некуда.
            'passengers.*.save_to_profile' => ['sometimes', 'boolean'],
            // Запись профиля, которую подставили в форму: правки уходят
            // ИМЕННО В НЕЁ. Без правила ключ не доживает до `validated()`,
            // и правка документа заводила бы в профиле вторую строку.
            'passengers.*.saved_id' => ['sometimes', 'nullable', 'integer'],
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
            'passengers.*.birth_date.after_or_equal' => 'Проверьте дату рождения: станция оформляет билеты для пассажиров не старше 100 лет.',
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

                if ($email && BlockedEmail::blocked($email)) {
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
