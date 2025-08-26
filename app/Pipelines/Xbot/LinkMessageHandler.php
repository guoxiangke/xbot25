<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 链接消息处理器
 * 处理 MT_RECV_LINK_MSG 类型的链接消息
 */
class LinkMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_LINK_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $linkData = $context->requestRawData['link'] ?? '';
        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        $this->log('Link message processed', [
            'link_data' => $linkData,
            'raw_msg' => $rawMsg,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
