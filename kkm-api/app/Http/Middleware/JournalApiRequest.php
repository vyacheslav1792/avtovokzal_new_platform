<?php

namespace App\Http\Middleware;

use App\Models\ApiRequest;
use Closure;
use Illuminate\Http\Request;

/**
 * Журнал KKM API: каждый запрос и ответ сохраняются в Postgres
 * (таблица api_requests) — по образцу журнала sales-api.
 */
class JournalApiRequest
{
    private const SENSITIVE_KEYS = ['password', 'token'];

    public function handle(Request $request, Closure $next)
    {
        $startedAt = microtime(true);

        $response = $next($request);

        try {
            $payload = json_decode($response->getContent(), true);

            ApiRequest::create([
                'client_id' => $request->attributes->get('client')['id'] ?? null,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'action' => $request->route()?->getName(),
                'ip' => (string) $request->ip(),
                'request' => $this->sanitize($request->all()),
                'response' => is_array($payload) ? $payload : ['raw' => mb_substr((string) $response->getContent(), 0, 4000)],
                'status_code' => $response->getStatusCode(),
                'success' => $response->isSuccessful(),
                'error' => is_array($payload) ? mb_substr((string) ($payload['error'] ?? ''), 0, 1000) ?: null : null,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            report($e); // журнал не должен ломать сам запрос
        }

        return $response;
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}
