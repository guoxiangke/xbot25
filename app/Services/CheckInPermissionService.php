<?php

namespace App\Services;

use App\Models\WechatBot;

/**
 * 签到权限检查服务
 * 管理签到系统的全局开关和群组黑白名单权限
 */
class CheckInPermissionService
{
    private WechatBot $wechatBot;
    private XbotConfigManager $configManager;
    private ChatroomMessageFilter $roomFilter;

    public function __construct(WechatBot $wechatBot)
    {
        $this->wechatBot = $wechatBot;
        $this->configManager = new XbotConfigManager($wechatBot);
        $this->roomFilter = new ChatroomMessageFilter($wechatBot, $this->configManager);
    }

    /**
     * 检查群聊是否允许签到
     */
    public function canCheckIn(string $roomWxid): bool
    {
        // 第一步：检查 room_msg 前置条件
        $roomMsgPermission = $this->checkRoomMessagePermission($roomWxid);
        if (!$roomMsgPermission) {
            \Log::debug(static::class, [
                'message' => 'Room message permission denied',
                'room_wxid' => $roomWxid,
                'room_msg_permission' => $roomMsgPermission
            ]);
            return false;
        }

        // 第二步：检查签到系统权限
        $checkInPermission = $this->checkCheckInPermission($roomWxid);
        \Log::debug(static::class, [
            'message' => 'Final check-in permission',
            'room_wxid' => $roomWxid,
            'room_msg_permission' => $roomMsgPermission,
            'check_in_permission' => $checkInPermission,
            'final_result' => $checkInPermission
        ]);

        return $checkInPermission;
    }

    /**
     * 检查群消息处理权限（前置条件）
     */
    private function checkRoomMessagePermission(string $roomWxid): bool
    {
        // 使用现有的群消息过滤器判断群消息是否可以处理
        return $this->roomFilter->shouldProcess($roomWxid, '签到'); // 使用签到作为测试内容
    }

    /**
     * 检查签到系统权限
     */
    private function checkCheckInPermission(string $roomWxid): bool
    {
        $isGlobalCheckInEnabled = $this->configManager->isEnabled('check_in');
        $roomCheckInConfig = $this->getRoomCheckInConfig();

        if ($isGlobalCheckInEnabled) {
            // 黑名单模式：全局启用，默认允许，但特定群可以禁用
            return $roomCheckInConfig[$roomWxid] ?? true;
        } else {
            // 白名单模式：全局禁用，默认不允许，但特定群可以启用
            return $roomCheckInConfig[$roomWxid] ?? false;
        }
    }

    /**
     * 设置群聊签到权限
     */
    public function setRoomCheckInStatus(string $roomWxid, bool $status): bool
    {
        try {
            $roomConfig = $this->getRoomCheckInConfig();
            $roomConfig[$roomWxid] = $status;
            
            $this->wechatBot->setMeta('check_in_allowed_rooms', $roomConfig);
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to set room check-in status', [
                'room_wxid' => $roomWxid,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 获取群聊签到权限状态
     */
    public function getRoomCheckInStatus(string $roomWxid): ?bool
    {
        try {
            $roomConfig = $this->getRoomCheckInConfig();
            return $roomConfig[$roomWxid] ?? null;
        } catch (\Exception $e) {
            \Log::error('Failed to get room check-in status', [
                'room_wxid' => $roomWxid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * 获取所有群聊签到配置
     */
    public function getAllRoomCheckInConfigs(): array
    {
        return $this->getRoomCheckInConfig();
    }

    /**
     * 获取群聊签到配置
     */
    private function getRoomCheckInConfig(): array
    {
        return $this->wechatBot->getMeta('check_in_allowed_rooms', []);
    }

    /**
     * 检查签到系统是否全局启用
     */
    public function isGlobalCheckInEnabled(): bool
    {
        return $this->configManager->isEnabled('check_in');
    }

    /**
     * 获取权限状态描述（用于调试和显示）
     */
    public function getPermissionStatusDescription(string $roomWxid): array
    {
        // 重新创建配置管理器和过滤器以获取最新状态
        $this->configManager = new XbotConfigManager($this->wechatBot);
        $this->roomFilter = new ChatroomMessageFilter($this->wechatBot, $this->configManager);
        
        $roomMsgAllowed = $this->checkRoomMessagePermission($roomWxid);
        $globalCheckInEnabled = $this->isGlobalCheckInEnabled();
        $roomSpecificStatus = $this->getRoomCheckInStatus($roomWxid);
        $canCheckIn = $this->canCheckIn($roomWxid);

        return [
            'room_msg_allowed' => $roomMsgAllowed,
            'global_check_in_enabled' => $globalCheckInEnabled,
            'room_specific_status' => $roomSpecificStatus,
            'final_permission' => $canCheckIn,
            'mode' => $globalCheckInEnabled ? 'blacklist' : 'whitelist'
        ];
    }
}