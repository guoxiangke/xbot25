<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 视频消息处理器
 * 处理 MT_RECV_VIDEO_MSG 类型的视频消息
 */
class VideoMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_VIDEO_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $videoData = $context->requestRawData['video'] ?? '';

        $this->log('Video message processed', [
            'video_data' => $videoData,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
