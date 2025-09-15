<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 语音消息处理器
 * 处理 MT_RECV_VOICE_MSG 类型的语音消息，转换为文本消息传递给 TextMessageHandler
 */
class VoiceMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_VOICE_MSG')) {
            return $next($context);
        }

        $mp3File = $context->requestRawData['mp3_file'] ?? '';
        $msgid = $context->requestRawData['msgid'] ?? '';
        $fromWxid = $context->requestRawData['from_wxid'] ?? '';
        $toWxid = $context->requestRawData['to_wxid'] ?? '';
        $roomWxid = $context->requestRawData['room_wxid'] ?? '';

        // 获取微信客户端和机器人信息
        $wechatClient = $context->wechatBot->wechatClient;
        $wechatBot = $context->wechatBot;

        // 调用语音转文字
        $xbot = $context->wechatBot->xbot();
        $xbot->convertVoiceToText($msgid);

        // 处理已转换的MP3文件
        if (!empty($mp3File)) {
            // 替换本地路径为可访问的URL
            $voiceUrl = str_replace($wechatClient->file_path, $wechatClient->file_url, $mp3File);
            $voiceUrl = str_replace('\\', '/', $voiceUrl);

            // 缓存语音消息信息，等待转换结果
            $cacheKey = "voice_message_{$msgid}";
            Cache::put($cacheKey, [
                'voice_url' => $voiceUrl,
                'from_wxid' => $fromWxid,
                'to_wxid' => $toWxid,
                'room_wxid' => $roomWxid,
                'wechat_bot_id' => $wechatBot->id,
                'timestamp' => time(),
            ], now()->addMinutes(1));

            $this->log(__FUNCTION__, ['message' => 'Voice message cached, waiting for text conversion',
                'msgid' => $msgid,
                'voice_url' => $voiceUrl,
                'from_wxid' => $fromWxid,
            ]);
        } else {
            $this->log(__FUNCTION__, ['message' => 'Voice message received but no mp3_file',
                'msgid' => $msgid,
                'from_wxid' => $fromWxid,
            ]);
        }

        // 不再传递到下一个处理器（TextMessageHandler），直接返回
        return null;
    }


}
