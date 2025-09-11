<?php

namespace App\Jobs;

use App\Models\WechatBot;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Chatwoot;
use App\Services\XbotConfigManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatwootHandleQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $wechatBot;
    public $wxid;
    public $fromWxid;
    public $content;
    public $isFromBot;
    public $isRoom;
    public $originMsgType;
    public $msgId;
    protected Chatwoot $chatwoot;

    public function __construct(XbotMessageContext $context, string $content)
    {
        $this->wechatBot = $context->wechatBot;
        $this->wxid = $context->wxid;
        $this->fromWxid = $context->fromWxid;
        $this->content = $content;
        $this->isFromBot = $context->isFromBot;
        $this->isRoom = $context->isRoom;
        $this->originMsgType = $context->requestRawData['origin_msg_type'] ?? $context->msgType;
        $this->msgId = $context->msgId;
    }

    public function handle()
    {
        // 检查Chatwoot是否启用
        $configManager = new XbotConfigManager($this->wechatBot);
        $isChatwootEnabled = $configManager->isEnabled('chatwoot');
        if (!$isChatwootEnabled) return;

        // 避免通过UI发送的消息重复发送到Chatwoot
        $isSendByChatwootUI = Cache::get("chatwoot_outgoing_{$this->wechatBot->id}_{$this->wxid}") == $this->content;
        if ($isSendByChatwootUI) return;

        // 避免通过UI发送的附件重复发送到Chatwoot
        $isImageFromChatwoot = Cache::get("chatwoot_outgoing_attachment_{$this->wechatBot->id}_{$this->wxid}_image");
        $isAudioFromChatwoot = Cache::get("chatwoot_outgoing_attachment_{$this->wechatBot->id}_{$this->wxid}_audio");
        $isFileFromChatwoot = Cache::get("chatwoot_outgoing_attachment_{$this->wechatBot->id}_{$this->wxid}_file");
        $isVideoFromChatwoot = Cache::get("chatwoot_outgoing_attachment_{$this->wechatBot->id}_{$this->wxid}_video");

        // 检查消息内容是否为处理后的附件格式
        if (($isImageFromChatwoot && str_contains($this->content, '[图片消息]')) ||
            ($isAudioFromChatwoot && str_contains($this->content, '[音频消息]')) ||
            ($isFileFromChatwoot && str_contains($this->content, '[文件消息]')) ||
            ($isVideoFromChatwoot && str_contains($this->content, '[视频消息]'))) {
            return;
        }

        $this->chatwoot = new Chatwoot($this->wechatBot);

        // 获取或创建Chatwoot联系人
        $contact = $this->chatwoot->searchContact($this->wxid);

        // $isHost = false, 接受消息，传到chatwoot
        // $isHost = true, 第一次创建对话，不发消息给微信用户，只记录到chatwoot
        $isHost = false;

        if (!$contact) {
            // 从metadata中获取联系人信息
            $contacts = $this->wechatBot->getMeta('contacts', []);
            $contactData = $contacts[$this->wxid] ?? null;

            if ($contactData) {
                $contact = $this->chatwoot->saveContact($contactData);
                $isHost = true; // 第一次创建对话

                // 添加标签
                $label =  WechatBot::getContactTypeLabel($contactData['type'] ?? 0);
                if ($label) {
                    $this->chatwoot->setLabel($contact['id'], $label);
                }
            } else {
                Log::error('Contact not found in metadata for wxid: ' . $this->wxid);
                return;
            }
        }

        $content = $this->content;

        // 如果是群消息，添加发送者信息
        if ($this->isRoom && !$this->isFromBot) {
            $senderContacts = $this->wechatBot->getMeta('contacts', []);
            $senderData = $senderContacts[$this->fromWxid] ?? null;

            // 获取发送者名称：优先使用备注名 > 昵称 > wxid
            $senderName = $this->fromWxid; // 默认使用wxid
            $senderAvatar = '';

            if ($senderData) {
                $senderName = $senderData['remark'] ?? $senderData['nickname'] ?? $this->fromWxid;
                $senderAvatar = $senderData['avatar'] ?? '';
            }

            // 格式化为带头像链接的markdown格式
            if (!empty($senderAvatar)) {
                $httpsAvatar = str_replace('http://', 'https://', $senderAvatar);
                $content .= "\r\n by [{$senderName}]({$httpsAvatar})";
            } else {
                $content .= "\r\n by {$senderName}";
            }
        }

        // 区分消息方向：机器人发送的消息 vs 接收的消息
        // incoming - 访客/用户发进来的消息 (作为联系人发送)
        // outgoing - 机器人/系统发出去的消息 (作为客服发送)
        if ($this->isFromBot) {
            // 机器人发送的消息：以客服身份发送
            $this->chatwoot->sendMessageAsAgentToContact($contact, $content);
        } else {
            // 接收的消息：以联系人身份发送
            $this->chatwoot->sendMessageAsContact($contact, $content, $isHost);
        }

        Log::info('Message sent to Chatwoot via queue', [
            'msgId' => $this->msgId,
            'wxid' => $this->wxid,
            'origin_msg_type' => $this->originMsgType
        ]);
    }

}
