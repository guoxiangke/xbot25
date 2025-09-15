<?php

namespace App\Pipelines\Xbot\Message;

use App\Jobs\ChatwootHandleQueue;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Closure;

/**
 * Chatwoot消息处理器
 * 将未被关键词响应拦截的消息发送到Chatwoot
 */
class ChatwootHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

        // 获取处理后的文本内容
        $message = $this->getProcessedMessage($context);
        
        if (empty($message)) {
            return $next($context);
        }

        // 检查是否应该同步到 Chatwoot
        if (!$this->shouldSyncToChatwoot($context, $message)) {
            $this->log(__FUNCTION__, ['message' => 'Message blocked from Chatwoot sync',
                'msgId' => $context->msgId,
                'is_from_bot' => $context->isFromBot,
                'from' => $context->fromWxid
            ]);
            return $next($context);
        }

        // 把消息存储到 chatwoot 中（通过队列异步处理）
        ChatwootHandleQueue::dispatch($context, $message);

        $this->log(__FUNCTION__, ['message' => 'Sent',
            'msgId' => $context->msgId,
            'origin_type' => $context->msgType,
            'from' => $context->fromWxid
        ]);

        return $next($context);
    }

    /**
     * 获取经过处理的消息内容
     */
    private function getProcessedMessage(XbotMessageContext $context): string
    {
        // 如果上下文中有处理后的消息，使用处理后的消息
        if (!empty($context->processedMessage)) {
            return $context->processedMessage;
        }

        // 对于文本消息，直接获取原始内容
        if ($context->msgType === 'MT_RECV_TEXT_MSG') {
            return trim($context->requestRawData['msg'] ?? '');
        }

        // 对于语音转文本消息，使用转换后的文本
        if ($context->msgType === 'MT_TRANS_VOICE_MSG' && $context->hasVoiceTransText()) {
            return $context->getProcessedMessage() ?: $context->getVoiceTransText();
        }

        return '';
    }

    /**
     * 检查消息是否应该同步到 Chatwoot
     */
    private function shouldSyncToChatwoot(XbotMessageContext $context, string $message): bool
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 检查是否启用了Chatwoot
        if (!$configManager->isEnabled('chatwoot')) {
            return false;
        }
        
        // 如果是群消息，需要检查群消息过滤配置
        if ($context->isRoom && !empty($context->roomWxid)) {
            $filter = new \App\Services\ChatroomMessageFilter($context->wechatBot, $configManager);
            
            // 使用群消息过滤器检查是否应该处理这个群的消息
            if (!$filter->shouldProcess($context->roomWxid, $message)) {
                return false;
            }
        }
        
        return true;
    }

}