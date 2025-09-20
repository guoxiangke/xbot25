<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Clients\ChatwootClient;
use App\Services\Managers\ConfigManager;
use Closure;

/**
 * 系统消息处理器
 * 处理 MT_RECV_SYSTEM_MSG 类型的系统消息，转换为文本消息传递给 TextMessageHandler
 */
class SystemMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_SYSTEM_MSG')) {
            return $next($context);
        }

        $rawMsg = $context->requestRawData['raw_msg'] ?? '';
        $wxType = $context->requestRawData['wx_type'] ?? 0;
        
        // 处理 wx_type: 10000 的群邀请系统消息
        if ($wxType == 10000) {
            $this->handleGroupInviteSystemMessage($context);
            
            // 检查是否为退群相关的系统消息
            if ($this->isQuitMessage($rawMsg)) {
                $this->handleQuitMessage($context, $rawMsg);
            }
            
            // 检查是否为群新成员加入消息
            if ($this->isJoinMessage($rawMsg)) {
                $this->handleJoinMessage($context, $rawMsg);
            }
        }
        
        // 格式化系统消息为文本格式
        $systemMessage = $this->formatSystemMessage($rawMsg);
        
        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改为bot发送的文本消息
        $context->requestRawData['from_wxid'] = $context->wechatBot->wxid; // 改为bot发送
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $systemMessage; // 使用原始文本，不添加前缀

        $this->log(__FUNCTION__, ['message' => 'System message converted to bot text message',
            'raw_msg' => $rawMsg,
            'wx_type' => $wxType,
            'system_message' => $systemMessage,
            'sent_as_bot' => true,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 处理群邀请系统消息 (wx_type: 10000)
     * 确保群联系人被同步到 Chatwoot
     */
    private function handleGroupInviteSystemMessage(XbotMessageContext $context): void
    {
        $roomWxid = $context->requestRawData['room_wxid'] ?? null;
        
        if (!$roomWxid) {
            $this->logError('Group invite system message without room_wxid', [
                'raw_data' => $context->requestRawData
            ]);
            return;
        }

        // 检查 Chatwoot 是否启用
        $configManager = new ConfigManager($context->wechatBot);
        $isChatwootEnabled = $configManager->isEnabled('chatwoot');
        if (!$isChatwootEnabled) {
            return;
        }

        $chatwoot = new ChatwootClient($context->wechatBot);
        
        // 检查群联系人是否已存在
        $contact = $chatwoot->searchContact($roomWxid);
        
        if (!$contact) {
            // 从 metadata 中获取群信息
            $contacts = $context->wechatBot->getMeta('contacts', []);
            $groupData = $contacts[$roomWxid] ?? null;
            
            if (!$groupData) {
                // 群信息不存在，创建基础群信息以便继续处理
                // 从消息结构中获取群名
                $roomName = $context->requestRawData['room_name'] ?? 
                           $context->requestRawData['data']['room_name'] ?? 
                           $context->requestRawData['data']['nickname'] ?? 
                           '未知群聊';
                
                $groupData = [
                    'wxid' => $roomWxid,
                    'nickname' => $roomName,
                    'type' => 2,
                    'room_wxid' => $roomWxid,
                    'avatar' => '',
                    'remark' => '',
                    'sex' => 0
                ];
                
                // 保存到 metadata
                $contacts[$roomWxid] = $groupData;
                $context->wechatBot->setMeta('contacts', $contacts);
                
                $this->log(__FUNCTION__, ['message' => 'Created basic group data for missing contact',
                    'room_wxid' => $roomWxid,
                    'room_name' => $roomName
                ]);
            }
            
            // 保存群联系人到 Chatwoot
            $contact = $chatwoot->saveContact($groupData);
            $chatwoot->setLabel($contact['id'], '微信群');
            
            $this->log(__FUNCTION__, ['message' => 'Group contact saved to Chatwoot',
                'room_wxid' => $roomWxid,
                'contact_id' => $contact['id'] ?? null
            ]);
        }
    }

    /**
     * 格式化系统消息
     * 参考 XbotCallbackController.php 第255-258行的逻辑
     */
    private function formatSystemMessage(string $rawMsg): string
    {
        if (empty($rawMsg)) {
            return '系统消息';
        }

        try {
            // 清理系统消息，移除多余的引号和格式
            $cleanedMessage = trim($rawMsg);
            
            // 移除开头和结尾的引号
            if (str_starts_with($cleanedMessage, '"') && str_ends_with($cleanedMessage, '"')) {
                $cleanedMessage = substr($cleanedMessage, 1, -1);
            }
            
            // 如果消息为空，使用默认文本
            return !empty($cleanedMessage) ? $cleanedMessage : '系统消息';
            
        } catch (\Exception $e) {
            $this->logError('Error formatting system message: ' . $e->getMessage(), [
                'raw_msg' => $rawMsg
            ]);
            return '系统消息';
        }
    }

    /**
     * 检查是否为退群相关的系统消息
     */
    private function isQuitMessage(string $rawMsg): bool
    {
        // 检查各种退群消息模式
        $quitPatterns = [
            '/你将"(.+?)"移出了群聊/',         // 踢人
            '/^"(.+?)"退出了群聊$/',          // 带引号的退群
            '/(.+?)被"(.+?)"移出群聊/',       // 被其他人踢
            '/(.+?)被移出了群聊/',            // 被移出
            '/(.+?)已退出群聊/',              // 已退出
            '/^(.+?)(?:主动)?退出了群聊$/',   // 主动退群 (精确匹配)
        ];
        
        foreach ($quitPatterns as $pattern) {
            if (preg_match($pattern, $rawMsg)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 处理退群消息
     */
    private function handleQuitMessage(XbotMessageContext $context, string $rawMsg): void
    {
        $roomWxid = $context->requestRawData['room_wxid'] ?? null;
        
        if (!$roomWxid) {
            $this->logError('Quit message without room_wxid', [
                'raw_msg' => $rawMsg
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
                'raw_msg' => $rawMsg,
                'global_room_quit' => $globalRoomQuitEnabled,
                'group_room_quit' => $groupRoomQuitEnabled
            ]);
            return;
        }

        // 解析退群消息，提取用户名
        $quitUserName = $this->extractQuitUserName($rawMsg);
        
        if ($quitUserName) {
            // 发送退群通知到群内
            $notificationText = "{$quitUserName} 退出了群聊";
            $this->sendTextMessage($context, $notificationText, $roomWxid);
            
            $this->log(__FUNCTION__, ['message' => 'Quit notification sent to group',
                'room_wxid' => $roomWxid,
                'quit_user' => $quitUserName,
                'notification_text' => $notificationText,
                'raw_msg' => $rawMsg,
                'global_room_quit' => $globalRoomQuitEnabled,
                'group_room_quit' => $groupRoomQuitEnabled
            ]);
        } else {
            $this->logError('Failed to extract quit user name from message', [
                'raw_msg' => $rawMsg,
                'room_wxid' => $roomWxid
            ]);
        }
    }

    /**
     * 从退群消息中提取用户名
     */
    private function extractQuitUserName(string $rawMsg): ?string
    {
        // 尝试各种模式提取用户名
        $patterns = [
            '/你将"(.+?)"移出了群聊/' => 1,      // 踢人: 提取被踢用户名
            '/^"(.+?)"退出了群聊$/' => 1,        // 带引号的退群: 提取用户名
            '/(.+?)被"(.+?)"移出群聊/' => 1,     // 被踢: 提取被踢用户名
            '/(.+?)被移出了群聊/' => 1,          // 被移出: 提取被移出用户名
            '/(.+?)已退出群聊/' => 1,            // 已退出: 提取退出用户名
            '/^(.+?)(?:主动)?退出了群聊$/' => 1,  // 主动退群: 提取退群用户名 (精确匹配)
        ];
        
        foreach ($patterns as $pattern => $groupIndex) {
            if (preg_match($pattern, $rawMsg, $matches)) {
                return $matches[$groupIndex] ?? null;
            }
        }
        
        return null;
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
                $filter = new \App\Services\ChatroomMessageFilter($wechatBot, new \App\Services\Managers\ConfigManager($wechatBot));
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
     * 检查是否为群新成员加入的系统消息
     */
    private function isJoinMessage(string $rawMsg): bool
    {
        // 检查各种群新成员加入消息模式
        $joinPatterns = [
            '/你邀请"(.+?)"加入了群聊/',          // 邀请加入
            '/"(.+?)"加入了群聊/',               // 直接加入
            '/(.+?)加入了群聊/',                 // 一般加入格式
            '/"(.+?)"通过扫描你分享的二维码加入群聊/', // 二维码加入
            '/(.+?)通过(.+?)邀请加入了群聊/',      // 通过他人邀请加入
        ];
        
        foreach ($joinPatterns as $pattern) {
            if (preg_match($pattern, $rawMsg)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 处理群新成员加入消息，发送欢迎消息
     */
    private function handleJoinMessage(XbotMessageContext $context, string $rawMsg): void
    {
        $roomWxid = $context->requestRawData['room_wxid'] ?? null;
        $roomName = $context->requestRawData['room_name'] ?? '群聊';
        
        if (!$roomWxid) {
            $this->logError('Join message without room_wxid', [
                'raw_msg' => $rawMsg
            ]);
            return;
        }

        // 检查是否设置了群新成员欢迎消息
        $configManager = new ConfigManager($context->wechatBot);
        $welcomeTemplate = $configManager->getGroupConfig('room_welcome_msgs', $roomWxid);
        
        if (empty($welcomeTemplate)) {
            $this->log(__FUNCTION__, ['message' => 'No group welcome message template configured',
                'room_wxid' => $roomWxid,
                'raw_msg' => $rawMsg
            ]);
            return;
        }

        // 从系统消息中提取新成员昵称
        $newMemberName = $this->extractJoinUserName($rawMsg);
        
        if (!$newMemberName) {
            $this->logError('Failed to extract new member name from join message', [
                'raw_msg' => $rawMsg,
                'room_wxid' => $roomWxid
            ]);
            return;
        }

        // 替换变量生成欢迎消息
        $welcomeMessage = $this->replaceWelcomeVariables($welcomeTemplate, $newMemberName, $roomName);
        
        try {
            $xbot = $context->wechatBot->xbot();
            
            // 发送群内欢迎消息
            $groupResult = $xbot->sendTextMessage($roomWxid, $welcomeMessage);
            
            $this->log(__FUNCTION__, ['message' => 'Group welcome message sent to group',
                'room_wxid' => $roomWxid,
                'room_name' => $roomName,
                'new_member_name' => $newMemberName,
                'welcome_message' => $welcomeMessage,
                'group_result' => $groupResult,
                'raw_msg' => $rawMsg
            ]);
            
            // 注意：由于系统消息没有新成员的wxid，只能发送群内消息，无法发送私聊消息
            
        } catch (\Exception $e) {
            $this->logError('Failed to send group welcome message', [
                'room_wxid' => $roomWxid,
                'new_member_name' => $newMemberName,
                'error' => $e->getMessage(),
                'raw_msg' => $rawMsg
            ]);
        }
    }

    /**
     * 从群新成员加入消息中提取用户名
     */
    private function extractJoinUserName(string $rawMsg): ?string
    {
        // 尝试各种模式提取新成员用户名
        $patterns = [
            '/你邀请"(.+?)"加入了群聊/' => 1,           // 邀请加入：提取被邀请用户名
            '/"(.+?)"加入了群聊/' => 1,                // 直接加入：提取用户名
            '/(.+?)加入了群聊/' => 1,                  // 一般加入格式：提取用户名
            '/"(.+?)"通过扫描你分享的二维码加入群聊/' => 1, // 二维码加入：提取用户名
            '/(.+?)通过(.+?)邀请加入了群聊/' => 1,       // 通过他人邀请：提取被邀请用户名
        ];
        
        foreach ($patterns as $pattern => $groupIndex) {
            if (preg_match($pattern, $rawMsg, $matches)) {
                return $matches[$groupIndex] ?? null;
            }
        }
        
        return null;
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
