<?php

namespace App\Pipelines\Xbot;

use App\Pipelines\Xbot\Contracts\XbotHandlerInterface;
use Closure;
use Illuminate\Support\Facades\Log;

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

    /**
     * 从 XML 消息中提取链接 URL
     * 参考 XbotCallbackController.php 第35-39行的逻辑
     */
    protected function extractUrlFromXml(string $rawMsg): ?string
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            // 检查是否包含 URL 标签
            if (!str_contains($rawMsg, '<url>')) {
                return null;
            }

            // 使用正则表达式提取 URL（支持多行和HTML实体）
            if (preg_match('/<url>(.*?)<\/url>/s', $rawMsg, $matches)) {
                $url = trim($matches[1]);
                if (!empty($url)) {
                    // 解码HTML实体（如 &amp; 转换为 &）
                    $url = html_entity_decode($url);
                    return $url;
                }
            }

            // 如果正则表达式失败，尝试使用字符串操作
            $urlStart = strpos($rawMsg, '<url>');
            $urlEnd = strpos($rawMsg, '</url>');
            
            if ($urlStart !== false && $urlEnd !== false && $urlEnd > $urlStart) {
                $url = substr($rawMsg, $urlStart + 5, $urlEnd - $urlStart - 5);
                $url = trim($url);
                if (!empty($url)) {
                    // 解码HTML实体
                    $url = html_entity_decode($url);
                    return $url;
                }
            }

            // 调试信息：记录无法解析的XML
            $this->logError('URL not found in XML structure', [
                'raw_msg_preview' => substr($rawMsg, 0, 200) . '...',
                'has_appmsg' => str_contains($rawMsg, '<appmsg>'),
                'has_url_tag' => str_contains($rawMsg, '<url>')
            ]);

        } catch (\Exception $e) {
            $this->logError('Error extracting URL from XML: ' . $e->getMessage(), [
                'raw_msg' => $rawMsg
            ]);
        }

        return null;
    }
}
