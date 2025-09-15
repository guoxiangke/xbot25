<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 语音转换结果处理器
 * 处理 MT_TRANS_VOICE_MSG 类型的语音转换结果，组装完整消息发送到Chatwoot
 */
class VoiceTransMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_TRANS_VOICE_MSG')) {
            return $next($context);
        }

        // MT_TRANS_VOICE_MSG的数据可能在data中或直接在顶层
        $msgid = $context->requestRawData['msgid'] ?? $context->requestRawData['data']['msgid'] ?? '';
        $text = $context->requestRawData['text'] ?? $context->requestRawData['data']['text'] ?? '';

        // 如果没有消息ID或转换文本，继续传递
        if (empty($msgid) || empty($text)) {
            $this->log(__FUNCTION__, ['message' => 'Voice trans message missing msgid or text',
                'msgid' => $msgid,
                'has_text' => !empty($text),
            ]);
            return $next($context);
        }

        // 标记语音已转为文本，并设置转换后的文本
        $context->markVoiceTransProcessed($text);
        
        // 从缓存获取语音消息信息
        $cacheKey = "voice_message_{$msgid}";
        $voiceInfo = Cache::get($cacheKey);
        
        if ($voiceInfo) {
            // 如果有缓存信息，使用缓存中的URL
            $voiceUrl = $voiceInfo['voice_url'];
            // 清理缓存
            Cache::forget($cacheKey);
        } else {
            // MT_TRANS_VOICE_MSG本身不包含文件路径，使用简化格式
            $finalMessage = "【语音消息】{$text}";
            $context->setProcessedMessage($finalMessage);
            return $next($context);
        }
        
        // 组装最终的语音消息
        $finalMessage = "[语音消息]👉[点此收听]({$voiceUrl})👈\r\n 语音识别：{$text}";
        
        $context->setProcessedMessage($finalMessage);

        $this->log(__FUNCTION__, ['message' => 'Converted',
            'msgid' => $msgid,
            'text' => $text,
            'has_cached_info' => !empty($voiceInfo),
        ]);

        // 继续传递到下一个处理器进行关键词检查
        return $next($context);
    }

}
