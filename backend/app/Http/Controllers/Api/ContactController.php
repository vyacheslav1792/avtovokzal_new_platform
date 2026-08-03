<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;

/**
 * Контакты для сайта (страница /contacts, перенос legacy url/contacts.php).
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contacts) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->contacts->publicData(),
        ]);
    }
}
