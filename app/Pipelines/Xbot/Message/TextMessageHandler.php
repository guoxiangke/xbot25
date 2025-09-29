<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Str;

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
        
        // 检查是否为配置命令，如果是且用户无权限，则给出提示
        $isConfigCmd = $this->isConfigCommand($message);
        if ($isConfigCmd && !$context->isFromBot) {
            $this->sendTextMessage($context, "⚠️ 无权限执行配置命令，仅机器人管理员可用");
            $context->markAsProcessed(static::class);
            return $context;
        }
        
        // 繁体转简体

        // 将处理后的消息存储到上下文中，供后续处理器使用
        $context->setProcessedMessage($message);

        // 获取消息ID用于日志记录
        $messageId = $context->requestRawData['msgid'] ?? 'unknown';

        $this->log(__FUNCTION__, ['message' => 'Processed',
            'msgId' => $messageId,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 检查是否为配置命令
     */
    private function isConfigCommand(string $message): bool
    {
        $normalizedMessage = strtolower(trim($message));
        
        // 群级别配置命令列表（这些命令只能在群里由机器人执行）
        $groupLevelCommands = [
            'room_msg', 'check_in', 'youtube_room',
            // 别名
            'room_listen', 'check_in_room', 'youtube'
        ];
        
        // 解析命令参数
        $parts = array_values(array_filter(preg_split('/\s+/', trim($message)), 'strlen'));
        
        // 检查 /set 命令（必须有key和value）
        if (Str::startsWith($normalizedMessage, '/set ') && count($parts) >= 3) {
            $key = $parts[1] ?? '';
            // 如果是群级别配置命令，则不拦截（让 SelfMessageHandler 处理）
            return !in_array($key, $groupLevelCommands);
        }
        
        // 检查 /config <key> <value> 格式（包含3个或更多单词）
        if (Str::startsWith($normalizedMessage, '/config ') && count($parts) >= 3) {
            $key = $parts[1] ?? '';
            // 如果是群级别配置命令，则不拦截（让 SelfMessageHandler 处理）
            return !in_array($key, $groupLevelCommands);
        }
        
        return false;
    }

}
