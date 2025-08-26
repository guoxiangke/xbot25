<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 通知消息处理器
 * 处理各种通知类型的消息
 */
class NotificationHandler extends BaseXbotHandler
{
    private const NOTIFICATION_TYPES = [
        'MT_ROOM_ADD_MEMBER_NOTIFY_MSG',
        'MT_ROOM_DEL_MEMBER_NOTIFY_MSG',
        'MT_ROOM_CREATE_NOTIFY_MSG',
        'MT_CONTACT_ADD_NOITFY_MSG',
        'MT_CONTACT_DEL_NOTIFY_MSG',
        'MT_ROOM_MEMBER_DISPLAY_UPDATE_NOTIFY_MSG'
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, self::NOTIFICATION_TYPES) ||
            $context->isFromBot) {
            return $next($context);
        }

        $type = $context->requestRawData['type'] ?? '';
        $data = $context->requestRawData['data'] ?? [];

        $this->log('Notification message processed', [
            'notification_type' => $type,
            'data' => $data,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        $context->markAsProcessed(static::class);
        return $context;
    }
}
