<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 系统消息处理器
 * 处理 MT_RECV_SYSTEM_MSG 类型的系统消息
 */
class SystemMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {

        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_SYSTEM_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        // 转换为纯文本
        $textContent = "[系统消息] {$rawMsg}";
        $context->setContent($textContent);

        $this->log('System message processed', [
            'raw_msg' => $rawMsg,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
