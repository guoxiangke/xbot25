<?php

namespace App\Pipelines\Xbot;

use App\Models\WechatBot;
use App\Services\Chatwoot;
use Closure;
use Illuminate\Support\Facades\Log;

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

        $msgid = $context->requestRawData['msgid'] ?? '';
        $text = $context->requestRawData['text'] ?? '';

        // 如果没有消息ID或转换文本，继续传递
        if (empty($msgid) || empty($text)) {
            $this->log('Voice trans message missing msgid or text', [
                'msgid' => $msgid,
                'has_text' => !empty($text),
            ]);
            return $next($context);
        }

        // 从缓存获取语音消息信息
        $voiceInfo = $this->getCachedVoiceMessageInfo($msgid);

        if (!$voiceInfo) {
            $this->log('No cached voice info found for msgid', ['msgid' => $msgid]);
            return $next($context);
        }

        try {
            // 组装最终的语音消息
            $finalMessage = $this->assembleVoiceMessage($text, $voiceInfo);

            // 发送到Chatwoot
            $this->sendToChatwoot($finalMessage, $voiceInfo);

            // 清理缓存
            $this->removeCachedVoiceMessageInfo($msgid);

            $this->log('Voice message with text sent to Chatwoot', [
                'msgid' => $msgid,
                'text' => $text,
                'voice_url' => $voiceInfo['voice_url'] ?? '',
            ]);

        } catch (\Exception $e) {
            $this->logError('Error processing voice trans message: ' . $e->getMessage(), [
                'msgid' => $msgid,
                'voice_info' => $voiceInfo,
                'exception' => $e->getMessage(),
            ]);
        }

        // 不再传递到下一个处理器，语音消息处理完成
        return null;
    }

    /**
     * 组装最终的语音消息
     *
     * @param string $text 转换后的文本
     * @param array $voiceInfo 语音信息
     * @return string
     */
    private function assembleVoiceMessage(string $text, array $voiceInfo): string
    {
        $voiceUrl = $voiceInfo['voice_url'];
        return "[语音消息]👉[点此收听]({$voiceUrl})👈\r\n 语音识别：{$text}";
    }

    /**
     * 发送消息到Chatwoot
     *
     * @param string $message 消息内容
     * @param array $voiceInfo 语音信息
     */
    private function sendToChatwoot(string $message, array $voiceInfo): void
    {
        $wechatBotId = $voiceInfo['wechat_bot_id'] ?? null;
        $fromWxid = $voiceInfo['from_wxid'] ?? '';
        $roomWxid = $voiceInfo['room_wxid'] ?? '';

        if (!$wechatBotId || empty($fromWxid)) {
            throw new \InvalidArgumentException('Missing required information for Chatwoot');
        }

        // 获取WeChatBot实例
        $wechatBot = WechatBot::find($wechatBotId);
        if (!$wechatBot) {
            throw new \InvalidArgumentException('WeChatBot not found');
        }

        // 检查Chatwoot是否启用
        $isChatwootEnabled = $wechatBot->getMeta('chatwoot_enabled', false);
        if (!$isChatwootEnabled) {
            $this->log('Chatwoot is disabled for this bot', ['wechat_bot_id' => $wechatBotId]);
            return;
        }

        // 创建Chatwoot服务实例
        $chatwoot = new Chatwoot($wechatBot);

        try {
            // 获取或创建Chatwoot联系人
            $contact = $chatwoot->searchContact($fromWxid);

            $isHost = false; // 接收消息，传到chatwoot

            if (!$contact) {
                // 从metadata中获取联系人信息
                $contacts = $wechatBot->getMeta('contacts', []);
                $contactData = $contacts[$fromWxid] ?? null;

                if ($contactData) {
                    $contact = $chatwoot->saveContact($contactData);
                } else {
                    // 创建基本联系人信息
                    $contact = $chatwoot->saveContact([
                        'wxid' => $fromWxid,
                        'nickname' => $fromWxid,
                        'remark' => $fromWxid,
                    ]);
                }
            }

            if ($contact) {
                // 发送消息到Chatwoot（参考TextMessageHandler的逻辑）
                $chatwoot->sendMessageAsContact($contact, $message, $isHost);

                $this->log('Voice message sent to Chatwoot successfully', [
                    'from_wxid' => $fromWxid,
                    'room_wxid' => $roomWxid,
                    'message' => $message,
                ]);
            } else {
                throw new \Exception('Failed to create contact');
            }

        } catch (\Exception $e) {
            $this->logError('Failed to send voice message to Chatwoot: ' . $e->getMessage(), [
                'from_wxid' => $fromWxid,
                'room_wxid' => $roomWxid,
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
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
}
