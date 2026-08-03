<?php

namespace App\Http\Controllers\Api;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptService $receipts) {}

    /**
     * Регистрация чека: POST /api/v1/receipts.
     * external_id — ключ идемпотентности клиента.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:sale,refund'],
            'email' => ['required', 'email', 'max:255'],
            'electronic_payment' => ['required', 'numeric', 'min:0.01'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.name' => ['required', 'string', 'max:128'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['sometimes', 'numeric', 'min:0.001'],
        ]);

        $client = $request->attributes->get('client');

        $receipt = $this->receipts->register($data, $client['id']);

        return response()->json(
            ['success' => true, 'data' => $this->present($receipt)],
            $receipt->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** Статус чека по ключу идемпотентности. */
    public function show(string $externalId): JsonResponse
    {
        $receipt = $this->receipts->find($externalId);

        if (! $receipt) {
            return response()->json([
                'success' => false,
                'error' => 'Чек с таким external_id не найден',
                'error_code' => 'not_found',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $this->present($receipt)]);
    }

    private function present(Receipt $receipt): array
    {
        return [
            'external_id' => $receipt->external_id,
            'type' => $receipt->type,
            'status' => $receipt->status,
            'check_number' => $receipt->check_number,
            'session_number' => $receipt->session_number,
            'fiscal_sign' => $receipt->fiscal_sign,
            'ofd_url' => $receipt->ofd_url,
            'qr_code' => $receipt->qr_code,
            'error' => $receipt->error,
            'registered_at' => $receipt->registered_at?->toIso8601String(),
        ];
    }
}
