<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        // 提取并预处理消息内容
        $content = $this->extractMessageContent($context);
        if (empty($content)) {
            return $next($context);
        }

        // 检查是否为 YouTube 链接
        if ($this->isYouTubeLink($content)) {
            return $this->handleYouTubeLink($context, $content, $next);
        }

        // 检查资源系统是否启用
        $configManager = new ConfigManager($context->wechatBot);
        if (!$configManager->isEnabled('keyword_resources')) {
            return $next($context);
        }

        // 处理关键词
        $keyword = $this->preprocessKeyword($content);

        // 获取资源
        $resource = $context->wechatBot->getResouce($keyword);

        if ($resource) {
            // 发送资源响应
            $this->sendKeywordResponse($context, $resource);

            $this->log(__FUNCTION__, ['message' => 'Keyword response sent',
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
     * 检查是否为 YouTube 链接
     */
    private function isYouTubeLink(string $content): bool
    {
        return Str::contains($content, ['youtube.', 'youtu.be']);
    }

    /**
     * 处理 YouTube 链接
     */
    private function handleYouTubeLink(XbotMessageContext $context, string $content, Closure $next)
    {
        // 检查是否在允许的群组或用户中
        if (!$this->isYouTubeAllowed($context)) {
            // 不在允许的群组中，直接跳过，不响应
            return $next($context);
        }

        // 获取 YouTube 资源响应
        $resource = $context->wechatBot->getResouce($content);

        if ($resource) {
            // 发送资源响应
            $this->sendKeywordResponse($context, $resource);

            $this->log(__FUNCTION__, ['message' => 'YouTube link response sent',
                'content' => $content,
                'to' => $context->wxid,
                'is_room' => $context->isRoom
            ]);

            // YouTube 响应后继续处理，让原始消息也发送到Chatwoot
            return $next($context);
        }

        // 没有匹配的资源，继续处理
        return $next($context);
    }

    /**
     * 检查是否允许响应 YouTube 链接
     */
    private function isYouTubeAllowed(XbotMessageContext $context): bool
    {
        // 获取 YouTube 允许的群组列表
        $allowedRooms = $context->wechatBot->getMeta('youtube_allowed_rooms', [
            "26570621741@chatroom",
            "18403467252@chatroom",  // Youtube精选
            "34974119368@chatroom",
            "57526085509@chatroom",  // LFC活力生命
            "58088888496@chatroom",  // 活泼的生命
            "57057092201@chatroom",  // 每天一章
            "51761446745@chatroom",  // Linda
        ]);

        // 获取 YouTube 允许的用户列表
        $allowedUsers = $context->wechatBot->getMeta('youtube_allowed_users', ['keke302']);

        // 检查群消息
        if ($context->isRoom) {
            return in_array($context->roomWxid, $allowedRooms);
        }

        // 检查私聊消息
        return in_array($context->fromWxid, $allowedUsers);
    }

    /**
     * 发送关键词响应
     */
    private function sendKeywordResponse(XbotMessageContext $context, array $resource): void
    {
        // 标记为关键词响应消息
        $resource['is_keyword_response'] = true;
        
        // 使用WechatBot的send方法发送资源
        $context->wechatBot->send([$context->wxid], $resource);

        // 递归发送所有附加内容
        $this->sendAdditions($context, $resource);
    }

    /**
     * 递归发送附加内容
     */
    private function sendAdditions(XbotMessageContext $context, array $resource): void
    {
        if (isset($resource['addition'])) {
            $addition = $resource['addition'];
            
            // 标记为关键词响应消息
            $addition['is_keyword_response'] = true;
            
            // 发送当前附加内容
            $context->wechatBot->send([$context->wxid], $addition);
            
            // 递归处理嵌套的附加内容
            $this->sendAdditions($context, $addition);
        }
    }
}
