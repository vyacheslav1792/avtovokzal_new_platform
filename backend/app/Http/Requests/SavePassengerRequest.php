<?php

namespace App\Http\Requests;

use App\Services\PassengerDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Пассажир, сохраняемый в профиль. Правила — те же, что у пассажира заказа
 * (StoreOrderRequest): сохранить в кабинете можно только то, с чем потом
 * пройдёт покупка, иначе подстановка из профиля упиралась бы в отказ
 * станции уже на оплате.
 */
class SavePassengerRequest extends FormRequest
{
    /** ФИО: буквы (кириллица/латиница), дефис, пробел, апостроф. */
    private const NAME_REGEX = "regex:/^[А-ЯЁа-яёA-Za-z][А-ЯЁа-яёA-Za-z' -]*$/u";

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Лимиты длины — как в базе станции (SALE: фамилия 20, имя/отчество 14).
            'last_name' => ['required', 'string', 'min:2', 'max:20', self::NAME_REGEX],
            'first_name' => ['required', 'string', 'min:2', 'max:14', self::NAME_REGEX],
            'middle_name' => ['nullable', 'string', 'max:14', self::NAME_REGEX],
            'birth_date' => ['required', 'date', 'before:today', 'after:1900-01-01'],
            'gender' => ['sometimes', 'in:М,Ж'],
            'doc_type' => ['sometimes', 'integer'],
            'doc_series' => ['nullable', 'string', 'max:10'],
            'doc_number' => ['required', 'string', 'min:4', 'max:25'],
            'citizenship' => ['sometimes', 'integer', 'exists:citizenships,code'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'min' => 'Поле «:attribute» слишком короткое.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'regex' => 'Поле «:attribute» может содержать только буквы, дефис и пробел.',
            'last_name.max' => 'Фамилия не помещается в билет — не более 20 символов.',
            'first_name.max' => 'Имя не помещается в билет — не более 14 символов.',
            'middle_name.max' => 'Отчество не помещается в билет — не более 14 символов.',
            'birth_date.before' => 'Дата рождения не может быть в будущем.',
            'birth_date.after' => 'Проверьте дату рождения — она указана неверно.',
            'birth_date.date' => 'Укажите корректную дату рождения.',
            'doc_number.min' => 'Номер документа слишком короткий.',
            'citizenship.exists' => 'Выберите гражданство из списка.',
        ];
    }

    /** Документ — по правилам станции (KS_CONTROL_DATA_PAS). */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $docType = (int) $this->input('doc_type', 21);
                $citizenship = (int) $this->input('citizenship', PassengerDocument::RUSSIA);

                $parsed = PassengerDocument::parse(
                    $docType,
                    (string) $this->input('doc_series', '').(string) $this->input('doc_number', ''),
                );

                $error = PassengerDocument::validate(
                    $docType,
                    $citizenship,
                    $parsed['series'],
                    $parsed['number'],
                );

                if ($error) {
                    $validator->errors()->add('doc_number', $error);
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'last_name' => 'фамилия',
            'first_name' => 'имя',
            'middle_name' => 'отчество',
            'birth_date' => 'дата рождения',
            'doc_type' => 'тип документа',
            'doc_number' => 'серия и номер документа',
            'citizenship' => 'гражданство',
        ];
    }
}
