<?php

namespace App\Services;

use App\Models\WechatBot;

/**
 * 群消息过滤器
 * 根据 room_msg 配置和群特例设置决定是否处理群消息
 */
class ChatroomMessageFilter
{
    private WechatBot $wechatBot;
    private XbotConfigManager $configManager;

    // 始终放行的命令列表
    private const ALWAYS_ALLOWED_COMMANDS = [
        '/set room_listen',
        '/get room_id'
    ];

    public function __construct(WechatBot $wechatBot, XbotConfigManager $configManager)
    {
        $this->wechatBot = $wechatBot;
        $this->configManager = $configManager;
    }

    /**
     * 判断是否应该处理群消息
     */
    public function shouldProcess(string $roomWxid, string $messageContent): bool
    {
        \Log::debug('ChatroomMessageFilter shouldProcess called', [
            'room_wxid' => $roomWxid,
            'message_content' => $messageContent
        ]);

        // 首先检查是否为始终放行的命令
        $isAlwaysAllowed = $this->isAlwaysAllowedCommand($messageContent);
        if ($isAlwaysAllowed) {
            \Log::debug('Always allowed command - allowing', [
                'room_wxid' => $roomWxid,
                'message_content' => $messageContent
            ]);
            return true;
        }

        try {
            $roomConfig = $this->wechatBot->getMeta('allowed_or_denied_chatrooms', []);
        } catch (\Exception $e) {
            \Log::error('Failed to get room config', [
                'room_wxid' => $roomWxid,
                'error' => $e->getMessage()
            ]);
            $roomConfig = [];
        }
        
        $isRoomMsgEnabled = $this->configManager->isEnabled('room_msg');

        if ($isRoomMsgEnabled) {
            // room_msg 开启：默认处理，但配置为false的群不处理
            $result = $roomConfig[$roomWxid] ?? true;
            \Log::debug('Room msg enabled - result', [
                'room_wxid' => $roomWxid,
                'room_config' => $roomConfig[$roomWxid] ?? 'not_set',
                'result' => $result
            ]);
            return $result;
        } else {
            // room_msg 关闭：默认不处理，但配置为true的群特例处理
            $result = $roomConfig[$roomWxid] ?? false;
            \Log::debug('Room msg disabled - result', [
                'room_wxid' => $roomWxid,
                'room_config' => $roomConfig[$roomWxid] ?? 'not_set',
                'result' => $result
            ]);
            return $result;
        }
    }

    /**
     * 检查是否为始终放行的命令
     */
    private function isAlwaysAllowedCommand(string $content): bool
    {
        $normalizedContent = trim($content);
        
        foreach (self::ALWAYS_ALLOWED_COMMANDS as $allowedCommand) {
            if (str_starts_with($normalizedContent, $allowedCommand)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 设置群监听状态
     */
    public function setRoomListenStatus(string $roomWxid, bool $status): bool
    {
        try {
            $roomConfig = $this->wechatBot->getMeta('allowed_or_denied_chatrooms', []);
            $roomConfig[$roomWxid] = $status;
            
            $this->wechatBot->setMeta('allowed_or_denied_chatrooms', $roomConfig);
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to set room listen status', [
                'room_wxid' => $roomWxid,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取群监听状态
     */
    public function getRoomListenStatus(string $roomWxid): ?bool
    {
        try {
            $roomConfig = $this->wechatBot->getMeta('allowed_or_denied_chatrooms', []);
            return $roomConfig[$roomWxid] ?? null;
        } catch (\Exception $e) {
            \Log::error('Failed to get room listen status', [
                'room_wxid' => $roomWxid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取所有群配置
     */
    public function getAllRoomConfigs(): array
    {
        return $this->wechatBot->getMeta('allowed_or_denied_chatrooms', []);
    }
}