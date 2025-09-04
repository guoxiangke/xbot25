<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Chatwoot;
use App\Services\XbotConfigManager;
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
        }
        
        // 格式化系统消息为文本格式
        $systemMessage = $this->formatSystemMessage($rawMsg);
        
        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改为bot发送的文本消息
        $context->requestRawData['from_wxid'] = $context->wechatBot->wxid; // 改为bot发送
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $systemMessage; // 使用原始文本，不添加前缀

        $this->log('System message converted to bot text message', [
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
        $configManager = new XbotConfigManager($context->wechatBot);
        $isChatwootEnabled = $configManager->isEnabled('chatwoot');
        if (!$isChatwootEnabled) {
            return;
        }

        $chatwoot = new Chatwoot($context->wechatBot);
        
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
                
                $this->log('Created basic group data for missing contact', [
                    'room_wxid' => $roomWxid,
                    'room_name' => $roomName
                ]);
            }
            
            // 保存群联系人到 Chatwoot
            $contact = $chatwoot->saveContact($groupData);
            $chatwoot->setLabel($contact['id'], '微信群');
            
            $this->log('Group contact saved to Chatwoot', [
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
}
