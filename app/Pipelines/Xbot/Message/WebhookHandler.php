<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 第三方 Webhook 转发处理器
 */
class WebhookHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        // 只处理机器人接收的消息，跳过机器人发送的消息
        if ($context->isSentByBot()) {
            return $next($context);
        }

        $this->sendWebhook($context);

        return $next($context);
    }

    /**
     * 发送 webhook
     */
    private function sendWebhook(XbotMessageContext $context): void
    {
        $webhookConfig = $context->wechatBot->getMeta('webhook', [
            'url' => '',
            'secret' => ''
        ]);

        $webhookUrl = $webhookConfig['url'] ?? '';
        $webhookSecret = $webhookConfig['secret'] ?? '';

        if (empty($webhookUrl)) {
            return;
        }

        $data = $this->buildWebhookData($context);

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Xbot-Webhook/1.0'
            ];

            // 如果配置了密钥，添加签名
            if (!empty($webhookSecret)) {
                $payload = json_encode($data);
                $signature = hash_hmac('sha256', $payload, $webhookSecret);
                $headers['X-Webhook-Signature'] = 'sha256=' . $signature;
            }

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($webhookUrl, $data);

            if ($response->successful()) {
                $this->log('Webhook sent successfully', [
                    'webhook_url' => $webhookUrl,
                    'bot_wxid' => $context->wechatBot->wxid,
                    'msg_type' => $context->msgType
                ]);
            } else {
                $this->logError('Webhook failed with HTTP error', [
                    'webhook_url' => $webhookUrl,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('Webhook request failed: ' . $e->getMessage(), [
                'webhook_url' => $webhookUrl,
                'bot_wxid' => $context->wechatBot->wxid,
                'exception' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 构建 webhook 数据
     */
    private function buildWebhookData(XbotMessageContext $context): array
    {
        $contact = $context->contact;
        $data = [
            'msgid' => $context->msgId,
            'type' => $context->msgType,
            'wxid' => $contact['wxid'] ?? '',
            'remark' => $contact['remark'] ?? '',
            'avatar' => $contact['avatar'] ?? '',
            'content' => $context->content,
            'timestamp' => $context->timestamp,
            'bot_wxid' => $context->wechatBot->wxid
        ];

        // 群消息时添加发送者信息
        if ($context->isRoomMessage() && !empty($context->fromContact)) {
            $data['from'] = $context->fromContact['wxid'] ?? '';
            $data['from_remark'] = $context->fromContact['remark'] ?? '';
            $data['room_wxid'] = $context->roomWxid;
        }

        return $data;
    }
}