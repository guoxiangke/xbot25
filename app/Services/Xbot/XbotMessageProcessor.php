<?php

namespace App\Services\Xbot;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Xbot\XbotService;
use App\Services\Xbot\State\QrCodeStateHandler;
use App\Services\Xbot\State\LoginStateHandler;
use App\Services\Xbot\State\LogoutStateHandler;
use App\Services\Xbot\State\OwnerDataStateHandler;
use Illuminate\Support\Facades\Log;

/**
 * Xbot 消息处理器
 * 负责处理各种类型的消息
 */
class XbotMessageProcessor
{
    private $xbotContactSyncProcessor;
    private $xbotMessageRouter;

    public function __construct(
        XbotContactSyncProcessor $xbotContactSyncProcessor,
        XbotMessageRouter $xbotMessageRouter
    ) {
        $this->xbotContactSyncProcessor = $xbotContactSyncProcessor;
        $this->xbotMessageRouter = $xbotMessageRouter;
    }

    public function processMessage(
        ?WechatBot $wechatBot, 
        array $requestRawData, 
        string $msgType,
        WechatClient $wechatClient,
        string $currentWindows,
        ?string $xbotWxid,
        XbotService $xbot,
        int $clientId
    ): mixed {
        // 客户端连接消息
        if ($msgType == 'MT_CLIENT_CONTECTED') {
            sleep(1);
            return null;
        }

        // 客户端断开连接
        if ($msgType == 'MT_CLIENT_DISCONTECTED') {
            $xbot->createNewClient();
            return $this->processStateMessage('MT_USER_LOGOUT', $requestRawData, $wechatBot, $wechatClient, $currentWindows, $xbotWxid, $xbot, $clientId);
        }

        // 状态消息类型
        $stateTypes = [
            'MT_RECV_QRCODE_MSG',
            'MT_USER_LOGIN', 
            'MT_USER_LOGOUT',
            'MT_DATA_OWNER_MSG'
        ];

        // 处理状态消息
        if (in_array($msgType, $stateTypes)) {
            return $this->processStateMessage($msgType, $requestRawData, $wechatBot, $wechatClient, $currentWindows, $xbotWxid, $xbot, $clientId);
        }

        // 忽略超过1小时的消息
        if (isset($requestRawData['timestamp']) && $requestRawData['timestamp'] > 0 
            && now()->timestamp - $requestRawData['timestamp'] > 3600) {
            return null;
        }

        // 补充缺少的 from_wxid 和 to_wxid 字段
        $this->fillMissingWxidFields($msgType, $requestRawData, $wechatBot);

        // 联系人同步消息不需要from_wxid和to_wxid验证
        $contactSyncTypes = [
            // 'MT_TALKER_CHANGE_MSG',
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG', 
            'MT_DATA_PUBLICS_MSG',
            'MT_ROOM_CREATE_NOTIFY_MSG',
            'MT_DATA_CHATROOM_MEMBERS_MSG',//获取群联系人
        ];

        // 验证必要字段（联系人同步消息除外）
        if (!in_array($msgType, $contactSyncTypes) && !isset($requestRawData['from_wxid'], $requestRawData['to_wxid'])) {
            Log::debug("{$msgType} no from_wxid or to_wxid");
            return null;
        }

        // 忽略群消息检查
        $isRoom = $requestRawData['room_wxid'] ?? false;
        if ($isRoom) {
            $isHandleRoomMsg = $wechatBot->getMeta('room_msg_enabled', false);
            if (!$isHandleRoomMsg) {
                return null;
            }
        }

        // 验证消息ID：这些消息类型没有msgid但仍需要处理
        $messagesWithoutMsgid = [
            // 联系人同步消息
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG', 
            'MT_DATA_PUBLICS_MSG',
            'MT_DATA_CHATROOM_MEMBERS_MSG',
            'MT_ROOM_CREATE_NOTIFY_MSG',
            // 通知消息
            'MT_ROOM_ADD_MEMBER_NOTIFY_MSG',
            'MT_ROOM_DEL_MEMBER_NOTIFY_MSG',
            'MT_CONTACT_ADD_NOITFY_MSG',
            'MT_CONTACT_DEL_NOTIFY_MSG',
            'MT_ROOM_MEMBER_DISPLAY_UPDATE_NOTIFY_MSG',
            // 特殊操作消息
            'MT_ZOMBIE_CHECK_MSG',
            'MT_SEARCH_CONTACT_MSG',
        ];
        
        if (!isset($requestRawData['msgid']) && !in_array($msgType, $messagesWithoutMsgid)) {
            Log::error('消息无msgid且不在处理列表中', ['msgType' => $msgType, 'data' => $requestRawData]);
            return null;
        }

        // 处理联系人同步
        $this->xbotContactSyncProcessor->processContactSync($wechatBot, $requestRawData, $msgType);

        // 路由其他消息到相应处理管道
        return $this->xbotMessageRouter->routeMessage($wechatBot, $requestRawData, $msgType, $clientId);
    }

