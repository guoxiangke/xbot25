<?php

namespace App\Pipelines\Xbot\Message;

use App\Jobs\ChatwootHandleQueue;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
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

        // 检查关键词响应同步开关（只对Bot发出的消息进行检查）
        if ($context->isFromBot) {
            $isKeywordResponseSyncEnabled = $context->wechatBot->getMeta('keyword_response_sync_to_chatwoot_enabled', true);
            if (!$isKeywordResponseSyncEnabled) {
                return $next($context);
            }
        }

        // 把消息存储到 chatwoot 中（通过队列异步处理）
        ChatwootHandleQueue::dispatch(
            $context->wechatBot,
            $context->wxid,
            $context->fromWxid,
            $message,
            $context->isFromBot,
            $context->isRoom,
            $context->requestRawData['origin_msg_type'] ?? $context->msgType // 使用原始消息类型
        );

        $this->log('Message sent to Chatwoot queue', [
            'content_length' => strlen($message),
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
}