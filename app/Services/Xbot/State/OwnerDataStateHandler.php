<?php

namespace App\Services\Xbot\State;

use App\Models\WechatBot;

/**
 * 数据所有者状态处理器
 * 负责处理 MT_DATA_OWNER_MSG 心跳消息
 */
class OwnerDataStateHandler
{
    /**
     * 处理 MT_DATA_OWNER_MSG 消息
     */
    public function handle(WechatBot $wechatBot, int $clientId): void
    {
        // 保存到cache中，并更新 last_active_time
        $wechatBot->update([
            'is_live_at' => now(),
            'client_id' => $clientId,
        ]);
    }
}