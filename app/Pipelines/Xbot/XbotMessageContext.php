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
    public bool $isRoom;
    public bool $isGh;
    public bool $isSelf;
    public bool $isProcessed = false;
    public array $metadata = [];

    public function __construct(WechatBot $wechatBot, array $requestRawData)
    {
        $this->wechatBot = $wechatBot;
        $this->requestRawData = $requestRawData;
        $this->msgType = $requestRawData['type'] ?? '';
        $this->msgId = $requestRawData['msgid'] ?? null;
        $this->isRoom = !empty($requestRawData['room_wxid']);
        $this->isGh = str_starts_with($requestRawData['from_wxid'] ?? '', 'gh_');
        $this->isSelf = ($requestRawData['from_wxid'] ?? '') === ($requestRawData['to_wxid'] ?? '');
        $this->isRepliedKey = 'replied.' . $wechatBot->id . '.' . $this->msgId;
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
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'msgType' => $this->msgType,
            'msgId' => $this->msgId,
            'isRoom' => $this->isRoom,
            'isGh' => $this->isGh,
            'isSelf' => $this->isSelf,
            'isProcessed' => $this->isProcessed,
            'metadata' => $this->metadata,
        ];
    }
}