    private function processStateMessage(
        string $msgType, 
        array $requestRawData, 
        ?WechatBot $wechatBot,
        WechatClient $wechatClient,
        string $currentWindows,
        ?string $xbotWxid,
        XbotService $xbot,
        int $clientId
    ): mixed {
        switch ($msgType) {
            case 'MT_RECV_QRCODE_MSG':
                $handler = new QrCodeStateHandler($wechatClient, $currentWindows, $clientId);
                return $handler->handle($requestRawData);
                
            case 'MT_USER_LOGIN':
                $handler = new LoginStateHandler($wechatClient, $currentWindows, $clientId, $xbotWxid, $xbot);
                return $handler->handle($requestRawData);
                
            case 'MT_USER_LOGOUT':
                $handler = new LogoutStateHandler($wechatClient, $currentWindows, $clientId);
                return $handler->handle($wechatBot);
                
            case 'MT_DATA_OWNER_MSG':
                if ($wechatBot) {
                    $handler = new OwnerDataStateHandler();
                    $handler->handle($wechatBot, $clientId);
                }
                return null;
                
            default:
                Log::warning('Unknown state message type', ['msgType' => $msgType]);
                return null;
        }
    }

    /**
     * 为缺少wxid字段的消息类型补充必要的字段
     */
    private function fillMissingWxidFields(string $msgType, array &$requestRawData, ?WechatBot $wechatBot): void
    {
        if (!$wechatBot) {
            return;
        }

        switch ($msgType) {
            case 'MT_ROOM_ADD_MEMBER_NOTIFY_MSG':
            case 'MT_ROOM_CREATE_NOTIFY_MSG':
            case 'MT_ROOM_DEL_MEMBER_NOTIFY_MSG':
                // 群相关通知消息
                if (!isset($requestRawData['from_wxid'])) {
                    $requestRawData['from_wxid'] = $wechatBot->wxid;
                }
                if (!isset($requestRawData['to_wxid'])) {
                    $roomWxid = $requestRawData['room_wxid'] ?? null;
                    if ($roomWxid) {
                        $requestRawData['to_wxid'] = $roomWxid;
                    }
                }
                break;

            case 'MT_CONTACT_ADD_NOITFY_MSG':
            case 'MT_CONTACT_DEL_NOTIFY_MSG':
                // 联系人通知消息
                if (!isset($requestRawData['from_wxid'])) {
                    $requestRawData['from_wxid'] = $wechatBot->wxid;
                }
                if (!isset($requestRawData['to_wxid'])) {
                    // 尝试从其他字段获取目标wxid
                    $targetWxid = $requestRawData['target_wxid'] ?? $requestRawData['wxid'] ?? null;
                    if ($targetWxid) {
                        $requestRawData['to_wxid'] = $targetWxid;
                    }
                }
                break;

            case 'MT_ZOMBIE_CHECK_MSG':
            case 'MT_SEARCH_CONTACT_MSG':
                // 特殊操作消息
                if (!isset($requestRawData['from_wxid'])) {
                    $requestRawData['from_wxid'] = $wechatBot->wxid;
                }
                if (!isset($requestRawData['to_wxid'])) {
                    $targetWxid = $requestRawData['target_wxid'] ?? $requestRawData['wxid'] ?? null;
                    if ($targetWxid) {
                        $requestRawData['to_wxid'] = $targetWxid;
                    }
                }
                break;
        }
    }
}