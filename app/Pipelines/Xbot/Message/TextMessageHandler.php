<?php

namespace App\Pipelines\Xbot\Message;

use App\Jobs\ChatwootHandleQueue;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 文本消息处理器
 * 处理普通文本消息，并存储到Chatwoot中
 */
class TextMessageHandler extends BaseXbotHandler
{

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return $next($context);
        }

        $message = trim($context->requestRawData['msg'] ?? '');
        // 繁体转简体

        $this->log('Text message processed', [
            'content' => $message,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        // 把消息存储到 chatwoot 中（通过队列异步处理）
        ChatwootHandleQueue::dispatch(
            $context->wechatBot,
            $context->wxid,
            $context->fromWxid,
            $message,
            $context->isFromBot,
            $context->isRoom
        );

        return $next($context);
    }


}
