<?php

namespace App\Pipelines\Xbot;

use App\Models\WechatBot;
use Carbon\Carbon;

/**
 * Xbot消息处理上下文类
 * 封装消息处理过程中的所有数据和状态
 */
class XbotMessageContext
{
    public WechatBot $wechatBot;
    public array $requestRawData;
    public string $msgType;
    public ?string $msgId;
    public string $isRepliedKey;
    public bool $isGh;
    public bool $isSelfToSelf;
    public bool $isFromBot;
    public bool $isProcessed = false;
    public array $metadata = [];
    public string $wxid; // 消息发送者的微信ID
    public bool $isRoom;
    public string $fromWxid; //群信息的消息发送者的微信ID
    public ?int $clientId; // 客户端ID
    public ?string $processedMessage = null; // 处理后的消息内容
    
    // 联系人详细数据
    public ?array $fromContact = null;  // 发送者联系人详细数据
    public ?array $toContact = null;    // 接收者联系人详细数据  
    public ?array $roomContact = null;  // 群聊联系人详细数据

    public function __construct(WechatBot $wechatBot, array $requestRawData, string $msgType, ?int $clientId = null)
    {
        $this->wechatBot = $wechatBot;
        $this->requestRawData = $requestRawData;
        $this->msgType = $msgType;
        // 对于MT_TRANS_VOICE_MSG，字段可能在data中或直接在顶层
        if ($msgType === 'MT_TRANS_VOICE_MSG') {
            $this->msgId = $requestRawData['msgid'] ?? $requestRawData['data']['msgid'] ?? null;
            $toWxid   = $requestRawData['to_wxid']   ?? $requestRawData['data']['to_wxid']   ?? '';
            $fromWxid = $requestRawData['from_wxid'] ?? $requestRawData['data']['from_wxid'] ?? '';
            $roomWxid = $requestRawData['room_wxid'] ?? $requestRawData['data']['room_wxid'] ?? '';
        } else {
            $this->msgId = $requestRawData['msgid'] ?? null;
            $toWxid   = $requestRawData['to_wxid']   ?? '';
            $fromWxid = $requestRawData['from_wxid'] ?? '';
            $roomWxid = $requestRawData['room_wxid'] ?? '';
        }
        
        $this->clientId = $clientId;
        $this->isRoom = !empty($roomWxid);
        $this->isGh = str_starts_with($fromWxid, 'gh_');
        $this->isSelfToSelf = $fromWxid === $toWxid;
        $this->isFromBot = $fromWxid === $wechatBot->wxid;
        $this->isRepliedKey = 'replied.' . $wechatBot->id . '.' . $this->msgId;
        if ($this->isRoom) {// 群消息，直接用群wxid
            $wxid = $roomWxid;
        } elseif ($this->isFromBot) { // 机器人发送，取接收者
            $wxid = $toWxid;
        } else {// 用户发送，取发送者
            $wxid = $fromWxid;
        }
        $this->wxid = $wxid;
        $this->fromWxid = $fromWxid;
        
        // 预加载联系人数据
        $this->loadContactsData();
    }
    
    /**
     * 预加载联系人详细数据
     */
    private function loadContactsData(): void
    {
        $contacts = $this->wechatBot->getMeta('contacts', []);
        
        $fromWxid = $this->requestRawData['from_wxid'] ?? '';
        $toWxid = $this->requestRawData['to_wxid'] ?? '';
        $roomWxid = $this->requestRawData['room_wxid'] ?? '';
        
        // 加载发送者联系人数据
        if ($fromWxid) {
            $this->fromContact = $contacts[$fromWxid] ?? null;
        }
        
        // 加载接收者联系人数据
        if ($toWxid) {
            $this->toContact = $contacts[$toWxid] ?? null;
            // 如果是群联系人，移除member_list以减少日志输出
            if ($this->toContact && str_ends_with($toWxid, '@chatroom')) {
                unset($this->toContact['member_list']);
            }
        }
        
        // 加载群聊联系人数据
        if ($roomWxid) {
            $this->roomContact = $contacts[$roomWxid] ?? null;
            // 如果是群联系人，移除member_list以减少日志输出
            if ($this->roomContact) {
                unset($this->roomContact['member_list']);
            }
        }
    }

