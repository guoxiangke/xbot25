<?php

namespace App\Http\Controllers;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Xbot;
use App\Http\Requests\XbotRequest;
use App\Services\XbotServices\ContactSyncProcessor;
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

class XbotController extends Controller
{
    private $contactSyncProcessor;

    public function __construct(
        ContactSyncProcessor $contactSyncProcessor
    ) {
        $this->contactSyncProcessor = $contactSyncProcessor;
    }

    public function __invoke(XbotRequest $request, string $winToken)
    {
        try {
            // 验证和准备请求参数
            $validatedData = $request->validateAndPrepare($winToken);
            $result = $this->processMessage($validatedData);

            return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 200, [], JSON_UNESCAPED_UNICODE);
        }
    }

    public function processMessage(array $validatedData): mixed
    {
        extract($validatedData);
        $requestRawData = $requestAllData['data'] ?? [];

        // 客户端连接消息
        if ($msgType == 'MT_CLIENT_CONTECTED') {
            sleep(1);
            return null;
        }

        // 客户端断开连接
        if ($msgType == 'MT_CLIENT_DISCONTECTED') {
            $xbot->createNewClient();
            return $this->processStateMessage('MT_USER_LOGOUT', $requestRawData, $wechatBot, $wechatClient, $winToken, $xbotWxid, $xbot, $clientId);
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
            return $this->processStateMessage($msgType, $requestRawData, $wechatBot, $wechatClient, $winToken, $xbotWxid, $xbot, $clientId);
        }

        // 忽略超过1小时的消息
        if (isset($requestRawData['timestamp']) && $requestRawData['timestamp'] > 0
            && now()->timestamp - $requestRawData['timestamp'] > 3600) {
            return null;
        }

        // 联系人同步消息不需要from_wxid和to_wxid验证
        $contactSyncTypes = [
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG',
            'MT_DATA_PUBLICS_MSG',
            'MT_ROOM_CREATE_NOTIFY_MSG',
            'MT_DATA_CHATROOM_MEMBERS_MSG',
            'MT_DATA_WXID_MSG',
        ];

        // 验证必要字段（联系人同步消息除外）
        if (!in_array($msgType, $contactSyncTypes) && !isset($requestRawData['from_wxid'], $requestRawData['to_wxid'])) {
            Log::debug("{$msgType} no from_wxid or to_wxid");
            return null;
        }

        // 验证消息ID：这些消息类型没有msgid但仍需要处理
        $messagesWithoutMsgid = [
            // 联系人同步消息
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG',
            'MT_DATA_PUBLICS_MSG',
            'MT_DATA_CHATROOM_MEMBERS_MSG',
            'MT_ROOM_CREATE_NOTIFY_MSG',
            'MT_DATA_WXID_MSG',
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
        if (in_array($msgType, $contactSyncTypes)) {
            $this->contactSyncProcessor->processContactSync($wechatBot, $requestRawData, $msgType);
            return null;
        }

        // 忽略群消息（如果未启用群消息处理）
        $isRoom = $requestRawData['room_wxid'] ?? false;
        if ($isRoom) {
            $isHandleRoomMsg = $wechatBot->getMeta('room_msg_enabled', false);
            if (!$isHandleRoomMsg) {
                return null;
            }
        }

        // 路由其他消息到相应处理管道
        return $this->routeMessage($wechatBot, $requestRawData, $msgType, $clientId);
    }

    private function processStateMessage(
        string $msgType,
        array $requestRawData,
        ?WechatBot $wechatBot,
        WechatClient $wechatClient,
        string $winToken,
        ?string $xbotWxid,
        Xbot $xbot,
        int $clientId
    ): mixed {
        switch ($msgType) {
            case 'MT_RECV_QRCODE_MSG':
                $handler = new QrCodeStateHandler($wechatClient, $winToken, $clientId);
                return $handler->handle($requestRawData);

            case 'MT_USER_LOGIN':
                $handler = new LoginStateHandler($wechatClient, $winToken, $clientId, $xbotWxid, $xbot);
                return $handler->handle($requestRawData);

            case 'MT_USER_LOGOUT':
                $handler = new LogoutStateHandler($wechatClient, $winToken, $clientId);
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

    public function routeMessage(WechatBot $wechatBot, array $requestRawData, string $msgType, int $clientId): mixed
    {
        $context = new XbotMessageContext($wechatBot, $requestRawData, $msgType, $clientId);

        // 第一阶段：状态管理pipeline（处理系统状态）
        $stateHandlers = [
            ZombieCheckHandler::class,
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
            NotificationHandler::class,
            FriendRequestHandler::class,
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
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            PaymentMessageHandler::class,

            // 把各种消息类型处理后，都转换成纯文本的信息
            SystemMessageHandler::class,
            LocationMessageHandler::class,
            ImageMessageHandler::class,
            FileVideoMessageHandler::class,
            VoiceMessageHandler::class,
            VoiceTransMessageHandler::class,
            EmojiMessageHandler::class,
            LinkMessageHandler::class,
            OtherAppMessageHandler::class,
            TextMessageHandler::class,
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
