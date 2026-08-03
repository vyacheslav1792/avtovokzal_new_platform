<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class AuthenticateClient
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => 'Не передан токен клиента (заголовок Authorization: Bearer)',
                'error_code' => 'unauthenticated',
            ], 401);
        }

        $client = collect(config('clients.list'))->first(
            fn (array $c) => hash_equals($c['token'], $token),
        );

        if (! $client) {
            return response()->json([
                'success' => false,
                'error' => 'Клиент с таким токеном не найден',
                'error_code' => 'unknown_client',
            ], 401);
        }

        if (! IpUtils::checkIp($request->ip(), $client['ip_whitelist'] ?? [])) {
            return response()->json([
                'success' => false,
                'error' => 'Для этого клиента запрещён доступ с этого IP-адреса',
                'error_code' => 'ip_forbidden',
            ], 403);
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }
}
