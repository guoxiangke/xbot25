<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 文件消息处理器
 * 处理 MT_RECV_FILE_MSG 类型的文件消息
 */
class FileMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_FILE_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $fileData = $context->requestRawData['file'] ?? '';

        $this->log('File message processed', [
            'file_data' => $fileData,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }
}
