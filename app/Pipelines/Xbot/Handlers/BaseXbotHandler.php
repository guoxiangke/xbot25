<?php

namespace App\Pipelines\Xbot\Handlers;

use App\Pipelines\Xbot\Contracts\XbotHandlerInterface;
use App\Pipelines\Xbot\XbotMessageContext;
use Illuminate\Support\Facades\Log;
use Closure;

/**
 * Xbot消息处理器基类
 */
abstract class BaseXbotHandler implements XbotHandlerInterface
{
    /**
     * 处理消息的主要方法
     */
    abstract public function handle(XbotMessageContext $context, Closure $next);

    /**
     * 检查是否应该处理此消息
     */
    protected function shouldProcess(XbotMessageContext $context): bool
    {
        return !$context->isProcessed();
    }

    /**
     * 检查消息类型是否匹配
     */
    protected function isMessageType(XbotMessageContext $context, string|array $types): bool
    {
        $types = is_array($types) ? $types : [$types];
        return in_array($context->msgType, $types);
    }

    /**
     * 记录日志
     */
    protected function log(string $message, array $context = []): void
    {
        Log::debug(static::class, array_merge(['message' => $message], $context));
    }

    /**
     * 记录错误日志
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::error(static::class, array_merge(['message' => $message], $context));
    }

    /**
     * 发送文本消息的便捷方法
     */
    protected function sendTextMessage(XbotMessageContext $context, string $text, ?string $target = null): void
    {
        $target = $target ?? $context->getReplyTarget();
        $context->wechatBot->xbot()->sendTextMessage($target, $text);
    }

    /**
     * 设置缓存回复标记
     */
    protected function markAsReplied(XbotMessageContext $context, int $ttl = 30): void
    {
        cache()->put($context->isRepliedKey, true, $ttl);
    }
}
