<?php

namespace App\Http\Requests;

use App\Models\BlockedEmail;
use App\Services\FeedbackService;
use App\Services\SmartCaptcha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Отправка формы обратной связи. Проверки, которых в legacy не было:
 * SmartCaptcha вместо картинки из сессии, honeypot, минимальное время
 * заполнения и чёрный список адресов (legacy $CHS закрывал покупку и
 * кабинет, но не эту форму).
 */
class StoreFeedbackRequest extends FormRequest
{
    /** Имя: буквы, дефис, пробел, апостроф, точка — как в форме покупки. */
    private const NAME_REGEX = "regex:/^[А-ЯЁа-яёA-Za-z][А-ЯЁа-яёA-Za-z' .-]*$/u";

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'topic' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('feedback.topics')))],
            'name' => ['required', 'string', 'min:2', 'max:255', self::NAME_REGEX],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'min:10', 'max:32'],
            'body' => ['required', 'string', 'min:10', 'max:'.config('feedback.max_body_length')],
            'agree_pd' => ['accepted'],
            // Токен виджета SmartCaptcha; при выключенной капче не нужен.
            'captcha_token' => ['nullable', 'string', 'max:5000'],
            // Подписанная метка времени открытия формы (выдаёт GET /feedback/form).
            'form_token' => ['nullable', 'string', 'max:500'],
            // Honeypot: поле скрыто от человека, заполняют только боты.
            config('feedback.honeypot_field') => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'name.regex' => 'В имени допустимы только буквы, пробел, дефис и апостроф.',
            'email.email' => 'Похоже, в адресе электронной почты опечатка. Проверьте его, пожалуйста.',
            'phone.min' => 'Номер телефона слишком короткий — укажите его полностью.',
            'body.min' => 'Опишите вопрос подробнее — так мы ответим быстрее.',
            'body.max' => 'Сообщение слишком длинное. Максимум :max символов.',
            'agree_pd.accepted' => 'Для отправки необходимо согласие на обработку персональных данных.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Ваше имя',
            'email' => 'E-mail',
            'phone' => 'Телефон',
            'body' => 'Сообщение',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $honeypot = trim((string) $this->input(config('feedback.honeypot_field')));

                if ($honeypot !== '') {
                    // Боту не объясняем, что именно его выдало.
                    $validator->errors()->add('body', 'Сообщение не отправлено. Попробуйте ещё раз.');

                    return;
                }

                if (BlockedEmail::blocked($this->input('email'))) {
                    $validator->errors()->add(
                        'email',
                        'С этого адреса отправка сообщений недоступна. '
                        .'Позвоните в справочную службу: 8 (4862) 72-11-11.',
                    );

                    return;
                }

                /** @var FeedbackService $feedback */
                $feedback = app(FeedbackService::class);

                if (! $feedback->formTokenIsSane($this->input('form_token'))) {
                    $validator->errors()->add(
                        'body',
                        'Форма была открыта слишком давно. Обновите страницу и отправьте сообщение ещё раз.',
                    );

                    return;
                }

                /** @var SmartCaptcha $captcha */
                $captcha = app(SmartCaptcha::class);

                if (! $captcha->verify($this->input('captcha_token'), $this->ip())) {
                    $validator->errors()->add(
                        'captcha_token',
                        'Проверка «я не робот» не пройдена. Пройдите её ещё раз.',
                    );
                }
            },
        ];
    }
}
