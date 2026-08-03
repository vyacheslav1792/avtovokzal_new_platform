<?php

namespace App\Services;

use App\Models\Receipt;
use Illuminate\Support\Str;

/**
 * Регистрация чеков с идемпотентностью.
 *
 * Клиент передаёт external_id (ключ идемпотентности): повторный запрос
 * с тем же ключом никогда не пробьёт второй чек — вернётся результат
 * первого. Внутри KkmServer той же цели служит IdCommand: перед повторной
 * отправкой всегда спрашиваем GetRezult, не выполнена ли команда уже.
 */
class ReceiptService
{
    public function __construct(private readonly KkmServerClient $kkm) {}

    public function register(array $data, string $clientId): Receipt
    {
        $receipt = Receipt::where('external_id', $data['external_id'])->first();

        if ($receipt && $receipt->status === Receipt::STATUS_REGISTERED) {
            return $receipt; // идемпотентность: чек уже пробит
        }

        if ($receipt) {
            // Команда уже отправлялась — сперва узнаём её судьбу в KkmServer.
            [$status, $payload] = $this->resolveResult($this->kkm->getResult($receipt->id_command));

            if ($status === KkmServerClient::STATUS_OK) {
                return $this->markRegistered($receipt, $payload);
            }

            if ($status === KkmServerClient::STATUS_RUNNING) {
                return $receipt; // ещё выполняется — не дублируем
            }

            // NotFound / NotRun / Error — чек не пробит, отправим заново с тем же
            // IdCommand. Payload при этом обновляем: клиент мог исправить суммы.
            $receipt->update([
                'email' => $data['email'],
                'items' => $data['items'],
                'electronic_payment' => $data['electronic_payment'],
            ]);
        } else {
            $receipt = Receipt::create([
                'client_id' => $clientId,
                'external_id' => $data['external_id'],
                'type' => $data['type'],
                'id_command' => (string) Str::uuid(),
                'num_device' => config('kkm.num_device'),
                'email' => $data['email'],
                'items' => $data['items'],
                'electronic_payment' => $data['electronic_payment'],
                'status' => Receipt::STATUS_PENDING,
            ]);
        }

        $response = $this->registerOnKkt($receipt);

        $status = (int) ($response['Status'] ?? KkmServerClient::STATUS_ERROR);

        if ($status === KkmServerClient::STATUS_OK) {
            return $this->markRegistered($receipt, $response);
        }

        if ($status === KkmServerClient::STATUS_RUNNING) {
            $receipt->update(['status' => Receipt::STATUS_PENDING, 'raw_response' => $response]);

            return $receipt;
        }

        $receipt->update([
            'status' => Receipt::STATUS_ERROR,
            'error' => mb_substr((string) ($response['Error'] ?? 'Неизвестная ошибка ККТ'), 0, 1000),
            'raw_response' => $response,
        ]);

        return $receipt;
    }

    public function find(string $externalId): ?Receipt
    {
        $receipt = Receipt::where('external_id', $externalId)->first();

        // Для зависших команд уточняем результат прямо в KkmServer.
        if ($receipt && $receipt->status === Receipt::STATUS_PENDING) {
            [$status, $payload] = $this->resolveResult($this->kkm->getResult($receipt->id_command));

            if ($status === KkmServerClient::STATUS_OK) {
                return $this->markRegistered($receipt, $payload);
            }
        }

        return $receipt;
    }

    /**
     * Отправка чека в ККТ. Если смена превысила 24 часа (54-ФЗ: ФН
     * блокирует регистрацию), сами закрываем её Z-отчётом и повторяем
     * чек один раз — новая смена откроется автоматически первым чеком.
     */
    private function registerOnKkt(Receipt $receipt): array
    {
        $send = fn (): array => $this->kkm->registerCheck(
            $receipt->id_command,
            $receipt->type === 'refund' ? 1 : 0,
            $receipt->email,
            $receipt->items,
            (float) $receipt->electronic_payment,
        );

        $response = $send();

        $failed = (int) ($response['Status'] ?? KkmServerClient::STATUS_ERROR)
            === KkmServerClient::STATUS_ERROR;

        if ($failed && $this->shiftExpired($response)) {
            $close = $this->kkm->closeShift((string) Str::uuid());

            if ((int) ($close['Status'] ?? KkmServerClient::STATUS_ERROR) === KkmServerClient::STATUS_OK) {
                $response = $send();
            }
        }

        return $response;
    }

    /** Смена на ККТ открыта более 24 часов и требует Z-отчёта? */
    private function shiftExpired(array $response): bool
    {
        // По тексту ошибки (формулировки зависят от драйвера ККТ)…
        $error = mb_strtolower((string) ($response['Error'] ?? ''));
        if (str_contains($error, 'смен') && preg_match('/24\s*час/u', $error)) {
            return true;
        }

        // …и по состоянию ККТ: SessionState 3 — смена истекла (>24 ч).
        try {
            $info = $this->kkm->getKktInfo();

            return (int) ($info['Info']['SessionState'] ?? 0) === 3;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Разбор ответа GetRezult: внешний Status — статус самого GetRezult,
     * результат исходной команды вложен в Rezult со своим Status.
     *
     * @return array{0: int, 1: array} эффективный статус и полезный ответ
     */
    private function resolveResult(array $result): array
    {
        $outer = (int) ($result['Status'] ?? KkmServerClient::STATUS_NOT_FOUND);

        if ($outer !== KkmServerClient::STATUS_OK || ! isset($result['Rezult'])) {
            return [$outer, $result];
        }

        $payload = $result['Rezult'];

        return [(int) ($payload['Status'] ?? KkmServerClient::STATUS_ERROR), $payload];
    }

    private function markRegistered(Receipt $receipt, array $response): Receipt
    {
        $qr = (string) ($response['QRCode'] ?? '');
        parse_str(str_replace('&amp;', '&', $qr), $qrParams);

        $receipt->update([
            'status' => Receipt::STATUS_REGISTERED,
            'check_number' => $response['CheckNumber'] ?? null,
            'session_number' => $response['SessionNumber'] ?? null,
            'fiscal_sign' => $qrParams['fp'] ?? null,
            'ofd_url' => $response['URL'] ?? null,
            'qr_code' => $qr ?: null,
            'raw_response' => $response,
            'error' => null,
            'registered_at' => now(),
        ]);

        return $receipt->refresh();
    }
}
