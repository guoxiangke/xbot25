<?php

namespace App\Services\Xbot;

use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Support\Facades\Log;

/**
 * Xbot Bot 实例管理器
 * 负责获取和管理 WechatBot 实例
 */
class XbotBotManager
{
    public function getWechatBot(?string $xbotWxid, WechatClient $wechatClient, int $clientId, array $requestAllData): ?WechatBot
    {
        $wechatBot = null;
        
        if ($xbotWxid) {
            $wechatBot = WechatBot::where('wxid', $xbotWxid)->first();
        }
        
        // 由于部分消息没有wxid，故通过windows和端口确定WechatBot
        if(!$wechatBot) {
            $wechatBot = WechatBot::where('wechat_client_id', $wechatClient->id)
               ->where('client_id', $clientId)
               ->first();
        }
        
        if(!$wechatBot) {
            Log::error(__LINE__, [$requestAllData]);
        }

        return $wechatBot;
    }

    /**
     * 处理 MT_DATA_OWNER_MSG 消息
     */
    public function handleDataOwnerMessage(WechatBot $wechatBot, int $clientId): void
    {
        // 保存到cache中，并更新 last_active_time
        $wechatBot->update([
            'is_live_at' => now(),
            'client_id' => $clientId,
        ]);
    }
}