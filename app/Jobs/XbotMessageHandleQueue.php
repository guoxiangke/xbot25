<?php

namespace App\Jobs;

use App\Models\WechatBot;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\Handlers\{
//    BuiltinCommandHandler,
//    SelfMessageHandler,
//    SystemMessageHandler,
//    ContactUpdateHandler,
//    RoomMemberChangeHandler,
//    FileMessageHandler,
//    VoiceMessageHandler,
//    PictureMessageHandler,
//    PaymentMessageHandler,
//    LocationMessageHandler,
    TextMessageHandler
//    AutoReplyHandler,
//    SubscriptionHandler,
//    ResourceHandler,
//    ChatwootIntegrationHandler,
//    MessageRecorderHandler
};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Pipeline\Pipeline;

class XbotMessageHandleQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public WechatBot $wechatBot;
    public array $requestRawData;

    public function __construct(WechatBot $wechatBot, array $requestRawData)
    {
        $this->wechatBot = $wechatBot;
        $this->requestRawData = $requestRawData;
    }

    public function handle(): void
    {
        $context = new XbotMessageContext($this->wechatBot, $this->requestRawData);

        // 定义消息处理管道 - 按优先级排序
        $pipeline = [
            BuiltinCommandHandler::class,     // 最高优先级：内置命令
            SelfMessageHandler::class,        // 自消息处理
            SystemMessageHandler::class,      // 系统消息
            ContactUpdateHandler::class,      // 联系人更新
            RoomMemberChangeHandler::class,   // 群成员变化
            FileMessageHandler::class,        // 文件消息
            VoiceMessageHandler::class,       // 语音消息
            PictureMessageHandler::class,     // 图片消息
            PaymentMessageHandler::class,     // 支付消息
            LocationMessageHandler::class,    // 位置消息
            TextMessageHandler::class,        // 文本消息基础处理
            AutoReplyHandler::class,          // 自动回复
            SubscriptionHandler::class,       // 订阅处理
            ResourceHandler::class,           // 资源处理
            ChatwootIntegrationHandler::class,// Chatwoot集成
            MessageRecorderHandler::class,    // 消息记录（最后执行）
        ];

        try {
            app(Pipeline::class)
                ->send($context)
                ->through($pipeline)
                ->then(function ($context) {
                    Log::debug(__CLASS__, [
                        'message' => 'Pipeline completed',
                        'context' => $context->toArray()
                    ]);
                });
        } catch (\Exception $e) {
            Log::error(__CLASS__, [
                'message' => 'Pipeline error',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
