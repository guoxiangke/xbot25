<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 文本消息处理器
 * 处理普通文本消息，提取和规范化文本内容
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

        // 将处理后的消息存储到上下文中，供后续处理器使用
        $context->setProcessedMessage($message);

        $this->log('Text message processed', [
            'content' => $message,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }


}
