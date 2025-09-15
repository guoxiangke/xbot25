<?php

namespace App\Pipelines\Xbot\Contact;

use App\Jobs\ProcessFriendRequestJob;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use App\Services\Clients\ChatwootClient;
use App\Services\Analytics\FriendSourceAnalyzer;
use Closure;

/**
 * 好友请求处理器
 * 处理 MT_RECV_FRIEND_MSG 类型的好友请求消息
 * 参考 XbotCallbackController.php 第359-366行的逻辑
 */
class FriendRequestHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_FRIEND_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $data = $context->requestRawData;
        $rawMsg = $data['raw_msg'] ?? '';

        // 保存原始消息类型
        $context->setMetadata('origin_msg_type', $context->msgType);

        // 使用新的配置管理器
        $configManager = new ConfigManager($context->wechatBot);
        
        // 检查是否开启自动同意好友请求
        if ($configManager->isEnabled('friend_auto_accept')) {
            $this->handleAutoFriendRequest($context, $rawMsg, $configManager);
        } else {
            // 不自动同意，记录日志
            $this->log(__FUNCTION__, ['message' => 'Friend request received (auto accept disabled)',
                'from_wxid' => $context->fromWxid,
                'raw_msg' => $rawMsg
            ]);
        }

        // 分析好友来源
        $sourceAnalysis = FriendSourceAnalyzer::analyze($data);
        $context->setMetadata('friend_source_analysis', $sourceAnalysis);

        // 同步联系人信息到Chatwoot并发送通知
        $this->handleFriendRequestNotification($context, $rawMsg, $sourceAnalysis);

        // 转换为文本消息以便同步到Chatwoot
        $this->convertToTextMessage($context, $rawMsg, $sourceAnalysis);

        // 继续传递到下一个处理器（重要：不再markAsProcessed）
        return $next($context);
    }

    /**
     * 处理自动同意好友请求 - 使用队列延迟处理
     * 参考 XbotCallbackController.php 第361-364行的逻辑
     */
    private function handleAutoFriendRequest(XbotMessageContext $context, string $rawMsg, ConfigManager $configManager): void
    {
        $friendRequestInfo = $this->parseFriendRequestXml($rawMsg);
        
        if (!$friendRequestInfo) {
            $this->logError('Failed to parse friend request XML', ['raw_msg' => $rawMsg]);
            return;
        }

        $scene = $friendRequestInfo['scene'] ?? '';
        $encryptusername = $friendRequestInfo['encryptusername'] ?? '';
        $ticket = $friendRequestInfo['ticket'] ?? '';
        $fromnickname = $friendRequestInfo['fromnickname'] ?? '';
        $content = $friendRequestInfo['content'] ?? '';

        if (!empty($scene) && !empty($encryptusername) && !empty($ticket)) {
            // 计算智能延迟时间
            $delayMinutes = ProcessFriendRequestJob::calculateSmartDelay($configManager);
            
            // 分派到队列延迟处理
            ProcessFriendRequestJob::dispatch($context->wechatBot->id, $friendRequestInfo)
                ->delay(now()->addMinutes($delayMinutes));
            
            $this->log(__FUNCTION__, ['message' => 'Friend request queued for processing',
                'from_nickname' => $fromnickname,
                'content' => $content,
                'scene' => $scene,
                'delay_minutes' => $delayMinutes,
                'scheduled_at' => now()->addMinutes($delayMinutes)->toDateTimeString()
            ]);
        } else {
            $this->logError('Missing required friend request parameters', [
                'scene' => $scene,
                'encryptusername' => $encryptusername,
                'ticket' => $ticket
            ]);
        }
    }

    /**
     * 解析好友请求XML，提取完整的联系人信息
     * 参考 XbotCallbackController.php 第361行的逻辑和联系人同步的数据结构
     */
    private function parseFriendRequestXml(string $rawMsg): ?array
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            $info = [];
            
            // 提取微信ID（fromusername）
            if (preg_match('/fromusername="([^"]*)"/', $rawMsg, $matches)) {
                $info['wxid'] = trim($matches[1]);
            }

            // 提取场景值
            if (preg_match('/scene="([^"]*)"/', $rawMsg, $matches)) {
                $info['scene'] = trim($matches[1]);
            }

            // 提取加密用户名
            if (preg_match('/encryptusername="([^"]*)"/', $rawMsg, $matches)) {
                $info['encryptusername'] = trim($matches[1]);
            }

            // 提取ticket
            if (preg_match('/ticket="([^"]*)"/', $rawMsg, $matches)) {
                $info['ticket'] = trim($matches[1]);
            }

            // 提取昵称
            if (preg_match('/fromnickname="([^"]*)"/', $rawMsg, $matches)) {
                $info['fromnickname'] = $info['nickname'] = trim($matches[1]);
            }

            // 提取请求内容
            if (preg_match('/content="([^"]*)"/', $rawMsg, $matches)) {
                $info['content'] = trim($matches[1]);
            }

            // 提取性别
            if (preg_match('/sex="([^"]*)"/', $rawMsg, $matches)) {
                $info['sex'] = (int)trim($matches[1]);
            }

            // 提取地理信息
            if (preg_match('/country="([^"]*)"/', $rawMsg, $matches)) {
                $info['country'] = trim($matches[1]);
            }
            if (preg_match('/province="([^"]*)"/', $rawMsg, $matches)) {
                $info['province'] = trim($matches[1]);
            }
            if (preg_match('/city="([^"]*)"/', $rawMsg, $matches)) {
                $info['city'] = trim($matches[1]);
            }

            // 提取个性签名
            if (preg_match('/sign="([^"]*)"/', $rawMsg, $matches)) {
                $info['sign'] = trim($matches[1]);
            }

            // 提取头像URL
            if (preg_match('/bigheadimgurl="([^"]*)"/', $rawMsg, $matches)) {
                $info['avatar'] = trim($matches[1]);
            }

            // 提取别名（如果有）
            if (preg_match('/alias="([^"]*)"/', $rawMsg, $matches)) {
                $info['alias'] = trim($matches[1]);
            }

            if (!empty($info['wxid'])) {
                return $info;
            }

        } catch (\Exception $e) {
            $this->logError('Error parsing friend request XML: ' . $e->getMessage(), [
                'raw_msg_preview' => substr($rawMsg, 0, 200)
            ]);
        }

        return null;
    }

    /**
     * 处理好友请求通知：同步联系人到Chatwoot并发送通知
     */
    private function handleFriendRequestNotification(XbotMessageContext $context, string $rawMsg, array $sourceAnalysis): void
    {
        $friendRequestInfo = $this->parseFriendRequestXml($rawMsg);
        
        if (!$friendRequestInfo || empty($friendRequestInfo['wxid'])) {
            $this->logError('Failed to extract contact info from friend request', ['raw_msg_preview' => substr($rawMsg, 0, 200)]);
            return;
        }

        $configManager = new ConfigManager($context->wechatBot);
        
        // 检查是否开启Chatwoot同步
        if (!$configManager->isEnabled('chatwoot')) {
            $this->log(__FUNCTION__, ['message' => 'Chatwoot sync disabled, skipping friend request notification']);
            return;
        }

        $chatwoot = new ChatwootClient($context->wechatBot);
        
        // 检查联系人是否已存在
        $existingContact = $chatwoot->searchContact($friendRequestInfo['wxid']);
        if ($existingContact) {
            // 更新现有联系人信息
            $this->updateChatwootContact($chatwoot, $existingContact, $friendRequestInfo);
            $contact = $existingContact;
        } else {
            // 创建新联系人 - 添加scene字段
            $contactData = $friendRequestInfo;
            $contactData['scene'] = $sourceAnalysis['details']['scene'] ?? '';
            
            $contact = $chatwoot->saveContact($contactData);
        }

        if (!$contact || !isset($contact['id'])) {
            $this->logError('Failed to save/update contact in Chatwoot', [
                'wxid' => $friendRequestInfo['wxid'],
                'nickname' => $friendRequestInfo['nickname'] ?? ''
            ]);
            return;
        }

        // 格式化通知消息
        $notificationMessage = $this->formatFriendRequestMessage($friendRequestInfo, $sourceAnalysis);

        // 发送通知消息到Chatwoot（以客服身份）
        $response = $chatwoot->sendMessageAsAgentToContact($contact, $notificationMessage);
        
        if ($response && $response->successful()) {
            $this->log(__FUNCTION__, ['message' => 'Friend request notification sent to Chatwoot',
                'contact_id' => $contact['id'],
                'wxid' => $friendRequestInfo['wxid'],
                'nickname' => $friendRequestInfo['nickname'] ?? ''
            ]);
        } else {
            $this->logError('Failed to send friend request notification to Chatwoot', [
                'contact_id' => $contact['id'],
                'response_status' => $response ? $response->status() : 'unknown'
            ]);
        }

        // 发送通知到文件传输助手
        $this->sendNotificationToFileHelper($context, $notificationMessage);
    }

    /**
     * 更新Chatwoot联系人信息
     */
    private function updateChatwootContact(ChatwootClient $chatwoot, array $existingContact, array $newContact): void
    {
        $contactId = $existingContact['id'];

        // 检查是否需要更新名称
        $currentName = $existingContact['name'] ?? '';
        $newName = $newContact['nickname'] ?? $newContact['wxid'];

        if ($currentName !== $newName && !empty($newName)) {
            $chatwoot->updateContactName($contactId, $newName);
        }

        // 检查是否需要更新头像
        $currentAvatar = $existingContact['avatar_url'] ?? $existingContact['custom_attributes']['avatar_url'] ?? '';
        $newAvatar = $newContact['avatar'] ?? '';

        if ($currentAvatar !== $newAvatar && !empty($newAvatar)) {
            $chatwoot->updateContactAvatarById($contactId, $newAvatar);
        }
    }

    /**
     * 格式化好友请求通知消息
     */
    private function formatFriendRequestMessage(array $friendRequestInfo, array $sourceAnalysis): string
    {
        $nickname = $friendRequestInfo['nickname'] ?? '未知用户';
        $content = $friendRequestInfo['content'] ?? '';
        $sourceDesc = $sourceAnalysis['source_desc'] ?? '未知来源';
        $scene = $sourceAnalysis['details']['scene'] ?? '';
        
        $message = "收到好友请求";
        $message .= "\n来自：{$nickname}";
        $message .= "\n来源：{$sourceDesc}";
        
        if (!empty($scene)) {
            $message .= " (scene:{$scene})";
        }
        
        if (!empty($content)) {
            $message .= "\n消息：{$content}";
        }
        
        return $message;
    }

    /**
     * 发送通知到文件传输助手
     */
    private function sendNotificationToFileHelper(XbotMessageContext $context, string $message): void
    {
        $this->sendTextMessage($context, $message, 'filehelper');
        
        $this->log(__FUNCTION__, ['message' => 'Friend request notification sent to file helper',
            'message' => $message,
            'target' => 'filehelper'
        ]);
    }

    /**
     * 将好友请求转换为文本消息，以便同步到Chatwoot
     */
    private function convertToTextMessage(XbotMessageContext $context, string $rawMsg, array $sourceAnalysis): void
    {
        $friendRequestInfo = $this->parseFriendRequestXml($rawMsg);
        
        if ($friendRequestInfo) {
            $fromnickname = $friendRequestInfo['fromnickname'] ?? '未知用户';
            $content = $friendRequestInfo['content'] ?? '';
            $sourceDesc = $sourceAnalysis['source_desc'] ?? '未知来源';
            $scene = $sourceAnalysis['details']['scene'] ?? '';
            
            $textMessage = "收到好友请求";
            $textMessage .= "\n来自：{$fromnickname}";
            $textMessage .= "\n来源：{$sourceDesc}";
            
            if (!empty($scene)) {
                $textMessage .= " (scene:{$scene})";
            }
            
            if (!empty($content)) {
                $textMessage .= "\n消息：{$content}";
            }
            
            // 修改消息为文本类型
            $context->msgType = 'MT_RECV_TEXT_MSG';
            $context->requestRawData['msg'] = $textMessage;
            
            $this->log(__FUNCTION__, ['message' => 'Friend request converted to text message',
                'original_type' => 'MT_RECV_FRIEND_MSG',
                'converted_message' => $textMessage,
                'from_nickname' => $fromnickname
            ]);
        } else {
            // 如果解析失败，使用通用消息
            $textMessage = "收到好友请求（详细信息解析失败）";
            $context->msgType = 'MT_RECV_TEXT_MSG';
            $context->requestRawData['msg'] = $textMessage;
        }
    }
}