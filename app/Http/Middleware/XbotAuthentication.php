<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\WechatClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xbot 认证中间件
 * 验证Windows机器token的有效性
 */
class XbotAuthentication
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $winToken = $request->route('winToken');
        
        if (!$winToken) {
            return response()->json([
                'error' => '缺少Windows机器token'
            ], 401);
        }

        // 验证token是否存在
        $wechatClient = WechatClient::where('token', $winToken)->first();
        if (!$wechatClient) {
            return response()->json([
                'error' => '无效的Windows机器token'
            ], 401);
        }

        // 将WechatClient实例添加到请求中，供后续使用
        $request->merge(['wechat_client' => $wechatClient]);

        return $next($request);
    }
}