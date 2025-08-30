<?php

namespace App\Services\XbotServices;

use App\Models\WechatBot;
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
 * Xbot 消息路由处理器
 * 负责将消息路由到正确的处理管道
 */
class MessageRouter
{
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