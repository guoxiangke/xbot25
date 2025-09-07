<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
use Closure;
use Illuminate\Support\Str;

/**
 * 自消息处理器
 * 处理机器人发给自己的消息（系统指令）
 */
class SelfMessageHandler extends BaseXbotHandler
{

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) || !$context->isSelfToSelf) {
            return $next($context);
        }

        if ($this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            $msg = $context->requestRawData['msg'] ?? '';

            if (Str::startsWith($msg, '/set ')) {
                $this->handleSetCommand($context, $msg);
                // 继续传递到下游处理器（如ChatwootHandler），让命令也同步到Chatwoot
                return $next($context);
            }

            // 同时支持 /config <key> <value> 格式
            if (Str::startsWith($msg, '/config ') && str_word_count(trim($msg)) >= 3) {
                $this->handleSetCommand($context, $msg);
                // 继续传递到下游处理器（如ChatwootHandler），让命令也同步到Chatwoot
                return $next($context);
            }
        }

        return $next($context);
    }

    /**
     * 处理设置命令（支持 /set 和 /config 两种格式）
     */
    private function handleSetCommand(XbotMessageContext $context, string $message): void
    {
        $parts = explode(' ', $message);

        if (count($parts) < 3) {
            $commandFormat = Str::startsWith($message, '/config') ? '/config' : '/set';
            $this->sendTextMessage($context, "用法: {$commandFormat} <key> <value>\n例如: {$commandFormat} room_msg 1");
            return;
        }

        $key = $parts[1];
        $value = $parts[2];

        // 允许处理的设置项（从 XbotConfigManager 获取所有可用配置）
        $allowedKeys = XbotConfigManager::getAvailableCommands();
        if (!in_array($key, $allowedKeys)) {
            $this->sendTextMessage($context, "未知的设置项: $key\n目前支持: " . implode(', ', $allowedKeys));
            return;
        }

        // 解析值：支持 0/1, ON/OFF, true/false
        $boolValue = $this->parseBooleanValue($value);

        if ($boolValue === null) {
            $this->sendTextMessage($context, "无效的值: $value\n请使用: 0/1, ON/OFF, true/false");
            return;
        }

        // 'chatwoot_enabled'
        // 'room_msg_enabled' ...
        $metaKey = "{$key}_enabled";
        $context->wechatBot->setMeta($metaKey, $boolValue);
        $status = $boolValue ? '已启用' : '已禁用';

        $this->sendTextMessage($context, "设置成功: $key $status");
        $this->markAsReplied($context);
    }

    /**
     * 解析布尔值
     */
    private function parseBooleanValue(string $value): ?bool
    {
        $value = strtolower(trim($value));

        $trueValues = ['1', 'on', 'true', 'yes', 'enable'];
        $falseValues = ['0', 'off', 'false', 'no', 'disable'];

        if (in_array($value, $trueValues)) {
            return true;
        }

        if (in_array($value, $falseValues)) {
            return false;
        }

        return null;
    }

}
