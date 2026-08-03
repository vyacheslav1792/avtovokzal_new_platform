<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class AuthenticateAgent
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'success' => false,
                'error' => 'Не передан токен агента (заголовок Authorization: Bearer)',
                'error_code' => 'unauthenticated',
            ], 401);
        }

        $agent = collect(config('agents.list'))->first(
            fn (array $a) => hash_equals($a['token'], $token),
        );

        if (! $agent) {
            return response()->json([
                'success' => false,
                'error' => 'Агент с таким токеном не найден',
                'error_code' => 'unknown_agent',
            ], 401);
        }

        if (! IpUtils::checkIp($request->ip(), $agent['ip_whitelist'] ?? [])) {
            return response()->json([
                'success' => false,
                'error' => 'Для этого агента запрещён доступ с этого IP-адреса',
                'error_code' => 'ip_forbidden',
            ], 403);
        }

        $request->attributes->set('agent', $agent);

        return $next($request);
    }
}