    /**
     * 标记消息为已处理
     */
    public function markAsProcessed(string $handlerName = ''): void
    {
        $this->isProcessed = true;
        $this->metadata['processed_by'] = $handlerName;
        $this->metadata['processed_at'] = Carbon::now();
    }

    /**
     * 检查消息是否已被处理
     */
    public function isProcessed(): bool
    {
        return $this->isProcessed;
    }

    /**
     * 设置元数据
     */
    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * 获取元数据
     */
    public function getMetadata(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * 获取回复目标
     */
    public function getReplyTarget(): string
    {
        $fromWxid = $this->requestRawData['from_wxid'] ?? '';
        $toWxid = $this->requestRawData['to_wxid'] ?? '';

        if ($this->isRoom) {
            return $this->requestRawData['room_wxid'];
        }

        if ($fromWxid === $this->wechatBot->wxid) {
            return $toWxid;
        }

        return $fromWxid;
    }

    /**
     * 获取消息内容
     */
    public function getContent(): string
    {
        return $this->getMetadata('content', $this->requestRawData['msg'] ?? '');
    }

    /**
     * 设置消息内容
     */
    public function setContent(string $content): void
    {
        $this->setMetadata('content', $content);
    }

    /**
     * 获取联系人标签
     */
    public function getContactLabel(array $contactData): string
    {
        $wxid = $contactData['wxid'] ?? '';
        
        // 如果是机器人自己，返回特定标签
        if ($wxid === $this->wechatBot->wxid) {
            return \App\Models\WechatBot::getSpecialContactLabel('robot');
        }
        
        $type = $contactData['type'] ?? 0;
        return \App\Models\WechatBot::getContactTypeLabel($type);
    }

    /**
     * 获取发送者标签
     */
    public function getFromContactLabel(): string
    {
        return $this->fromContact ? $this->getContactLabel($this->fromContact) : '未知';
    }

    /**
     * 获取接收者标签
     */
    public function getToContactLabel(): string
    {
        return $this->toContact ? $this->getContactLabel($this->toContact) : '未知';
    }

    /**
     * 获取群聊标签
     */
    public function getRoomContactLabel(): string
    {
        return $this->roomContact ? $this->getContactLabel($this->roomContact) : '未知';
    }

    /**
     * 获取发送者显示名称
     */
    public function getFromContactName(): string
    {
        if (!$this->fromContact) return '未知联系人';
        
        return $this->fromContact['remark'] ?? 
               $this->fromContact['nickname'] ?? 
               $this->fromContact['wxid'] ?? '未知联系人';
    }

    /**
     * 获取群聊显示名称
     */
    public function getRoomContactName(): string
    {
        if (!$this->roomContact) return '未知群聊';
        
        return $this->roomContact['remark'] ?? 
               $this->roomContact['nickname'] ?? 
               $this->roomContact['wxid'] ?? '未知群聊';
    }

    /**
     * 设置处理后的消息内容
     */
    public function setProcessedMessage(string $message): void
    {
        $this->processedMessage = $message;
    }

    /**
     * 获取处理后的消息内容
     */
    public function getProcessedMessage(): ?string
    {
        return $this->processedMessage;
    }

    /**
     * 标记语音消息已转为文本
     */
    public function markVoiceTransProcessed(string $text): void
    {
        $this->setMetadata('voice_trans_processed', true);
        $this->setMetadata('voice_trans_text', $text);
    }

    /**
     * 检查是否有语音转文本
     */
    public function hasVoiceTransText(): bool
    {
        return $this->getMetadata('voice_trans_processed', false);
    }

    /**
     * 获取语音转文本内容
     */
    public function getVoiceTransText(): string
    {
        return $this->getMetadata('voice_trans_text', '');
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'msgType' => $this->msgType,
            'msgId' => $this->msgId,
            'isRoom' => $this->isRoom,
            'isGh' => $this->isGh,
            'isSelfToSelf' => $this->isSelfToSelf,
            'isFromBot' => $this->isFromBot,
            'processedMessage' => $this->processedMessage,
            'metadata' => $this->metadata,
            'fromContact' => $this->fromContact,
            'toContact' => $this->toContact,
            'roomContact' => $this->roomContact,
        ];
    }
}
