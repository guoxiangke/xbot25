<?php

namespace App\Pipelines\Xbot\Contact;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
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

        // 检查是否开启自动同意好友请求
        $isAutoAgree = $context->wechatBot->getMeta('isAutoAgree', false);
        
        if ($isAutoAgree) {
            $this->handleAutoFriendRequest($context, $rawMsg);
        } else {
            // 不自动同意，记录日志
            $this->log('Friend request received (auto agree disabled)', [
                'from_wxid' => $context->fromWxid,
                'raw_msg' => $rawMsg
            ]);
        }

        $context->markAsProcessed(static::class);
        return $context;
    }

    /**
     * 处理自动同意好友请求
     * 参考 XbotCallbackController.php 第361-364行的逻辑
     */
    private function handleAutoFriendRequest(XbotMessageContext $context, string $rawMsg): void
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
            // 自动同意好友请求
            $context->wechatBot->xbot()->agreenFriend($scene, $encryptusername, $ticket);
            
            $this->log('Auto accepted friend request', [
                'from_nickname' => $fromnickname,
                'content' => $content,
                'scene' => $scene
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
     * 解析好友请求XML
     * 参考 XbotCallbackController.php 第361行的逻辑
     */
    private function parseFriendRequestXml(string $rawMsg): ?array
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            $info = [];
            
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
                $info['fromnickname'] = trim($matches[1]);
            }

            // 提取请求内容
            if (preg_match('/content="([^"]*)"/', $rawMsg, $matches)) {
                $info['content'] = trim($matches[1]);
            }

            if (!empty($info)) {
                return $info;
            }

        } catch (\Exception $e) {
            $this->logError('Error parsing friend request XML: ' . $e->getMessage(), [
                'raw_msg_preview' => substr($rawMsg, 0, 200)
            ]);
        }

        return null;
    }
}