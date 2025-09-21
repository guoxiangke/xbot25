<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\XbotWebhookRequest;
use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Clients\XbotClient;
use App\Services\Processors\ContactSyncProcessor;
use App\Services\StateHandlers\QrCodeStateHandler;
use App\Services\StateHandlers\LoginStateHandler;
use App\Services\StateHandlers\LogoutStateHandler;
use App\Pipelines\Xbot\State\OwnerDataStateHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\State\ZombieCheckHandler;
use App\Pipelines\Xbot\Contact\FriendRequestHandler;
use App\Pipelines\Xbot\Contact\NotificationHandler;
use App\Pipelines\Xbot\Contact\SearchContactHandler;
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
use App\Pipelines\Xbot\Message\RoomAliasHandler;
use App\Pipelines\Xbot\Message\WebhookHandler;
use App\Pipelines\Xbot\Message\ChatwootHandler;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

/**
 * Xbot Webhook 控制器
 * 处理HTTP请求和消息调度业务逻辑
 */
class XbotController extends Controller
{
    private ContactSyncProcessor $contactSyncProcessor;

    public function __construct(ContactSyncProcessor $contactSyncProcessor)
    {
        $this->contactSyncProcessor = $contactSyncProcessor;
    }

    /**
     * 处理Xbot webhook请求
     */
    public function __invoke(XbotWebhookRequest $request, string $winToken)
    {
        try {
            $validatedData = $request->getValidatedData($winToken);
            $result = $this->dispatch($validatedData);

            return $this->createTextResponse($result);
            
        } catch (\Exception $e) {
            return $this->createTextResponse($e->getMessage());
        }
    }

