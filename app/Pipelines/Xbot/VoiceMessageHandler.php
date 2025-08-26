<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 语音消息处理器
 * 处理 MT_RECV_VOICE_MSG 类型的语音消息
 */
class VoiceMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_VOICE_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $silkFile = $context->requestRawData['silk_file'] ?? '';

        $this->log('Voice message processed', [
            'silk_file' => $silkFile,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
