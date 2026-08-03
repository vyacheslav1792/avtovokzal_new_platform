<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateContactsRequest;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;

/**
 * Редактирование контактов из админки АИС (ais-spa, страница /contacts).
 * В legacy эти данные правились прямо в исходнике url/contacts.php.
 *
 * БЕЗ авторизации — как и админский возврат; на прод не открывать
 * (авторизация появится на этапе users).
 */
class AdminContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    /** Все строки, включая скрытые. */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->contacts->adminData(),
        ]);
    }

    /** Полная замена содержимого страницы контактов. */
    public function update(UpdateContactsRequest $request): JsonResponse
    {
        $data = $this->contacts->replace(
            $request->validated('details'),
            $request->validated('points'),
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
