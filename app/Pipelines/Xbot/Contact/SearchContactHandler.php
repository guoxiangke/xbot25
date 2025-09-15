<?php

namespace App\Pipelines\Xbot\Contact;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 搜索联系人处理器
 * 处理 MT_SEARCH_CONTACT_MSG 类型的消息
 * 用于搜索联系人回调和主动加好友
 */
class SearchContactHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_SEARCH_CONTACT_MSG') ||
            $context->isFromBot) {
            return $next($context);
        }

        $data = $context->requestRawData;

        // 保存原始消息类型
        $context->setMetadata('origin_msg_type', $context->msgType);

        // 检查是否有v1和v2参数（主动加好友的回调）
        if (isset($data['v1']) && isset($data['v2'])) {
            $this->handleAddFriendCallback($context, $data);
        } else {
            // 更新联系人信息
            $this->handleContactUpdate($context, $data);
        }

        // 转换为文本消息以便同步到Chatwoot
        $this->convertToTextMessage($context, $data);

        // 继续传递到下一个处理器
        return $next($context);
    }

    /**
     * 处理主动加好友的回调
     * 参考旧代码中 v1, v2 参数的处理逻辑
     */
    private function handleAddFriendCallback(XbotMessageContext $context, array $data): void
    {
        $v1 = $data['v1'];
        $v2 = $data['v2'];
        $searchInfo = $data['search'] ?? '';

        try {
            $remark = "朋友介绍"; // 默认备注，可以后续扩展为配置项
            
            // 调用主动加好友API
            $xbot = $context->wechatBot->xbot();
            $result = $xbot->addFriendBySearchCallback($v1, $v2, $remark);
            
            $this->log(__FUNCTION__, [
                'message' => 'Add friend by search callback executed',
                'v1' => $v1,
                'v2' => $v2,
                'remark' => $remark,
                'search_info' => $searchInfo,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to add friend by search callback', [
                'v1' => $v1,
                'v2' => $v2,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 处理联系人信息更新
     * 参考旧代码中的联系人同步逻辑
     */
    private function handleContactUpdate(XbotMessageContext $context, array $data): void
    {
        try {
            $xbot = $context->wechatBot->xbot();
            
            // 更新好友列表
            $friendsResult = $xbot->getFriendsList();
            $this->log(__FUNCTION__, ['message' => 'Friends list updated', 'result' => $friendsResult]);
            
            // 更新群聊列表
            $roomsResult = $xbot->getChatroomsList();
            $this->log(__FUNCTION__, ['message' => 'Chatrooms list updated', 'result' => $roomsResult]);
            
            $this->log(__FUNCTION__, [
                'message' => 'Contact lists synchronized',
                'friends_result' => $friendsResult,
                'rooms_result' => $roomsResult,
                'trigger_data' => $data
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to update contact lists', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
        }
    }

    /**
     * 将搜索联系人消息转换为文本消息，以便同步到Chatwoot
     */
    private function convertToTextMessage(XbotMessageContext $context, array $data): void
    {
        if (isset($data['v1']) && isset($data['v2'])) {
            // 主动加好友回调
            $searchInfo = $data['search'] ?? '搜索回调';
            $textMessage = "执行主动加好友操作";
            
            if (!empty($searchInfo)) {
                $textMessage .= "\n搜索信息：{$searchInfo}";
            }
        } else {
            // 联系人更新
            $textMessage = "联系人信息同步更新";
            
            if (!empty($data)) {
                $dataInfo = json_encode($data, JSON_UNESCAPED_UNICODE);
                $textMessage .= "\n触发数据：{$dataInfo}";
            }
        }

        // 修改消息为文本类型
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $textMessage;
        
        $this->log(__FUNCTION__, [
            'message' => 'Search contact message converted to text',
            'original_type' => 'MT_SEARCH_CONTACT_MSG',
            'converted_message' => $textMessage
        ]);
    }
}