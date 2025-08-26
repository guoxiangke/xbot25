<?php

namespace App\Pipelines\Xbot;

use App\Services\Chatwoot;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 文本消息处理器
 * 处理普通文本消息，并存储到Chatwoot中
 */
class TextMessageHandler extends BaseXbotHandler
{

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return $next($context);
        }

        $message = trim($context->requestRawData['msg'] ?? '');
        // 繁体转简体

        $this->log('Text message processed', [
            'content' => $message,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        // 把消息存储到 chatwoot 中
        $this->sendMessageToChatwoot($context, $message);

        return $next($context);
    }

    /**
     * 发送消息到Chatwoot
     * 重构自 XbotCallbackController 的 sendMessageToContact 逻辑
     */
    protected function sendMessageToChatwoot(XbotMessageContext $context, string $content): void
    {
        // 检查Chatwoot是否启用
        $isChatwootEnabled = $context->wechatBot->getMeta('chatwoot_enabled', false);
        if(!$isChatwootEnabled) return;

        // if($context->isFromBot) return;// 这样的话，bot通过windows微信客户端和手机端发送的信息，chatwoot上就没有记录了。
        // 避免通过UI发送的消息重复发送到Chatwoot
        $isSendByChatwootUI = Cache::get("chatwoot_outgoing_{$context->wechatBot->id}_{$context->wxid}") == $content;
        if($isSendByChatwootUI) return;

        try {
            $chatwoot = new Chatwoot($context->wechatBot);

            $wxid = $context->wxid;

            // 获取或创建Chatwoot联系人
            $contact = $chatwoot->searchContact($wxid);

            // $isHost = false, 接受消息，传到chatwoot
            // $isHost = true, 第一次创建对话，不发消息给微信用户，只记录到chatwoot
            $isHost = false;

            if (!$contact) {
                // 从metadata中获取联系人信息
                $contacts = $context->wechatBot->getMeta('contacts', []);
                $contactData = $contacts[$wxid] ?? null;

                if ($contactData) {
                    $contact = $chatwoot->saveContact($contactData);
                    $isHost = true; // 第一次创建对话

                    // 添加标签
                    $label = $this->getContactLabel($contactData);
                    if ($label) {
                        $chatwoot->setLabel($contact['id'], $label);
                    }
                } else {
                    $this->logError('Contact not found in metadata for wxid: ' . $wxid);
                    return;
                }
            }

            // 如果是群消息，添加发送者信息
            $fromWxid = $context->fromWxid;
            if ($context->isRoom && !$context->isFromBot) {
                $senderContacts = $context->wechatBot->getMeta('contacts', []);
                $senderData = $senderContacts[$fromWxid] ?? null;
                if ($senderData) {
                    $senderName = $senderData['remark'] ?? $senderData['nickname'] ?? $fromWxid;
                    $content .= "\r\n by {$senderName}";
                }
            }

            // 区分消息方向：机器人发送的消息 vs 接收的消息
            // incoming - 访客/用户发进来的消息 (作为联系人发送)
            // outgoing - 机器人/系统发出去的消息 (作为客服发送)
            if ($context->isFromBot) {
                // 机器人发送的消息：以客服身份发送
                $chatwoot->sendMessageAsAgentToContact($contact, $content);

            } else {
                // 接收的消息：以联系人身份发送
                $chatwoot->sendMessageAsContact($contact, $content, $isHost);
            }

            $this->log(__FILE__, [
                'msg'=>'Message sent to Chatwoot'
            ]);
        } catch (\Exception $e) {
            $this->logError('Failed to send message to Chatwoot: ' . $e->getMessage());
        }
    }

    /**
     * 获取联系人标签
     */
    protected function getContactLabel(array $contactData): string
    {
        $type = $contactData['type'] ?? 0;
        $labels = [
            1 => '好友',
            2 => '群聊',
            3 => '公众号'
        ];

        return $labels[$type] ?? '未知';
    }

}
