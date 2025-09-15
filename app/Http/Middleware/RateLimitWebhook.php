<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook 限流中间件
 * 防止webhook请求过于频繁
 */
class RateLimitWebhook
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = 100, int $decayMinutes = 1): Response
    {
        $winToken = $request->route('winToken');
        $clientId = $request->input('client_id');
        
        // 构建限流键
        $key = "webhook_rate_limit:{$winToken}:{$clientId}";
        
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $maxAttempts) {
            return response()->json([
                'error' => 'Too many webhook requests. Please try again later.'
            ], 429);
        }
        
        // 增加计数
        Cache::put($key, $attempts + 1, now()->addMinutes($decayMinutes));
        
        return $next($request);
    }
}