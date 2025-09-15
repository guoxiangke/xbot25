<?php

namespace App\Services\Guards;

use App\Models\WechatBot;
use App\Services\Managers\ConfigManager;
use App\Services\Managers\MessageFilterManager;

/**
 * 功能权限守护器
 * 管理各种功能的权限检查和黑白名单
 */
class FeatureGuard
{
    private WechatBot $wechatBot;
    private ConfigManager $configManager;
    private MessageFilterManager $roomFilter;

    public function __construct(WechatBot $wechatBot)
    {
        $this->wechatBot = $wechatBot;
        $this->configManager = new ConfigManager($wechatBot);
        $this->roomFilter = new MessageFilterManager($wechatBot, $this->configManager);
    }

    /**
     * 检查群聊是否允许签到
     */
    public function canCheckIn(string $roomWxid): bool
    {
        // 第一步：检查 room_msg 前置条件
        $roomMsgPermission = $this->checkRoomMessagePermission($roomWxid);
        if (!$roomMsgPermission) {
            return false;
        }

        // 第二步：检查签到系统权限
        $checkInPermission = $this->checkCheckInPermission($roomWxid);
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
            
            $this->wechatBot->setMeta('check_in_specials', $roomConfig);
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
        return $this->wechatBot->getMeta('check_in_specials', []);
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
        $this->configManager = new ConfigManager($this->wechatBot);
        $this->roomFilter = new MessageFilterManager($this->wechatBot, $this->configManager);
        
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