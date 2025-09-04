<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
use Closure;

/**
 * 内置命令处理器
 * 处理 whoami 等内置系统命令
 */
class BuiltinCommandHandler extends BaseXbotHandler
{
    private const COMMANDS = [
        '/help' => ['method' => 'handleHelpCommand', 'description' => '显示帮助信息'],
        '/whoami' => ['method' => 'handleWhoamiCommand', 'description' => '显示当前登录信息'],
        '/check online' => ['method' => 'handleCheckOnlineCommand', 'description' => '检查微信在线状态'],
        '/sync contacts' => ['method' => 'handleSyncContactsCommand', 'description' => '同步联系人列表'],
        '/list subscriptions' => ['method' => 'handleListSubscriptionsCommand', 'description' => '查看当前订阅列表'],
        '/get room_id' => ['method' => 'handleGetRoomIdCommand', 'description' => '获取群聊ID'],
        '/config' => ['method' => 'handleConfigCommand', 'description' => '查看和管理系统配置'],
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // BuiltinCommandHandler 作为最高优先级处理器，不检查 isProcessed 状态
        // 只检查消息类型，确保命令能够被优先处理
        // 避免对非文本消息进行不必要的命令解析

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
            $method = self::COMMANDS[$matchedCommand]['method'];
            $this->log('Executing command', ['command' => $matchedCommand, 'method' => $method, 'originalKeyword' => $keyword]);
            $this->$method($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /set 开头的命令（但先排除精确匹配的命令）
        if (str_starts_with($keyword, '/set ') && !$commandFound) {
            if ($context->isFromBot) {
                // 机器人执行配置命令
                $this->handleSetCommand($context, $keyword);
            } else {
                // 非机器人用户提示权限不足
                $this->handleSetCommandHint($context);
            }
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
        $helpText = "Hi，我是一个AI机器人，暂支持以下指令：\n";

        foreach (self::COMMANDS as $command => $config) {
            $helpText .= "{$command} - {$config['description']}\n";
        }

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
        $this->sendTextMessage($context, "已发送状态检查请求，请稍候...");
    }

    /**
     * 处理 /sync contacts 命令
     * 同步联系人列表
     */
    private function handleSyncContactsCommand(XbotMessageContext $context): void
    {
        // 检查是否启用Chatwoot同步
        $configManager = new XbotConfigManager($context->wechatBot);
        $isChatwootEnabled = $configManager->isEnabled('chatwoot');
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
        // 解析命令: /set chatwoot 0/1, /set room_msg 0/1, /set keyword_resources 0/1, /set keyword_sync 0/1
        // 使用 preg_split 处理多个空格的情况
        $parts = array_values(array_filter(preg_split('/\s+/', trim($keyword)), 'strlen'));

        if (count($parts) < 3) {
            $this->sendTextMessage($context, '⚠️ 命令格式错误\n正确格式：/set <setting> 0/1');
            $this->markAsReplied($context);
            return;
        }

        $command = $parts[1] ?? '';
        $value = $parts[2] ?? '';

        // 特殊处理 room_listen 命令
        if ($command === 'room_listen') {
            $this->handleSetRoomListenCommand($context, $value);
            return;
        }

        // 使用统一的配置设置方法
        $this->handleUnifiedSetCommand($context, $command, $value);
    }

    /**
     * 统一的配置设置处理方法
     */
    private function handleUnifiedSetCommand(XbotMessageContext $context, string $command, string $value): void
    {
        // 检查参数值
        if (!in_array($value, ['0', '1'])) {
            $this->sendTextMessage($context, '⚠️ 参数错误\n请使用 0（关闭）或 1（开启）');
            $this->markAsReplied($context);
            return;
        }

        $configManager = new XbotConfigManager($context->wechatBot);
        $isEnabled = $value === '1';

        try {
            // 检查配置是否存在
            if (!in_array($command, $configManager::getAvailableCommands())) {
                $availableCommands = implode(', ', $configManager::getAvailableCommands());
                $this->sendTextMessage($context, "⚠️ 未知的设置命令\n可用命令：{$availableCommands}");
                $this->markAsReplied($context);
                return;
            }

            // 设置配置
            $configManager->set($command, $isEnabled);

            // 发送确认消息
            $configName = $configManager->getConfigName($command);
            $this->sendConfigUpdateMessage($context, $configName, $isEnabled);
            $this->markAsReplied($context);

            $this->log('Config updated', [
                'command' => $command,
                'value' => $value,
                'enabled' => $isEnabled
            ]);

        } catch (\Exception $e) {
            $this->sendTextMessage($context, "❌ 配置设置失败：{$e->getMessage()}");
            $this->markAsReplied($context);
        }
    }


    /**
     * 发送配置更新消息
     */
    private function sendConfigUpdateMessage(XbotMessageContext $context, string $configName, bool $isEnabled): void
    {
        if ($isEnabled) {
            $this->sendTextMessage($context, "✅ 已开启{$configName}");
        } else {
            $this->sendTextMessage($context, "❌ 已关闭{$configName}");
        }
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

    /**
     * 处理获取群ID命令
     */
    private function handleGetRoomIdCommand(XbotMessageContext $context): void
    {
        if (!$context->isRoom) {
            $this->sendTextMessage($context, '⚠️ 此命令只能在群聊中使用');
            $this->markAsReplied($context);
            return;
        }

        $roomWxid = $context->requestRawData['room_wxid'] ?? '';
        $this->sendTextMessage($context, $roomWxid);
        $this->markAsReplied($context);
    }

    /**
     * 处理配置查看命令
     */
    private function handleConfigCommand(XbotMessageContext $context): void
    {
        $configManager = new XbotConfigManager($context->wechatBot);

        // 构建配置状态消息
        $message = "📋 当前配置状态：\n\n";
        $message .= "🌐 全局配置：\n";

        // 显示全局配置
        $globalConfigs = $configManager->getAll();
        foreach ($globalConfigs as $command => $value) {
            $status = $value ? '✅开启' : '❌关闭';
            $configName = $configManager->getConfigName($command);
            $message .= "• {$command}: {$status} {$configName}\n";
        }


        $message .= "\n💡 使用 /set <配置名> 0/1 修改配置";
        $message .= "\n💡 使用 /help 查看所有命令";

        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);

        $this->log('Config status displayed', [
            'is_room' => $context->isRoom,
            'room_wxid' => $context->roomWxid ?? null
        ]);
    }

    /**
     * 处理 /set room_listen 命令
     * 设置特定群的监听状态
     */
    private function handleSetRoomListenCommand(XbotMessageContext $context, string $value): void
    {
        if (!$context->isRoom) {
            $this->sendTextMessage($context, '❌ 此命令只能在群聊中使用');
            $this->markAsReplied($context);
            return;
        }

        $status = (int)$value;
        if ($status !== 0 && $status !== 1) {
            $this->sendTextMessage($context, '❌ 状态值必须是 0 (关闭) 或 1 (开启)');
            $this->markAsReplied($context);
            return;
        }

        $filter = new \App\Services\ChatroomMessageFilter($context->wechatBot, new XbotConfigManager($context->wechatBot));
        $success = $filter->setRoomListenStatus($context->roomWxid, (bool)$status);

        if ($success) {
            $statusText = $status ? '✅开启' : '❌关闭';
            $this->sendTextMessage($context, "📢 群监听状态已设置为: {$statusText}");
            $this->log('Room listen status set', [
                'room_wxid' => $context->roomWxid,
                'status' => $status,
                'success' => $success
            ]);
        } else {
            $this->sendTextMessage($context, '❌ 设置群监听状态失败');
        }

        $this->markAsReplied($context);
    }
}
