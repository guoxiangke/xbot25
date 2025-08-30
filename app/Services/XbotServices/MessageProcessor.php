<?php

namespace App\Services\XbotServices;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Xbot;
use App\Services\XbotServices\State\QrCodeStateHandler;
use App\Services\XbotServices\State\LoginStateHandler;
use App\Services\XbotServices\State\LogoutStateHandler;
use App\Services\XbotServices\State\OwnerDataStateHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\State\ZombieCheckHandler;
use App\Pipelines\Xbot\Contact\FriendRequestHandler;
use App\Pipelines\Xbot\Contact\NotificationHandler;
use App\Pipelines\Xbot\Message\SystemMessageHandler;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\Message\BuiltinCommandHandler;
use App\Pipelines\Xbot\Message\VoiceMessageHandler;
use App\Pipelines\Xbot\Message\VoiceTransMessageHandler;
use App\Pipelines\Xbot\Message\ImageMessageHandler;
use App\Pipelines\Xbot\Message\EmojiMessageHandler;
use App\Pipelines\Xbot\Message\LinkMessageHandler;
use App\Pipelines\Xbot\Message\PaymentMessageHandler;
use App\Pipelines\Xbot\Message\FileVideoMessageHandler;
use App\Pipelines\Xbot\Message\LocationMessageHandler;
use App\Pipelines\Xbot\Message\OtherAppMessageHandler;
use App\Pipelines\Xbot\Message\TextMessageHandler;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;

/**
 * Xbot 消息处理器
 * 负责处理各种类型的消息
 */
class MessageProcessor
{
    private $contactSyncProcessor;

    public function __construct(
        ContactSyncProcessor $contactSyncProcessor
    ) {
        $this->contactSyncProcessor = $contactSyncProcessor;
    }

    public function processMessage(
        ?WechatBot $wechatBot, 
        array $requestRawData, 
        string $msgType,
        WechatClient $wechatClient,
        string $currentWindows,
        ?string $xbotWxid,
        Xbot $xbot,
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
        $this->contactSyncProcessor->processContactSync($wechatBot, $requestRawData, $msgType);

        // 路由其他消息到相应处理管道
        return $this->routeMessage($wechatBot, $requestRawData, $msgType, $clientId);
    }

    private function processStateMessage(
        string $msgType, 
        array $requestRawData, 
        ?WechatBot $wechatBot,
        WechatClient $wechatClient,
        string $currentWindows,
        ?string $xbotWxid,
        Xbot $xbot,
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

    public function routeMessage(WechatBot $wechatBot, array $requestRawData, string $msgType, int $clientId): mixed
    {
        $context = new XbotMessageContext($wechatBot, $requestRawData, $msgType, $clientId);
        
        // 第一阶段：状态管理pipeline（处理系统状态）
        $stateHandlers = [
            ZombieCheckHandler::class,        // 僵尸粉检测处理
        ];

        $stateResult = app(Pipeline::class)
            ->send($context)
            ->through($stateHandlers)
            ->thenReturn();

        // 如果状态处理完成，直接返回
        if ($stateResult->isProcessed()) {
            return null;
        }

        // 第二阶段：联系人管理pipeline（处理联系人和关系）
        $contactHandlers = [
            NotificationHandler::class,       // 群成员变更通知
            FriendRequestHandler::class,      // 好友请求处理
        ];

        $contactResult = app(Pipeline::class)
            ->send($context)
            ->through($contactHandlers)
            ->thenReturn();

        // 如果联系人处理完成，直接返回
        if ($contactResult->isProcessed()) {
            return null;
        }

        // 第三阶段：消息内容处理pipeline（处理具体消息内容）
        $messageHandlers = [
            BuiltinCommandHandler::class,     // 内置命令
            SelfMessageHandler::class,        // 自消息处理
            PaymentMessageHandler::class,     // 微信支付消息处理

            // 把各种消息类型处理后，都转换成纯文本的信息
            SystemMessageHandler::class,      // 系统消息
            LocationMessageHandler::class,    // 位置消息处理
            ImageMessageHandler::class,       // 图片消息
            FileVideoMessageHandler::class,   // 文件/视频消息
            VoiceMessageHandler::class,       // 语音消息
            VoiceTransMessageHandler::class,  // 语音转换结果消息
            EmojiMessageHandler::class,       // 表情消息
            LinkMessageHandler::class,        // 链接消息
            OtherAppMessageHandler::class,    // 其他应用消息
            TextMessageHandler::class,        // 文本消息（最后执行）
        ];

        app(Pipeline::class)
            ->send($context)
            ->through($messageHandlers)
            ->then(function ($context) {
                Log::debug('Message pipeline completed', [
                    'context' => $context->toArray()
                ]);
            });

        return null;
    }
}