    /**
     * 创建纯文本响应
     */
    private function createTextResponse($data): Response
    {
        $content = $data ?? 'ok';
        
        return new Response(
            $content,
            200,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    /**
     * 调度消息处理
     */
    private function dispatch(array $validatedData): mixed
    {
        extract($validatedData);
        $requestRawData = $requestAllData['data'] ?? [];

        // 客户端连接消息
        if ($msgType == 'MT_CLIENT_CONTECTED') {
            sleep(1);
            return "processed client connected";
        }

        // 状态消息类型
        $stateTypes = [
            'MT_RECV_QRCODE_MSG',
            'MT_USER_LOGIN',
            'MT_USER_LOGOUT',
            'MT_DATA_OWNER_MSG',
            'MT_CLIENT_DISCONTECTED'
        ];

        // 处理状态消息
        if (in_array($msgType, $stateTypes)) {
            // 客户端断开连接时需要创建新客户端
            if ($msgType == 'MT_CLIENT_DISCONTECTED') {
                $xbot->createNewClient();
                $msgType = 'MT_USER_LOGOUT';
            }

            return $this->processStateMessage($msgType, $requestRawData, $wechatBot, $wechatClient, $winToken, $xbotWxid, $xbot, $clientId);
        }

        // 忽略超过1小时的消息
        if (isset($requestRawData['time'])) {
            $messageTime = $requestRawData['time'];
            $currentTime = time();
            $timeDiff = $currentTime - $messageTime;
            
            if ($timeDiff > 3600) {
                Log::info(__FUNCTION__, [
                    'message_time' => date('Y-m-d H:i:s', $messageTime),
                    'current_time' => date('Y-m-d H:i:s', $currentTime),
                    'diff_seconds' => $timeDiff,
                    'msg_type' => $msgType,
                    'message' => '忽略超过1小时的消息'
                ]);
                return "ignored: message too old ($timeDiff seconds)";
            }
        }

        // 联系人同步相关消息
        $contactTypes = [
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG', 
            'MT_DATA_PUBLICS_MSG',
            'MT_DATA_WXID_MSG',
            'MT_DATA_CHATROOM_MEMBERS_MSG'
        ];

        if (in_array($msgType, $contactTypes)) {
            $this->contactSyncProcessor->processContactSync($wechatBot, $requestRawData, $msgType);
            return "processed contact sync: $msgType";
        }

        // 检查是否找到了WechatBot实例
        if (!$wechatBot) {
            Log::warning('无法找到WechatBot实例', [
                'msg_type' => $msgType,
                'client_id' => $clientId,
                'xbot_wxid' => $xbotWxid ?? 'null'
            ]);
            return "error: WechatBot not found for $msgType (client_id: $clientId)";
        }

        // 使用Pipeline处理其他消息
        return $this->processMessageThroughPipeline($wechatBot, $requestRawData, $requestAllData, $msgType, $clientId, $isRoom, $roomWxid);
    }

    /**
     * 处理状态消息
     */
    private function processStateMessage(
        string $msgType,
        array $requestRawData,
        ?WechatBot $wechatBot,
        WechatClient $wechatClient,
        string $winToken,
        ?string $xbotWxid,
        XbotClient $xbot,
        int $clientId
    ): mixed {
        return match ($msgType) {
            'MT_RECV_QRCODE_MSG' => (new QrCodeStateHandler($wechatClient, $winToken, $clientId))->handle($requestRawData),
            'MT_USER_LOGIN' => (new LoginStateHandler($wechatClient, $winToken, $clientId, $xbotWxid, $xbot))->handle($requestRawData['data'] ?? $requestRawData),
            'MT_USER_LOGOUT' => (new LogoutStateHandler($wechatClient, $winToken, $clientId))->handle($wechatBot),
            'MT_DATA_OWNER_MSG' => $this->handleOwnerDataMessage($wechatBot, $clientId),
            default => "processed state message: $msgType"
        };
    }

    /**
     * 处理机器人自身信息消息
     */
    private function handleOwnerDataMessage(?WechatBot $wechatBot, int $clientId): string
    {
        if (!$wechatBot) {
            Log::warning('Owner data message received but no WechatBot found', ['client_id' => $clientId]);
            return 'processed MT_DATA_OWNER_MSG without WechatBot';
        }

        // 直接调用OwnerDataStateHandler处理
        $handler = new OwnerDataStateHandler();
        $handler->handle($wechatBot, $clientId);
        
        return 'processed MT_DATA_OWNER_MSG';
    }

    /**
     * 通过Pipeline处理消息
     */
    private function processMessageThroughPipeline(
        WechatBot $wechatBot,
        array $requestRawData,
        array $requestAllData,
        string $msgType,
        int $clientId,
        bool $isRoom,
        ?string $roomWxid
    ): mixed {
        // 创建消息上下文
        $context = new XbotMessageContext($wechatBot, $requestRawData, $msgType, $clientId);

        // 群消息过滤检查
        if ($context->isRoom) {
            $configManager = new \App\Services\Managers\ConfigManager($wechatBot);
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
                    return 'ignored: group message filtered out';
                }
            }
        }

        // 第一阶段：状态管理 Pipeline
        app(Pipeline::class)
            ->send($context)
            ->through([ZombieCheckHandler::class])
            ->thenReturn();

        if ($context->isProcessed()) {
            return 'processed by state pipeline';
        }

        // 第二阶段：联系人管理 Pipeline
        app(Pipeline::class)
            ->send($context)
            ->through([
                NotificationHandler::class,
                FriendRequestHandler::class,
                SearchContactHandler::class,
            ])
            ->thenReturn();

        if ($context->isProcessed()) {
            return 'processed by contact pipeline';
        }

        // 第三阶段：消息内容处理 Pipeline
        app(Pipeline::class)
            ->send($context)
            ->through([
                BuiltinCommandHandler::class,
                SelfMessageHandler::class,
                PaymentMessageHandler::class,
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
                RoomAliasHandler::class,
                TextMessageHandler::class,
                KeywordResponseHandler::class,
                WebhookHandler::class,
                ChatwootHandler::class,
            ])
            ->thenReturn();

        return 'processed through message pipeline';
    }

    /**
     * 检查消息是否为群级别配置命令
     */
    private function isGroupConfigCommand(string $messageContent): bool
    {
        $trimmedMessage = trim($messageContent);

        // 群级别配置命令模式（支持多个空格和简化命令）
        $groupConfigPatterns = [
            // 原始命令
            '/^\/set\s+room_listen\s+[01]$/i',
            '/^\/config\s+room_listen\s+[01]$/i',
            '/^\/set\s+check_in_room\s+[01]$/i',
            '/^\/config\s+check_in_room\s+[01]$/i',
            '/^\/set\s+youtube_room\s+[01]$/i',
            '/^\/config\s+youtube_room\s+[01]$/i',
            // 简化命令
            '/^\/set\s+check_in\s+[01]$/i',
            '/^\/config\s+check_in\s+[01]$/i',
            '/^\/set\s+youtube\s+[01]$/i',
            '/^\/config\s+youtube\s+[01]$/i',
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
        $trimmedMessage = trim($messageContent);
        
        // 签到相关的消息模式
        $checkInPatterns = [
            '/^签到$/u',
            '/^打卡$/u', 
            '/^check\s*in$/i',
            '/^checkin$/i',
        ];
        
        foreach ($checkInPatterns as $pattern) {
            if (preg_match($pattern, $trimmedMessage)) {
                return true;
            }
        }
        
        return false;
    }
}