<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 内置命令处理器
 * 处理 whoami 等内置系统命令
 */
class BuiltinCommandHandler extends BaseXbotHandler
{
    private const COMMANDS = [
        'whoami' => 'handleWhoamiCommand',
        '/help' => 'handleHelpCommand',
        '/whoami' => 'handleWhoamiCommand',
        '/check online' => 'handleCheckOnlineCommand',
        '/sync contacts' => 'handleSyncContactsCommand',
        '/list subscriptions' => 'handleListSubscriptionsCommand',
        // 写一个群指令，让机器人自己设置是否监听群消息，而且还需要机器人自己来发，自己响应:已监听群xxx@chatroom，
        // 这个配置要存到
        // $contacts = $wechatBot->getMeta('contacts', $contacts);
        // $contacts[$thisRoomWxid]['listen_this_room'] = true/false;
        // '/set listen_this_room 0/1' => '',
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // BuiltinCommandHandler 作为最高优先级处理器，不检查 isProcessed 状态
        // 只检查消息类型，确保命令能够被优先处理
        // 避免对非文本消息进行不必要的命令解析

        // 调试日志：记录收到的消息类型和内容
        if (!$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return $next($context);
        }
        $keyword = trim($context->requestRawData['msg'] ?? '');

        // 处理命令匹配（包括大小写和空格处理）
        $normalizedKeyword = strtolower(trim($keyword));
        $commandFound = false;
        $matchedCommand = null;

        foreach (self::COMMANDS as $command => $method) {
            if (strtolower(trim($command)) === $normalizedKeyword) {
                $commandFound = true;
                $matchedCommand = $command;
                break;
            }
        }

        if ($commandFound && $matchedCommand) {
            $method = self::COMMANDS[$matchedCommand];
            $this->log('Executing command', ['command' => $matchedCommand, 'method' => $method, 'originalKeyword' => $keyword]);
            $this->$method($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /set 开头的命令（权限检查）
        if (str_starts_with($keyword, '/set ') && !$context->isFromBot) {
            $this->handleSetCommandHint($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /set 开头的命令（机器人执行）
        if (str_starts_with($keyword, '/set ') && $context->isFromBot) {
            $this->handleSetCommand($context, $keyword);
            $context->markAsProcessed(static::class);
            return $context;
        }

        return $next($context);
    }

    /**
     * 处理 whoami 命令
     */
    private function handleWhoamiCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $winToken = $wechatBot->wechatClient->token;
        $time = optional($wechatBot->login_at)->diffForHumans();
        $port = "{$wechatBot->client_id}@{$winToken}";

        $text = "登陆时长：$time\n"
            . "设备端口: $port\n"
            . "北京时间: {$wechatBot->login_at}";

        $this->sendTextMessage($context, $text);
        $this->markAsReplied($context);
    }

    /**
     * 处理 set 命令提示
     */
    private function handleSetCommandHint(XbotMessageContext $context): void
    {
        $this->sendTextMessage($context, "⚠️ 权限不足\n设置命令需要使用机器人自己来发送");
        $this->markAsReplied($context);
    }

    /**
     * 处理帮助命令
     */
    private function handleHelpCommand(XbotMessageContext $context): void
    {
        $helpText = "Hi，我是一个AI机器人，暂支持以下指令：\n"
            . "/help - 显示帮助信息\n"
            . "/whoami - 显示当前登录信息\n"
            . "/check online - 检查微信在线状态\n"
            . "/sync contacts - 同步联系人列表\n"
            . "/list subscriptions - 查看当前订阅列表\n"
            . "-========系统设置=======- \n"
            . "/set listen_this_room 0/1 - 设置当前群监听开关\n"
            . "/set room_msg 0/1 - 群消息处理开关\n"
            . "/set chatwoot 0/1 - Chatwoot同步开关\n"
            . "/set keyword_response_sync_to_chatwoot 0/1 - 关键词响应同步到Chatwoot开关\n"
            . "/set resources 0/1 - 资源系统响应开关";

        $this->sendTextMessage($context, $helpText);
        $this->markAsReplied($context);
    }

    /**
     * 处理 /check online 命令
     * 发送 xbot->getSelfInfo() 检查在线状态
     */
    private function handleCheckOnlineCommand(XbotMessageContext $context): void
    {
        $context->wechatBot->xbot()->getSelfInfo();
//        $this->sendTextMessage($context, "已发送状态检查请求");
    }

    /**
     * 处理 /sync contacts 命令
     * 同步联系人列表
     */
    private function handleSyncContactsCommand(XbotMessageContext $context): void
    {
        // 检查是否启用Chatwoot同步
        $isChatwootEnabled = $context->wechatBot->getMeta('chatwoot_enabled', false);
        if (!$isChatwootEnabled) {
            $this->sendTextMessage($context, '⚠️ Chatwoot同步未启用\n请先使用 /set chatwoot 1 启用');
            $this->markAsReplied($context);
            return;
        }

        $xbot = $context->wechatBot->xbot();

        // 调用三个同步API
        $xbot->getFriendsList();
        $xbot->getChatroomsList();
        $xbot->getPublicAccountsList();

        $this->sendTextMessage($context, '已请求同步，请稍后确认！');
        $this->markAsReplied($context);
    }


    /**
     * 处理机器人 set 命令
     */
    private function handleSetCommand(XbotMessageContext $context, string $keyword): void
    {
        // 解析命令: /set chatwoot 0/1, /set room_msg 0/1, /set listen_this_room 0/1
        // 使用 preg_split 处理多个空格的情况
        $parts = array_values(array_filter(preg_split('/\s+/', trim($keyword)), 'strlen'));
        
        if (count($parts) < 3) {
            $this->sendTextMessage($context, '⚠️ 命令格式错误\n正确格式：/set <setting> 0/1');
            $this->markAsReplied($context);
            return;
        }

        $command = $parts[1] ?? '';
        $value = $parts[2] ?? '';

        switch ($command) {
            case 'listen_this_room':
                $this->handleSetListenRoomCommand($context, $value);
                break;
            case 'chatwoot':
                $this->handleSetChatwootCommand($context, $value);
                break;
            case 'room_msg':
                $this->handleSetRoomMsgCommand($context, $value);
                break;
            case 'keyword_response_sync_to_chatwoot':
                $this->handleSetKeywordResponseSyncCommand($context, $value);
                break;
            case 'resources':
                $this->handleSetResourcesCommand($context, $value);
                break;
            default:
                $this->sendTextMessage($context, '⚠️ 未知的设置命令\n可用命令：chatwoot, room_msg, listen_this_room, keyword_response_sync_to_chatwoot, resources');
                $this->markAsReplied($context);
        }
    }

    /**
     * 处理群消息监听设置命令
     */
    private function handleSetListenRoomCommand(XbotMessageContext $context, string $value): void
    {
        // 检查是否在群聊中
        $roomWxid = $context->requestRawData['room_wxid'] ?? '';
        if (empty($roomWxid)) {
            $this->sendTextMessage($context, '⚠️ 此命令只能在群聊中使用');
            $this->markAsReplied($context);
            return;
        }

        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $wechatBot = $context->wechatBot;
        $isListen = $value === '1';

        // 获取现有的联系人数据
        $contacts = $wechatBot->getMeta('contacts', []);
        
        // 设置群聊监听状态
        if (!isset($contacts[$roomWxid])) {
            $contacts[$roomWxid] = [];
        }
        $contacts[$roomWxid]['listen_this_room'] = $isListen;
        
        // 保存设置
        $wechatBot->setMeta('contacts', $contacts);

        // 发送确认消息
        if ($isListen) {
            $this->sendTextMessage($context, "✅ 已监听群{$roomWxid}");
        } else {
            $this->sendTextMessage($context, "❌ 已停止监听群{$roomWxid}");
        }
        
        $this->markAsReplied($context);
        
        $this->log('Set room listening status', [
            'room_wxid' => $roomWxid,
            'listen_status' => $isListen,
            'command_value' => $value
        ]);
    }

    /**
     * 处理Chatwoot同步开关设置命令
     */
    private function handleSetChatwootCommand(XbotMessageContext $context, string $value): void
    {
        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $wechatBot = $context->wechatBot;
        $isEnabled = $value === '1';

        // 设置Chatwoot同步状态
        $wechatBot->setMeta('chatwoot_enabled', $isEnabled);

        // 发送确认消息
        if ($isEnabled) {
            $this->sendTextMessage($context, "✅ 已开启Chatwoot同步");
        } else {
            $this->sendTextMessage($context, "❌ 已关闭Chatwoot同步");
        }
        
        $this->markAsReplied($context);
        
        $this->log('Set chatwoot sync status', [
            'chatwoot_enabled' => $isEnabled,
            'command_value' => $value
        ]);
    }

    /**
     * 处理群消息处理开关设置命令
     */
    private function handleSetRoomMsgCommand(XbotMessageContext $context, string $value): void
    {
        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $wechatBot = $context->wechatBot;
        $isEnabled = $value === '1';

        // 设置群消息处理状态
        $wechatBot->setMeta('room_msg_enabled', $isEnabled);

        // 发送确认消息
        if ($isEnabled) {
            $this->sendTextMessage($context, "✅ 已开启群消息处理");
        } else {
            $this->sendTextMessage($context, "❌ 已关闭群消息处理");
        }
        
        $this->markAsReplied($context);
        
        $this->log('Set room message processing status', [
            'room_msg_enabled' => $isEnabled,
            'command_value' => $value
        ]);
    }

    /**
     * 处理关键词响应同步到Chatwoot开关设置命令
     */
    private function handleSetKeywordResponseSyncCommand(XbotMessageContext $context, string $value): void
    {
        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $wechatBot = $context->wechatBot;
        $isEnabled = $value === '1';

        // 设置关键词响应同步到Chatwoot状态
        $wechatBot->setMeta('keyword_response_sync_to_chatwoot_enabled', $isEnabled);

        // 发送确认消息
        if ($isEnabled) {
            $this->sendTextMessage($context, "✅ 已开启关键词响应同步到Chatwoot");
        } else {
            $this->sendTextMessage($context, "❌ 已关闭关键词响应同步到Chatwoot");
        }
        
        $this->markAsReplied($context);
        
        $this->log('Set keyword response sync to chatwoot status', [
            'keyword_response_sync_enabled' => $isEnabled,
            'command_value' => $value
        ]);
    }

    /**
     * 处理资源系统开关设置命令
     */
    private function handleSetResourcesCommand(XbotMessageContext $context, string $value): void
    {
        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $wechatBot = $context->wechatBot;
        $isEnabled = $value === '1';

        // 设置资源系统响应状态
        $wechatBot->setMeta('resources_enabled', $isEnabled);

        // 发送确认消息
        if ($isEnabled) {
            $this->sendTextMessage($context, "✅ 资源系统已开启");
        } else {
            $this->sendTextMessage($context, "❌ 资源系统已关闭");
        }
        
        $this->markAsReplied($context);
        
        $this->log('Set resources system status', [
            'resources_enabled' => $isEnabled,
            'command_value' => $value
        ]);
    }

    /**
     * 处理查看订阅列表命令
     */
    private function handleListSubscriptionsCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $wxid = $context->wxid;

        // 获取当前联系人的所有订阅
        $subscriptions = XbotSubscription::query()
            ->where('wechat_bot_id', $wechatBot->id)
            ->where('wxid', $wxid)
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->sendTextMessage($context, '暂无订阅');
            $this->markAsReplied($context);
            return;
        }

        // 构建订阅列表消息
        $subscriptionList = "当前订阅列表：\n";
        foreach ($subscriptions as $index => $subscription) {
            $hour = $this->getHourFromCron($subscription->cron);
            $subscriptionList .= ($index + 1) . ". {$subscription->keyword} (每天{$hour}点)\n";
        }

        $this->sendTextMessage($context, $subscriptionList);
        $this->markAsReplied($context);
    }

    /**
     * 从cron表达式中提取小时
     */
    private function getHourFromCron(string $cron): int
    {
        $parts = explode(' ', $cron);
        return isset($parts[1]) ? intval($parts[1]) : 7;
    }
}
