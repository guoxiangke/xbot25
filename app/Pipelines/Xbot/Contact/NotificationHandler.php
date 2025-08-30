<?php

namespace App\Pipelines\Xbot\Contact;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
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

        $msgType = $context->msgType;
        $data = $context->requestRawData;

        // 处理群成员添加通知
        if ($msgType === 'MT_ROOM_ADD_MEMBER_NOTIFY_MSG') {
            $this->handleMemberAddNotification($context);
            // 转换为文本消息后，继续管道处理
            return $next($context);
        }

        $this->log('Notification message processed', [
            'notification_type' => $msgType,
            'data' => $data,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        $context->markAsProcessed(static::class);
        return $context;
    }

    /**
     * 处理群成员添加通知
     */
    private function handleMemberAddNotification(XbotMessageContext $context): void
    {
        $memberList = $context->requestRawData['member_list'] ?? 
                     ($context->requestRawData['data']['member_list'] ?? []);
        $groupName = $context->requestRawData['nickname'] ?? 
                    ($context->requestRawData['data']['nickname'] ?? '群聊');
        $roomWxid = $context->requestRawData['room_wxid'] ?? 
                   ($context->requestRawData['data']['room_wxid'] ?? null);
        
        if (empty($memberList) || !$roomWxid) {
            $this->logError('Invalid member add notification data', [
                'member_list_count' => count($memberList),
                'room_wxid' => $roomWxid
            ]);
            return;
        }

        // 确保群联系人信息在 metadata 中存在
        $this->ensureGroupContactExists($context, $roomWxid, $groupName);

        // 生成群成员添加通知文本 (保持原始系统格式)
        $memberNames = [];
        foreach ($memberList as $member) {
            $memberNames[] = $member['nickname'] ?? $member['wxid'] ?? '未知用户';
        }
        
        $memberCount = count($memberNames);
        if ($memberCount === 1) {
            $notificationText = "{$memberNames[0]} 加入了群聊";
        } else {
            $memberListText = implode('、', $memberNames);
            $notificationText = "{$memberListText} 加入了群聊";
        }

        // 修改消息为bot发送的文本消息
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        $context->requestRawData['from_wxid'] = $context->wechatBot->wxid; // 改为bot发送
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $notificationText;

        $this->log('Member add notification converted to bot text message', [
            'room_wxid' => $roomWxid,
            'group_name' => $groupName,
            'member_count' => $memberCount,
            'notification_text' => $notificationText,
            'sent_as_bot' => true
        ]);
    }

    /**
     * 确保群联系人信息存在于 metadata 中
     */
    private function ensureGroupContactExists(XbotMessageContext $context, string $roomWxid, string $groupName): void
    {
        // 检查群信息是否已在 metadata 中
        $contacts = $context->wechatBot->getMeta('contacts', []);
        
        if (!isset($contacts[$roomWxid])) {
            // 群信息不存在，创建基础群信息
            $groupContact = [
                'wxid' => $roomWxid,
                'nickname' => $groupName,
                'type' => 2, // 群聊类型
                'room_wxid' => $roomWxid,
                'avatar' => '', // 群头像通常在其他消息中获得
                'remark' => '',
                'sex' => 0
            ];
            
            // 保存到 metadata
            $contacts[$roomWxid] = $groupContact;
            $context->wechatBot->setMeta('contacts', $contacts);
            
            // 主动获取完整的群信息
            $xbot = $context->wechatBot->xbot();
            $xbot->getChatroomsList();
            
            $this->log('Group contact added to metadata', [
                'room_wxid' => $roomWxid,
                'group_name' => $groupName
            ]);
        }
    }
}
