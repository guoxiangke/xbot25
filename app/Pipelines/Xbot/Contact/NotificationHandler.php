<?php

namespace App\Pipelines\Xbot\Contact;

use App\Jobs\SendWelcomeMessageJob;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
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

        // 保存原始消息类型
        $context->setMetadata('origin_msg_type', $msgType);

        // 根据消息类型处理不同的通知
        switch ($msgType) {
            case 'MT_ROOM_ADD_MEMBER_NOTIFY_MSG':
                $this->handleMemberAddNotification($context);
                break;
                
            case 'MT_CONTACT_ADD_NOITFY_MSG':
                $this->handleContactAddNotification($context);
                break;
                
            case 'MT_CONTACT_DEL_NOTIFY_MSG':
                $this->handleContactDeleteNotification($context);
                break;
                
            case 'MT_ROOM_DEL_MEMBER_NOTIFY_MSG':
                $this->handleMemberRemoveNotification($context);
                break;
                
            case 'MT_ROOM_CREATE_NOTIFY_MSG':
                $this->handleRoomCreateNotification($context);
                break;
                
            case 'MT_ROOM_MEMBER_DISPLAY_UPDATE_NOTIFY_MSG':
                $this->handleMemberDisplayUpdateNotification($context);
                break;
                
            default:
                $this->log(__FUNCTION__, ['message' => 'Unknown notification type',
                    'notification_type' => $msgType,
                    'data' => $data
                ]);
                break;
        }

        // 继续传递到下一个处理器
        return $next($context);
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

        $this->log(__FUNCTION__, ['message' => 'Member add notification converted to bot text message',
            'room_wxid' => $roomWxid,
            'group_name' => $groupName,
            'member_count' => $memberCount,
            'notification_text' => $notificationText,
            'sent_as_bot' => true
        ]);

        // 检查是否需要发送群新成员欢迎消息
        $this->sendGroupWelcomeMessages($context, $roomWxid, $groupName, $memberList);
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
            
            $this->log(__FUNCTION__, ['message' => 'Group contact added to metadata',
                'room_wxid' => $roomWxid,
                'group_name' => $groupName
            ]);
        }
    }

    /**
     * 处理好友添加通知（同意好友请求后）
     * 参考旧代码中 MT_CONTACT_ADD_NOITFY_MSG 的处理逻辑
     */
    private function handleContactAddNotification(XbotMessageContext $context): void
    {
        $data = $context->requestRawData;
        $newFriendWxid = $data['from_wxid'] ?? ($data['data']['from_wxid'] ?? null);
        
        if (!$newFriendWxid) {
            $this->logError('Invalid contact add notification data', [
                'data' => $data
            ]);
            return;
        }

        try {
            // 更新好友信息
            $xbot = $context->wechatBot->xbot();
            $xbot->getFriendsList();
            
            $this->log(__FUNCTION__, ['message' => 'Contact added successfully, friends list updated',
                'new_friend_wxid' => $newFriendWxid
            ]);

            // 检查是否需要发送欢迎消息
            $configManager = new ConfigManager($context->wechatBot);
            
            if ($configManager->hasWelcomeMessage()) {
                // 延迟5-10分钟发送欢迎消息
                $delay = rand(300, 600);
                
                SendWelcomeMessageJob::dispatch($context->wechatBot->id, $newFriendWxid)
                    ->delay(now()->addSeconds($delay));
                
                $this->log(__FUNCTION__, ['message' => 'Welcome message scheduled for new friend',
                    'new_friend_wxid' => $newFriendWxid,
                    'delay_seconds' => $delay
                ]);
            }

            // 转换为文本消息
            $this->convertContactAddToTextMessage($context, $newFriendWxid);

        } catch (\Exception $e) {
            $this->logError('Failed to handle contact add notification', [
                'new_friend_wxid' => $newFriendWxid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理好友删除通知
     */
    private function handleContactDeleteNotification(XbotMessageContext $context): void
    {
        $data = $context->requestRawData;
        $deletedFriendWxid = $data['from_wxid'] ?? ($data['data']['from_wxid'] ?? null);
        
        if (!$deletedFriendWxid) {
            $this->logError('Invalid contact delete notification data', [
                'data' => $data
            ]);
            return;
        }

        try {
            // 从联系人列表中移除
            $this->removeFromContactList($context, $deletedFriendWxid);
            
            $this->log(__FUNCTION__, ['message' => 'Contact deleted, removed from contact list',
                'deleted_friend_wxid' => $deletedFriendWxid
            ]);

            // 转换为文本消息
            $this->convertContactDeleteToTextMessage($context, $deletedFriendWxid);

        } catch (\Exception $e) {
            $this->logError('Failed to handle contact delete notification', [
                'deleted_friend_wxid' => $deletedFriendWxid,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理群成员移除通知
     */
    private function handleMemberRemoveNotification(XbotMessageContext $context): void
    {
        $data = $context->requestRawData;
        $roomWxid = $data['room_wxid'] ?? ($data['data']['room_wxid'] ?? null);
        $memberList = $data['member_list'] ?? ($data['data']['member_list'] ?? []);
        
        if (!$roomWxid || empty($memberList)) {
            $this->logError('Invalid member remove notification data', [
                'room_wxid' => $roomWxid,
                'member_list_count' => count($memberList)
            ]);
            return;
        }

        // 检查退群监控配置
        $configManager = new ConfigManager($context->wechatBot);
        
        // 检查全局和群级别的 room_quit 配置
        $globalRoomQuitEnabled = $configManager->isEnabled('room_quit');
        $groupRoomQuitEnabled = $this->getGroupLevelConfig($context->wechatBot, $roomWxid, 'room_quit');
        
        // 如果群设置了 room_quit，按群设置；否则按全局设置
        $shouldMonitorQuit = $groupRoomQuitEnabled !== null ? $groupRoomQuitEnabled : $globalRoomQuitEnabled;
        
        if (!$shouldMonitorQuit) {
            $this->log(__FUNCTION__, ['message' => 'Room quit monitoring disabled for this group',
                'room_wxid' => $roomWxid,
                'global_room_quit' => $globalRoomQuitEnabled,
                'group_room_quit' => $groupRoomQuitEnabled
            ]);
            
            // 不处理，直接标记为已处理，避免继续传递
            $context->markAsProcessed(static::class);
            return;
        }

        // 生成移除成员通知文本
        $memberNames = [];
        foreach ($memberList as $member) {
            $memberNames[] = $member['nickname'] ?? $member['wxid'] ?? '未知用户';
        }
        
        $memberCount = count($memberNames);
        if ($memberCount === 1) {
            $notificationText = "{$memberNames[0]} 退出了群聊";
        } else {
            $memberListText = implode('、', $memberNames);
            $notificationText = "{$memberListText} 退出了群聊";
        }

        // 发送退群通知到群内
        $this->sendTextMessage($context, $notificationText, $roomWxid);
        
        $this->log(__FUNCTION__, ['message' => 'Member quit notification sent to group',
            'room_wxid' => $roomWxid,
            'member_count' => $memberCount,
            'notification_text' => $notificationText,
            'global_room_quit' => $globalRoomQuitEnabled,
            'group_room_quit' => $groupRoomQuitEnabled
        ]);
    }

    /**
     * 处理群创建通知
     */
    private function handleRoomCreateNotification(XbotMessageContext $context): void
    {
        $data = $context->requestRawData;
        $roomWxid = $data['room_wxid'] ?? ($data['data']['room_wxid'] ?? null);
        $roomName = $data['nickname'] ?? ($data['data']['nickname'] ?? '新群聊');
        
        if (!$roomWxid) {
            $this->logError('Invalid room create notification data', [
                'data' => $data
            ]);
            return;
        }

        // 确保群联系人信息存在
        $this->ensureGroupContactExists($context, $roomWxid, $roomName);
        
        $notificationText = "群聊 \"{$roomName}\" 已创建";
        $this->convertToSystemMessage($context, $notificationText);
        
        $this->log(__FUNCTION__, ['message' => 'Room create notification processed',
            'room_wxid' => $roomWxid,
            'room_name' => $roomName
        ]);
    }

    /**
     * 处理群成员显示名更新通知
     */
    private function handleMemberDisplayUpdateNotification(XbotMessageContext $context): void
    {
        $data = $context->requestRawData;
        
        $notificationText = "群成员信息已更新";
        $this->convertToSystemMessage($context, $notificationText);
        
        $this->log(__FUNCTION__, ['message' => 'Member display update notification processed',
            'data' => $data
        ]);
    }

    /**
     * 从联系人列表中移除好友
     */
    private function removeFromContactList(XbotMessageContext $context, string $wxid): void
    {
        $contacts = $context->wechatBot->getMeta('contacts', []);
        
        if (isset($contacts[$wxid])) {
            unset($contacts[$wxid]);
            $context->wechatBot->setMeta('contacts', $contacts);
        }
    }

    /**
     * 转换好友添加通知为文本消息
     */
    private function convertContactAddToTextMessage(XbotMessageContext $context, string $friendWxid): void
    {
        $contacts = $context->wechatBot->getMeta('contacts', []);
        $nickname = $contacts[$friendWxid]['nickname'] ?? $contacts[$friendWxid]['remark'] ?? $friendWxid;
        
        $textMessage = "新好友添加成功：{$nickname}";
        $this->convertToSystemMessage($context, $textMessage);
    }

    /**
     * 转换好友删除通知为文本消息
     */
    private function convertContactDeleteToTextMessage(XbotMessageContext $context, string $friendWxid): void
    {
        $textMessage = "好友已被移除：{$friendWxid}";
        $this->convertToSystemMessage($context, $textMessage);
    }

    /**
     * 转换为系统消息
     */
    private function convertToSystemMessage(XbotMessageContext $context, string $message): void
    {
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        $context->requestRawData['from_wxid'] = $context->wechatBot->wxid; // 改为bot发送
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $message;
    }

    /**
     * 获取群级别配置项的值
     * 
     * @param WechatBot $wechatBot
     * @param string $roomWxid  
     * @param string $configKey 配置键名
     * @return bool|null null表示没有群级别配置，使用全局配置
     */
    private function getGroupLevelConfig($wechatBot, string $roomWxid, string $configKey): ?bool
    {
        switch ($configKey) {
            case 'room_msg':
                $filter = new \App\Services\ChatroomMessageFilter($wechatBot, new ConfigManager($wechatBot));
                return $filter->getRoomListenStatus($roomWxid);
                
            case 'check_in':
                $service = new \App\Services\CheckInPermissionService($wechatBot);
                return $service->getRoomCheckInStatus($roomWxid);
                
            case 'room_quit':
                // room_quit 配置存储在 room_quit_specials metadata 中
                $quitConfigs = $wechatBot->getMeta('room_quit_specials', []);
                return $quitConfigs[$roomWxid] ?? null;
                
            default:
                return null;
        }
    }

    /**
     * 发送群新成员欢迎消息（双重发送：私聊+群内）
     */
    private function sendGroupWelcomeMessages(XbotMessageContext $context, string $roomWxid, string $groupName, array $memberList): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 获取群级别的新成员欢迎消息模板
        $welcomeTemplate = $configManager->getGroupConfig('room_welcome_msgs', $roomWxid);
        
        if (empty($welcomeTemplate)) {
            $this->log(__FUNCTION__, ['message' => 'No group welcome message template configured',
                'room_wxid' => $roomWxid
            ]);
            return;
        }

        // 获取联系人信息用于昵称替换
        $contacts = $context->wechatBot->getMeta('contacts', []);
        
        // 为每个新成员发送欢迎消息
        foreach ($memberList as $member) {
            $memberWxid = $member['wxid'] ?? null;
            if (!$memberWxid) {
                continue;
            }
            
            // 获取成员昵称
            $memberNickname = $contacts[$memberWxid]['nickname'] ?? 
                             $contacts[$memberWxid]['remark'] ?? 
                             $member['nickname'] ?? 
                             $memberWxid;
            
            // 替换变量
            $welcomeMessage = $this->replaceWelcomeVariables($welcomeTemplate, $memberNickname, $groupName);
            
            try {
                $xbot = $context->wechatBot->xbot();
                
                // 1. 发送私聊欢迎消息给新成员
                $privateResult = $xbot->sendTextMessage($memberWxid, $welcomeMessage);
                
                // 2. 发送群内欢迎消息
                $groupResult = $xbot->sendTextMessage($roomWxid, $welcomeMessage);
                
                $this->log(__FUNCTION__, ['message' => 'Group welcome messages sent (private + group)',
                    'room_wxid' => $roomWxid,
                    'member_wxid' => $memberWxid,
                    'member_nickname' => $memberNickname,
                    'welcome_message' => $welcomeMessage,
                    'private_result' => $privateResult,
                    'group_result' => $groupResult
                ]);
                
            } catch (\Exception $e) {
                $this->logError('Failed to send group welcome messages', [
                    'room_wxid' => $roomWxid,
                    'member_wxid' => $memberWxid,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 替换欢迎消息中的变量
     */
    private function replaceWelcomeVariables(string $template, string $nickname, string $groupName): string
    {
        $replacements = [
            '@nickname' => "@{$nickname}",
            '【xx】' => "【{$groupName}】",
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

}
