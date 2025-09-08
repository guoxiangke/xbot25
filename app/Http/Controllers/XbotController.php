<?php

namespace App\Http\Controllers;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Xbot;
use App\Http\Requests\XbotRequest;
use App\Services\XbotServices\ContactSyncProcessor;
use App\Services\XbotConfigManager;
use App\Services\XbotServices\State\QrCodeStateHandler;
use App\Services\XbotServices\State\LoginStateHandler;
use App\Services\XbotServices\State\LogoutStateHandler;
use App\Pipelines\Xbot\State\OwnerDataStateHandler;
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
use App\Pipelines\Xbot\Message\KeywordResponseHandler;
use App\Pipelines\Xbot\Message\SubscriptionHandler;
use App\Pipelines\Xbot\Message\TextMessageHandler;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use App\Pipelines\Xbot\Message\WebhookHandler;
use App\Pipelines\Xbot\Message\ChatwootHandler;
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
            // 新增加一个客户端，主动调用获取QR，压入缓存，以供web登陆
            // $xbot->loadQRCode();
            return null;
        }

        // 状态消息类型
        $stateTypes = [
            'MT_RECV_QRCODE_MSG',
            'MT_USER_LOGIN',
            'MT_USER_LOGOUT',
            'MT_DATA_OWNER_MSG',
            'MT_CLIENT_DISCONTECTED'// 客户端断开连接
        ];

        // 处理状态消息
        if (in_array($msgType, $stateTypes)) {
            // 客户端断开连接时需要创建新客户端
            if ($msgType == 'MT_CLIENT_DISCONTECTED') {
                $xbot->createNewClient();
                // 将断线处理为登出状态
                $msgType = 'MT_USER_LOGOUT';
            }

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
        $hasValidWxids = isset($requestRawData['from_wxid'], $requestRawData['to_wxid']);

        // 对于MT_TRANS_VOICE_MSG，数据可能在data中或直接在顶层
        if ($msgType === 'MT_TRANS_VOICE_MSG') {
            // 如果没有msgid和text，跳过
            $hasMsgId = isset($requestRawData['msgid']) || isset($requestRawData['data']['msgid']);
            $hasText = isset($requestRawData['text']) || isset($requestRawData['data']['text']);

            // 对于语音转文字消息，必须同时有msgid和text才处理
            // 没有msgid的是中间状态消息，没有text的是转换失败
            if (!$hasMsgId || !$hasText) {
                return null;
            }


            $hasValidWxids = true;
        }

        if (!in_array($msgType, $contactSyncTypes) && !$hasValidWxids) {
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

        // 检查消息ID
        $hasMsgId = isset($requestRawData['msgid']);

        // 对于MT_TRANS_VOICE_MSG，msgid可能在顶层或data中
        if ($msgType === 'MT_TRANS_VOICE_MSG') {
            $hasMsgId = isset($requestRawData['msgid']) || isset($requestRawData['data']['msgid']);
        }

        if (!$hasMsgId && !in_array($msgType, $messagesWithoutMsgid)) {
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
            $configManager = new XbotConfigManager($wechatBot);

            if (!$configManager->isEnabled('room_msg')) {
                // 检查是否为始终放行的命令
                $messageContent = $requestRawData['msg'] ?? $requestRawData['data']['msg'] ?? '';
                $filter = new \App\Services\ChatroomMessageFilter($wechatBot, $configManager);
                $roomWxid = $requestRawData['room_wxid'] ?? '';

                // 先检查基本的群消息过滤
                $basicFilterPassed = $filter->shouldProcess($roomWxid, $messageContent);
                
                if (!$basicFilterPassed) {
                    // 检查是否为群级别配置命令（这些命令需要始终放行）
                    $isGroupConfigCommand = $this->isGroupConfigCommand($messageContent);
                    
                    // 检查是否为签到消息且该群开启了签到
                    $checkInService = new \App\Services\CheckInPermissionService($wechatBot);
                    $isCheckInMessage = $this->isCheckInMessage($messageContent);
                    $canCheckIn = $checkInService->canCheckIn($roomWxid);
                    
                    // 如果是群配置命令或者是签到消息且该群可以签到，则放行
                    if (!$isGroupConfigCommand && !($isCheckInMessage && $canCheckIn)) {
                        return null;
                    }
                }
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
                $loginWxid = $requestRawData['wxid'];// 一定存在这个wxid
                $handler = new LoginStateHandler($wechatClient, $winToken, $clientId, $loginWxid, $xbot);
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

        // 群消息过滤检查
        if ($context->isRoom) {
            $configManager = new \App\Services\XbotConfigManager($wechatBot);
            $filter = new \App\Services\ChatroomMessageFilter($wechatBot, $configManager);
            $messageContent = $requestRawData['msg'] ?? $requestRawData['data']['msg'] ?? '';

            // 先检查基本的群消息过滤
            $basicFilterPassed = $filter->shouldProcess($context->roomWxid, $messageContent);
            
            // 如果基本过滤不通过，检查是否为特殊消息需要放行
            if (!$basicFilterPassed) {
                // 检查是否为群级别配置命令（这些命令需要始终放行）
                $isGroupConfigCommand = $this->isGroupConfigCommand($messageContent);
                
                // 检查是否为签到消息且该群开启了签到
                $checkInService = new \App\Services\CheckInPermissionService($wechatBot);
                $isCheckInMessage = $this->isCheckInMessage($messageContent);
                $canCheckIn = $checkInService->canCheckIn($context->roomWxid);
                
                // 如果是群配置命令或者是签到消息且该群可以签到，则放行
                if ($isGroupConfigCommand || ($isCheckInMessage && $canCheckIn)) {
                    // 允许处理
                } else {
                    return null;
                }
            }
        }

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
            SubscriptionHandler::class,
            CheckInMessageHandler::class,
            TextMessageHandler::class,
            KeywordResponseHandler::class,
            WebhookHandler::class,
            ChatwootHandler::class,
        ];

        app(Pipeline::class)
            ->send($context)
            ->through($messageHandlers)
            ->thenReturn();

        return null;
    }

    /**
     * 检查消息是否为群级别配置命令
     */
    private function isGroupConfigCommand(string $messageContent): bool
    {
        $trimmedMessage = trim($messageContent);
        
        // 群级别配置命令模式（支持多个空格）
        $groupConfigPatterns = [
            '/^\/set\s+room_listen\s+[01]$/i',
            '/^\/config\s+room_listen\s+[01]$/i',
            '/^\/set\s+check_in_room\s+[01]$/i',
            '/^\/config\s+check_in_room\s+[01]$/i',
            '/^\/set\s+youtube_room\s+[01]$/i',
            '/^\/config\s+youtube_room\s+[01]$/i',
        ];

        foreach ($groupConfigPatterns as $pattern) {
            if (preg_match($pattern, $trimmedMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查消息是否为签到相关消息
     */
    private function isCheckInMessage(string $messageContent): bool
    {
        $checkInKeywords = [
            'qd', 'Qd', 'qiandao', 'Qiandao', '签到', '簽到',
            'dk', 'Dk', 'Daka', 'daka', '打卡',
            '已读', '已看', '已讀', '已听', '已聽', '已完成',
            '报名', '報名', 'bm', 'Bm', 'baoming', 'Baoming',
            '打卡排行', '我的打卡'
        ];

        $trimmedMessage = trim($messageContent);
        return in_array($trimmedMessage, $checkInKeywords);
    }
}
