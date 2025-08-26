<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 其他应用消息处理器
 * 处理 MT_RECV_OTHER_APP_MSG 类型的其他应用消息
 */
class OtherAppMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_OTHER_APP_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $wxType = $context->requestRawData['wx_type'] ?? '';
        $wxSubType = $context->requestRawData['wx_sub_type'] ?? '';
        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        $this->log('Other app message processed', [
            'wx_type' => $wxType,
            'wx_sub_type' => $wxSubType,
            'raw_msg' => $rawMsg,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
