<?php

namespace App\Pipelines\Xbot\Message;

use App\Jobs\ChatwootHandleQueue;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
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
            $this->log('Message blocked from Chatwoot sync', [
                'content_preview' => substr($message, 0, 50),
                'is_from_bot' => $context->isFromBot,
                'from' => $context->fromWxid
            ]);
            return $next($context);
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

    /**
     * 检查消息是否应该同步到 Chatwoot
     */
    private function shouldSyncToChatwoot(XbotMessageContext $context, string $message): bool
    {
        // 非机器人消息始终同步
        if (!$context->isFromBot) {
            return true;
        }

        // 检查是否为关键词响应消息且关键词同步被禁用
        if ($this->isKeywordResponseMessage($message)) {
            $configManager = new XbotConfigManager($context->wechatBot);
            $isKeywordSyncEnabled = $configManager->isEnabled('keyword_sync');
            
            // 如果关键词同步被禁用，则不同步关键词响应消息
            return $isKeywordSyncEnabled;
        }

        // 其他机器人消息（命令响应、系统消息等）始终同步
        return true;
    }

    /**
     * 判断是否为关键词响应消息
     * 关键词响应消息通常有特定格式，如：【关键词】标题
     */
    private function isKeywordResponseMessage(string $message): bool
    {
        // 检查是否以【】格式开头，这是关键词响应的典型格式
        return preg_match('/^【.*?】/', $message);
    }
}