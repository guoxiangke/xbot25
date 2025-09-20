<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\WechatBot;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * 群邀请别名处理器
 * 处理私聊中的别名匹配并自动发送群邀请
 */
class RoomAliasHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

        // 只处理私聊文本消息
        if (!$this->isMessageType($context, 'MT_RECV_TEXT_MSG') || $context->isRoom) {
            return $next($context);
        }

        // 只处理用户发送的消息，不处理机器人自己的消息
        if ($context->isFromBot) {
            return $next($context);
        }

        $message = trim($context->requestRawData['msg'] ?? '');
        
        // 检查消息是否为纯数字或字母（可能的群邀请别名）
        if (!preg_match('/^[a-zA-Z0-9]+$/', $message)) {
            return $next($context);
        }

        // 查找匹配的群
        $matchedRoomWxid = $this->findRoomByAlias($context->wechatBot, $message);
        
        if ($matchedRoomWxid) {
            $this->sendRoomInvitation($context, $matchedRoomWxid, $message);
            // 标记为已处理，避免其他处理器重复处理
            $context->markAsProcessed(static::class);
            return $context;
        }

        return $next($context);
    }

    /**
     * 根据别名查找群
     */
    private function findRoomByAlias(WechatBot $wechatBot, string $alias): ?string
    {
        $configManager = new ConfigManager($wechatBot);
        return $configManager->findRoomByAlias($alias);
    }

    /**
     * 发送群邀请
     */
    private function sendRoomInvitation(XbotMessageContext $context, string $roomWxid, string $alias): void
    {
        try {
            $fromWxid = $context->requestRawData['from_wxid'] ?? '';
            
            // 获取群名称
            $contacts = $context->wechatBot->getMeta('contacts', []);
            $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? '群聊';
            
            // 发送群邀请 - 智能选择邀请方式
            $xbot = $context->wechatBot->xbot();
            $result = $this->sendSmartRoomInvitation($xbot, $roomWxid, $fromWxid);
            
            // 发送欢迎消息
            $welcomeMessage = $this->buildWelcomeMessage($context, $roomWxid, $fromWxid, $roomName);
            $this->sendTextMessage($context, $welcomeMessage);
            
            Log::info(__FUNCTION__, [
                'wechat_bot_id' => $context->wechatBot->id,
                'wxid' => $context->wechatBot->wxid,
                'from_wxid' => $fromWxid,
                'room_wxid' => $roomWxid,
                'room_name' => $roomName,
                'alias' => $alias,
                'result' => $result,
                'message' => 'Room invitation sent via alias'
            ]);

        } catch (\Exception $e) {
            Log::error('RoomAliasHandler: Failed to send room invitation', [
                'wechat_bot_id' => $context->wechatBot->id,
                'from_wxid' => $context->requestRawData['from_wxid'] ?? '',
                'room_wxid' => $roomWxid,
                'alias' => $alias,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 发送错误提示
            $this->sendTextMessage($context, "❎ 群邀请发送失败，请稍后再试");
        }
    }

    /**
     * 发送群邀请 - 统一使用邀请请求方式（更安全，适用于所有群）
     */
    private function sendSmartRoomInvitation($xbot, string $roomWxid, string $memberWxid): mixed
    {
        // 统一使用邀请请求方式，更安全且适用于所有群大小
        $this->log('Using invitation request for room', [
            'room_wxid' => $roomWxid,
            'member_wxid' => $memberWxid,
            'method' => 'sendChatroomInviteRequest'
        ]);
        
        return $xbot->sendChatroomInviteRequest($roomWxid, $memberWxid);
    }

    /**
     * 构建欢迎消息
     */
    private function buildWelcomeMessage(XbotMessageContext $context, string $roomWxid, string $fromWxid, string $roomName): string
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 获取群级别的欢迎消息模板
        $customWelcomeMsg = $configManager->getGroupConfig('room_welcome_msgs', $roomWxid);
        
        // 获取用户昵称用于变量替换
        $contacts = $context->wechatBot->getMeta('contacts', []);
        $userNickname = $contacts[$fromWxid]['nickname'] ?? $contacts[$fromWxid]['remark'] ?? $fromWxid;
        
        if (!empty($customWelcomeMsg)) {
            // 使用自定义欢迎消息模板
            $welcomeMessage = $this->replaceVariables($customWelcomeMsg, $userNickname, $roomName);
        } else {
            // 使用默认欢迎消息模板
            $welcomeMessage = "@{$userNickname}，您好，欢迎加入【{$roomName}】群👏";
        }
        
        return $welcomeMessage;
    }

    /**
     * 替换欢迎消息中的变量
     */
    private function replaceVariables(string $template, string $nickname, string $roomName): string
    {
        $replacements = [
            '@nickname' => "@{$nickname}",
            '【xx】' => "【{$roomName}】",
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}