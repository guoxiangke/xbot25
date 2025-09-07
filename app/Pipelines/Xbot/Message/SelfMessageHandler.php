<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Services\CheckInPermissionService;
use Closure;
use Illuminate\Support\Str;

/**
 * 自消息处理器
 * 处理机器人发给自己的消息（系统指令）
 */
class SelfMessageHandler extends BaseXbotHandler
{
    /**
     * 群级别配置项
     */
    private const GROUP_LEVEL_CONFIGS = [
        'room_listen' => '群消息处理',
        'check_in_room' => '群签到系统',
        'youtube_room' => 'YouTube链接响应'
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // 只处理机器人自己发送的消息（私聊给自己 或 在群里发送）
        if (!$this->shouldProcess($context) || !$context->isFromBot) {
            return $next($context);
        }

        if ($this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            $msg = $context->requestRawData['msg'] ?? '';

            if (Str::startsWith($msg, '/set ')) {
                $this->handleSetCommand($context, $msg);
                // 配置命令处理完成，标记为已处理，避免 TextMessageHandler 重复处理
                $context->markAsProcessed(static::class);
                return $context;
            }

            // 同时支持 /config <key> <value> 格式
            if (Str::startsWith($msg, '/config ') && str_word_count(trim($msg)) >= 3) {
                $this->handleSetCommand($context, $msg);
                // 配置命令处理完成，标记为已处理，避免 TextMessageHandler 重复处理
                $context->markAsProcessed(static::class);
                return $context;
            }
        }

        return $next($context);
    }

    /**
     * 处理设置命令（支持 /set 和 /config 两种格式）
     */
    private function handleSetCommand(XbotMessageContext $context, string $message): void
    {
        // 使用 preg_split 处理多个连续空格，并过滤空元素，重新索引
        $parts = array_values(array_filter(preg_split('/\s+/', trim($message)), 'strlen'));

        if (count($parts) < 3) {
            $commandFormat = Str::startsWith($message, '/config') ? '/config' : '/set';
            $this->sendTextMessage($context, "用法: {$commandFormat} <key> <value>\n例如: {$commandFormat} room_msg 1");
            return;
        }

        $key = $parts[1];
        $value = $parts[2];

        // 检查是否为群级别配置
        if (array_key_exists($key, self::GROUP_LEVEL_CONFIGS)) {
            $this->handleGroupLevelConfig($context, $key, $value);
            return;
        }

        // 允许处理的全局设置项（从 XbotConfigManager 获取所有可用配置）
        $allowedKeys = XbotConfigManager::getAvailableCommands();
        if (!in_array($key, $allowedKeys)) {
            $globalKeys = implode(', ', $allowedKeys);
            $groupKeys = implode(', ', array_keys(self::GROUP_LEVEL_CONFIGS));
            $this->sendTextMessage($context, "未知的设置项: $key\n全局配置: {$globalKeys}\n群配置: {$groupKeys}");
            return;
        }

        // 解析值：支持 0/1, ON/OFF, true/false
        $boolValue = $this->parseBooleanValue($value);

        if ($boolValue === null) {
            $this->sendTextMessage($context, "无效的值: $value\n请使用: 0/1, ON/OFF, true/false");
            return;
        }

        // 'chatwoot_enabled'
        // 'room_msg_enabled' ...
        $metaKey = "{$key}_enabled";
        $context->wechatBot->setMeta($metaKey, $boolValue);
        $status = $boolValue ? '已启用' : '已禁用';

        // 特殊处理：开启签到时自动开启群消息处理
        if ($key === 'check_in' && $boolValue === true) {
            $roomMsgKey = "room_msg_enabled";
            $context->wechatBot->setMeta($roomMsgKey, true);
            $this->sendTextMessage($context, "设置成功: $key $status\n⚠️ 签到功能需要群消息处理，已自动开启 room_msg");
        } else {
            $this->sendTextMessage($context, "设置成功: $key $status");
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 解析布尔值
     */
    private function parseBooleanValue(string $value): ?bool
    {
        $value = strtolower(trim($value));

        $trueValues = ['1', 'on', 'true', 'yes', 'enable'];
        $falseValues = ['0', 'off', 'false', 'no', 'disable'];

        if (in_array($value, $trueValues)) {
            return true;
        }

        if (in_array($value, $falseValues)) {
            return false;
        }

        return null;
    }

    /**
     * 处理群级别配置
     */
    private function handleGroupLevelConfig(XbotMessageContext $context, string $key, string $value): void
    {
        // 解析值
        $boolValue = $this->parseBooleanValue($value);
        if ($boolValue === null) {
            $this->sendTextMessage($context, "无效的值: $value\n请使用: 0/1, ON/OFF, true/false");
            return;
        }

        // 群级别配置必须在群聊中执行
        if (!$context->isRoom) {
            $this->sendTextMessage($context, "群级别配置只能在群聊中设置");
            return;
        }

        $roomWxid = $context->roomWxid;
        $status = $boolValue ? '已启用' : '已禁用';
        $configName = self::GROUP_LEVEL_CONFIGS[$key];

        switch ($key) {
            case 'room_listen':
                $this->handleRoomListenConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;

            case 'check_in_room':
                $this->handleCheckInRoomConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;

            case 'youtube_room':
                $this->handleYouTubeRoomConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;
        }

        $this->markAsReplied($context);
    }

    /**
     * 处理群消息监听配置
     */
    private function handleRoomListenConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $configManager = new XbotConfigManager($context->wechatBot);
        $filter = new ChatroomMessageFilter($context->wechatBot, $configManager);
        $filter->setRoomListenStatus($roomWxid, $enabled);
    }

    /**
     * 处理群签到配置
     */
    private function handleCheckInRoomConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $checkInService = new CheckInPermissionService($context->wechatBot);
        $checkInService->setRoomCheckInStatus($roomWxid, $enabled);
    }

    /**
     * 处理YouTube群配置
     */
    private function handleYouTubeRoomConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $wechatBot = $context->wechatBot;
        $allowedRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        
        if ($enabled) {
            // 添加到允许列表
            if (!in_array($roomWxid, $allowedRooms)) {
                $allowedRooms[] = $roomWxid;
            }
        } else {
            // 从允许列表中移除
            $allowedRooms = array_filter($allowedRooms, function($room) use ($roomWxid) {
                return $room !== $roomWxid;
            });
        }
        
        $wechatBot->setMeta('youtube_allowed_rooms', array_values($allowedRooms));
    }

}
