<?php

namespace App\Services\StateHandlers;

use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Support\Facades\Log;

/**
 * 登出状态处理器
 * 负责处理用户登出逻辑
 */
class LogoutStateHandler
{
    private $wechatClient;
    private $currentWindows;
    private $clientId;
    private $qrCodeHandler;

    public function __construct(
        WechatClient $wechatClient,
        string $currentWindows,
        int $clientId
    ) {
        $this->wechatClient = $wechatClient;
        $this->currentWindows = $currentWindows;
        $this->clientId = $clientId;
        $this->qrCodeHandler = new QrCodeStateHandler($wechatClient, $currentWindows, $clientId);
    }

    /**
     * 处理用户登出
     */
    public function handle(?WechatBot $wechatBot): string
    {
        $this->processLogout($wechatBot);
        if ($wechatBot) {
            return "processed MT_USER_LOGOUT for {$wechatBot->wxid}";
        } else {
            return "processed MT_USER_LOGOUT (no active bot)";
        }
    }

    /**
     * 处理登出逻辑
     */
    private function processLogout(?WechatBot $wechatBot): void
    {
        // 登出后，维护 $qrPool
        $this->qrCodeHandler->removeQrFromPool();

        // 当前端关闭了还没扫码登录的客户端时，没有 $wechatBot
        if($wechatBot){
            $wechatBot->update([
                'is_live_at' => null,
                'login_at' => null,
                'client_id' => null
            ]);
            Log::info(__FUNCTION__, [
                'windows' => $this->currentWindows,
                'client_id' => $this->clientId,
                'wechat_bot' => $wechatBot->toArray(),
                'message' => '登出'
            ]);
        } else {
            Log::info(__FUNCTION__, [
                'windows' => $this->currentWindows,
                'client_id' => $this->clientId,
                'message' => '当前关闭的客户端，还未登录'
            ]);
        }
    }
}