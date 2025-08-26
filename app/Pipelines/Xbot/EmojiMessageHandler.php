<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 表情消息处理器
 * 处理 MT_RECV_EMOJI_MSG 类型的表情消息
 */
class EmojiMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_EMOJI_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $emojiData = $context->requestRawData['emoji'] ?? '';

        $this->log('Emoji message processed', [
            'emoji_data' => $emojiData,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
