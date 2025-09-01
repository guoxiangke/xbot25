<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 关键词响应处理器
 * 处理文本消息和语音转文本消息中的关键词，响应对应资源
 */
class KeywordResponseHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

        // 只处理文本消息和语音转文本消息
        if (!$this->isMessageType($context, ['MT_RECV_TEXT_MSG', 'MT_TRANS_VOICE_MSG'])) {
            return $next($context);
        }

        // 不响应自己的消息，避免死循环
        if ($context->isFromBot) {
            return $next($context);
        }

        // 检查是否已经响应过（防止重复响应）
        $cacheKey = "keyword_replied:{$context->wechatBot->wxid}:{$context->wxid}:" . md5($context->requestRawData['msg'] ?? '');
        if (Cache::get($cacheKey, false)) {
            return $next($context);
        }

        // 提取并预处理消息内容
        $content = $this->extractMessageContent($context);
        if (empty($content)) {
            return $next($context);
        }

        // 处理关键词
        $keyword = $this->preprocessKeyword($content);
        
        // 获取资源
        $resource = $context->wechatBot->getResouce($keyword);
        
        if ($resource) {
            // 发送资源响应
            $this->sendKeywordResponse($context, $resource);
            
            // 标记已响应，10秒内不重复响应
            Cache::put($cacheKey, true, 10);
            
            $this->log('Keyword response sent', [
                'keyword' => $keyword,
                'to' => $context->wxid
            ]);
            
            // 关键词响应后继续处理，让原始消息也发送到Chatwoot
            return $next($context);
        }

        // 没有匹配的关键词，继续到下一个处理器
        return $next($context);
    }

    /**
     * 提取消息内容
     */
    private function extractMessageContent(XbotMessageContext $context): string
    {
        if ($context->msgType === 'MT_RECV_TEXT_MSG') {
            return $context->requestRawData['msg'] ?? '';
        }
        
        if ($context->msgType === 'MT_TRANS_VOICE_MSG') {
            // 直接从消息数据中获取转换后的文本（可能在顶层或data中）
            return $context->requestRawData['text'] ?? $context->requestRawData['data']['text'] ?? '';
        }
        
        return '';
    }

    /**
     * 预处理关键词
     * 去除左右空格，未来可扩展添加繁体转简体等处理
     */
    private function preprocessKeyword(string $content): string
    {
        // 基础处理：去除左右空格
        $keyword = trim($content);
        
        // TODO: 未来在此添加更多处理逻辑
        // - 繁体转简体
        // - 其他文本规范化处理
        
        return $keyword;
    }

    /**
     * 发送关键词响应
     */
    private function sendKeywordResponse(XbotMessageContext $context, array $resource): void
    {
        // 使用WechatBot的send方法发送资源
        $context->wechatBot->send([$context->wxid], $resource);
        
        // 发送附加内容
        if (isset($resource['addition'])) {
            $context->wechatBot->send([$context->wxid], $resource['addition']);
        }
    }
}