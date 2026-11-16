<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AisEmployee;
use App\Services\CustomerAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Покупатели сайта в админке АИС (ais-spa, раздел «Покупатели сайта»).
 *
 * Права сотрудника: `customers.view` — смотреть, `customers.assist` —
 * помощь со входом, `customers.edit` — смена e-mail и отвязка VK (с кодом
 * подтверждения), `customers.impersonate` — вход в кабинет от имени
 * покупателя (тоже с кодом).
 *
 * В legacy такой страницы не было: АИС работала без авторизации вообще, а
 * база покупателей правилась запросами к Firebird руками.
 */
class AdminCustomerController extends Controller
{
    public function __construct(private readonly CustomerAdminService $customers) {}

    /** Список: кабинеты и покупавшие без регистрации, одной выборкой. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(['all', 'users', 'guests'])],
            'login' => ['nullable', Rule::in(['password', 'vk', 'legacy', 'none'])],
            'has_orders' => ['nullable', Rule::in(['0', '1'])],
            'email_unverified' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['success' => true, 'data' => $this->customers->list($data)]);
    }

    /** Карточка кабинета. */
    public function show(User $customer): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->customers->card($customer)]);
    }

    /**
     * Карточка покупавшего без кабинета — по адресу заказа. Адрес приходит
     * в теле запроса, а не в пути: в пути он требовал бы кодирования и
     * ломался бы на точках и плюсах в адресе.
     */
    public function guest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->customers->guestCard($data['email']),
        ]);
    }

    /** Помощь со входом: сброс пароля, подтверждение адреса, сессии. */
    public function assist(Request $request, User $customer): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['reset_password', 'verify_email', 'logout_all'])],
            /*
             * Пароль, который сотрудник задаёт покупателю. Проверок
             * сложности и минимальной длины здесь НЕТ сознательно: пароль
             * придумывает человек, говорящий с покупателем по телефону.
             * Ограничение одно — 72 байта: дальше bcrypt всё равно
             * обрезает, и пароль длиннее работал бы не целиком.
             * Пусто = пароль гасится, покупатель задаёт его сам.
             */
            'password' => ['nullable', 'string', 'max:72'],
        ], [
            'password.max' => 'Пароль длиннее 72 знаков не поддерживается.',
        ]);

        $employee = $this->employee($request);
        $ip = $request->ip();

        $result = match ($data['action']) {
            'reset_password' => $this->customers->resetPassword(
                $customer,
                $employee,
                $data['password'] ?? null,
                $ip,
            ),
            'verify_email' => $this->customers->verifyEmail($customer, $employee, $ip),
            'logout_all' => $this->customers->logoutAll($customer, $employee, $ip),
        };

        return response()->json([
            'success' => true,
            'data' => $result + ['customer' => $this->customers->card($customer->refresh())],
        ]);
    }

    /** Смена e-mail и отвязка VK — право `customers.edit`, с кодом подтверждения. */
    public function update(Request $request, User $customer): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['change_email', 'unlink_vk'])],
            'email' => ['required_if:action,change_email', 'nullable', 'email', 'max:255'],
        ], [
            'email.email' => 'Похоже, в адресе опечатка.',
            'email.required_if' => 'Укажите новый адрес.',
        ]);

        $employee = $this->employee($request);
        $ip = $request->ip();

        $result = $data['action'] === 'change_email'
            ? $this->customers->changeEmail($customer, (string) $data['email'], $employee, $ip)
            : $this->customers->unlinkVk($customer, $employee, $ip);

        return response()->json([
            'success' => true,
            'data' => $result + ['customer' => $this->customers->card($customer->refresh())],
        ]);
    }

    /**
     * Ссылка входа в кабинет от имени покупателя. Отдаём именно ссылку с
     * одноразовым кодом: Bearer-токен кабинета в адресной строке осел бы
     * в истории браузера и в журналах nginx.
     */
    public function impersonate(Request $request, User $customer): JsonResponse
    {
        $result = $this->customers->issueImpersonationCode(
            $customer,
            $this->employee($request),
            $request->ip(),
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Сотрудника в запрос положило middleware `ais.can` (setUserResolver) —
     * он нужен журналу действий: кто и что сделал с кабинетом.
     */
    private function employee(Request $request): AisEmployee
    {
        $employee = $request->user();

        abort_unless($employee instanceof AisEmployee, 403, 'Нужно войти в АИС.');

        return $employee;
    }
}
