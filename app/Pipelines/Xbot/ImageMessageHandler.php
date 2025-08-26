<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 图片消息处理器
 * 处理 MT_RECV_PICTURE_MSG 类型的图片消息
 */
class ImageMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_PICTURE_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $imageData = $context->requestRawData['image'] ?? '';

        // 转换为纯文本
        $textContent = "[图片消息] {$imageData}";
        $context->setContent($textContent);

        $this->log('Image message processed', [
            'image_data' => $imageData,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
