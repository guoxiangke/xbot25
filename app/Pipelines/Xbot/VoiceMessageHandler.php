<?php

namespace App\Pipelines\Xbot;

use Closure;

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
        $wechatClient = $this->getWechatClientFromContext($context);
        $wechatBot = $context->wechatBot;

        if ($wechatClient && $wechatBot && !empty($msgid)) {
            // 调用语音转文字
            $xbot = $context->wechatBot->xbot();
            $xbot->convertVoiceToText($msgid);

            // 处理已转换的MP3文件
            if (!empty($mp3File)) {
                // 替换本地路径为可访问的URL
                $voiceUrl = $this->convertLocalPathToUrl($mp3File, $wechatClient);
                
                // 缓存语音消息信息，等待转换结果
                $this->cacheVoiceMessageInfo($msgid, [
                    'voice_url' => $voiceUrl,
                    'from_wxid' => $fromWxid,
                    'to_wxid' => $toWxid,
                    'room_wxid' => $roomWxid,
                    'wechat_bot_id' => $wechatBot->id,
                    'timestamp' => time(),
                ]);
                
                $this->log('Voice message cached, waiting for text conversion', [
                    'msgid' => $msgid,
                    'voice_url' => $voiceUrl,
                    'from_wxid' => $fromWxid,
                ]);
            } else {
                $this->log('Voice message received but no mp3_file', [
                    'msgid' => $msgid,
                    'from_wxid' => $fromWxid,
                ]);
            }
        } else {
            $this->logError('Voice message processing failed - missing required data', [
                'msgid' => $msgid,
                'has_wechat_client' => !empty($wechatClient),
                'has_wechat_bot' => !empty($wechatBot),
            ]);
        }

        // 不再传递到下一个处理器（TextMessageHandler），直接返回
        return null;
    }


    /**
     * 将本地路径转换为可访问的URL
     *
     * @param string $localPath 本地文件路径
     * @param mixed $wechatClient 微信客户端
     * @return string 可访问的URL
     */
    private function convertLocalPathToUrl(string $localPath, $wechatClient): string
    {
        // 使用 wechat_client 的 file_path 作为前缀进行替换
        if (!empty($wechatClient->file_path) && str_starts_with($localPath, $wechatClient->file_path)) {
            $relativePath = substr($localPath, strlen($wechatClient->file_path));
            
            // 移除可能的前导反斜杠
            $relativePath = ltrim($relativePath, '\\');
            
            // 将反斜杠转换为正斜杠
            $relativePath = str_replace('\\', '/', $relativePath);
            
            // 使用 file_url 作为基础URL
            if (!empty($wechatClient->file_url)) {
                return rtrim($wechatClient->file_url, '/') . '/' . $relativePath;
            }
        }
        
        // 如果无法按预期替换，直接返回原始路径的URL格式
        return str_replace('\\', '/', $localPath);
    }

    /**
     * 缓存语音消息信息
     *
     * @param string $msgid 消息ID
     * @param array $voiceInfo 语音信息
     */
    private function cacheVoiceMessageInfo(string $msgid, array $voiceInfo): void
    {
        $cacheKey = "voice_message_{$msgid}";
        
        // 缓存5分钟，给语音转文字足够的时间
        \Illuminate\Support\Facades\Cache::put($cacheKey, $voiceInfo, now()->addMinutes(5));
    }

    /**
     * 从缓存获取语音消息信息
     *
     * @param string $msgid 消息ID
     * @return array|null
     */
    private function getCachedVoiceMessageInfo(string $msgid): ?array
    {
        $cacheKey = "voice_message_{$msgid}";
        
        return \Illuminate\Support\Facades\Cache::get($cacheKey);
    }

    /**
     * 删除缓存的语音消息信息
     *
     * @param string $msgid 消息ID
     */
    private function removeCachedVoiceMessageInfo(string $msgid): void
    {
        $cacheKey = "voice_message_{$msgid}";
        
        \Illuminate\Support\Facades\Cache::forget($cacheKey);
    }

    /**
     * 从Context中获取WechatClient
     */
    private function getWechatClientFromContext(XbotMessageContext $context)
    {
        try {
            return $context->wechatBot->wechatClient;
        } catch (\Exception $e) {
            $this->logError('Error getting WechatClient: ' . $e->getMessage());
            return null;
        }
    }
}
