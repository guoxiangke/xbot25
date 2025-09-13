<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
use App\Services\CheckInPermissionService;
use App\Services\ChatroomMessageFilter;
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
        '/config' => ['method' => 'handleConfigCommand', 'description' => '查看配置状态'],
        '/list subscriptions' => ['method' => 'handleListSubscriptionsCommand', 'description' => '查看当前订阅列表'],
        '/get wxid' => ['method' => 'handleGetWxidCommand', 'description' => '获取wxID'],
        '/sync contacts' => ['method' => 'handleSyncContactsCommand', 'description' => '同步联系人信息'],
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
            // 继续传递到下游处理器（如ChatwootHandler），让命令也同步到Chatwoot
            return $next($context);
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
     * 处理帮助命令
     */
    private function handleHelpCommand(XbotMessageContext $context): void
    {
        $helpText = "Hi，我是一个AI机器人，暂支持以下指令：\n";

        foreach (self::COMMANDS as $command => $config) {
            // 跳过隐藏的命令或空描述的命令
            if (!empty($config['hidden']) || empty($config['description'])) {
                continue;
            }
            $helpText .= "\n{$command} - {$config['description']}";
        }


        $this->sendTextMessage($context, $helpText);
        $this->markAsReplied($context);
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
     * 处理获取wxID命令
     */
    private function handleGetWxidCommand(XbotMessageContext $context): void
    {
        if ($context->isRoom) {
            // 在群聊中，返回群ID
            $roomWxid = $context->requestRawData['room_wxid'] ?? '';
            $this->sendTextMessage($context, $roomWxid);
        } else {
            // 在私聊中，返回对方的wxid
            $fromWxid = $context->requestRawData['from_wxid'] ?? '';
            $this->sendTextMessage($context, $fromWxid);
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 处理配置查看命令
     */
    private function handleConfigCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $configManager = new XbotConfigManager($wechatBot);

        // 获取所有配置状态
        $configText = "🔧 当前配置状态：\n\n";

        // 1. 全局配置
        $configText .= "📋 全局配置：\n";
        $globalConfigs = $configManager->getAll();
        foreach ($globalConfigs as $key => $value) {
            $configName = $configManager->getConfigName($key);
            $status = $value ? '✅ 已启用 ' : '❌ 已禁用 ';
            $configText .= "• {$configName}: {$status}{$key}\n";
        }

        // 2. Chatwoot配置
        $chatwootConfigs = $configManager->getAllChatwootConfigs();
        if (!empty(array_filter($chatwootConfigs))) {
            $configText .= "\n💬 Chatwoot配置：\n";
            foreach ($chatwootConfigs as $key => $value) {
                $configName = $configManager->getConfigName($key);
                if (!empty($value)) {
                    $displayValue = ($key === 'chatwoot_token') ? '***已设置***' : $value;
                    $configText .= "• {$configName}: {$displayValue} {$key}\n";
                } else {
                    $configText .= "• {$configName}: ❌ 未设置 {$key}\n";
                }
            }
        }

        // 3. 好友配置
        $friendConfigs = $configManager->getAllFriendConfigs();
        if (!empty(array_filter($friendConfigs))) {
            $configText .= "\n👥 好友配置：\n";
            foreach ($friendConfigs as $key => $value) {
                $configName = $configManager->getConfigName($key);
                if (!empty($value)) {
                    $configText .= "• {$configName}: {$value} {$key}\n";
                } else {
                    $configText .= "• {$configName}: ❌ 未设置 {$key}\n";
                }
            }

            // 显示今日好友请求处理统计
            $dailyStats = $configManager->getFriendConfig('daily_stats', []);
            if (!empty($dailyStats) && $dailyStats['date'] === now()->toDateString()) {
                $configText .= "\n📊 今日统计：\n";
                $configText .= "• 已处理好友请求: {$dailyStats['count']}个\n";
                if (!empty($dailyStats['last_processed'])) {
                    $configText .= "• 最近处理时间: {$dailyStats['last_processed']}\n";
                }
            }
        }

        // 4. 配置说明
        $configText .= "\n💡 配置命令说明：\n";
        $configText .= "• /set <key> <value> - 设置配置项\n";

        $this->sendTextMessage($context, $configText);
        $this->markAsReplied($context);
    }

    /**
     * 处理同步联系人命令
     */
    private function handleSyncContactsCommand(XbotMessageContext $context): void
    {
        try {
            $xbot = $context->wechatBot->xbot();

            // 同步好友列表
            $friendsResult = $xbot->getFriendsList();

            // 同步群聊列表
            $roomsResult = $xbot->getChatroomsList();

            // 同步公众号列表
            $publicResult = $xbot->getPublicAccountsList();

            $this->sendTextMessage($context, '已请求同步，请稍后确认！');
            $this->markAsReplied($context);

        } catch (\Exception $e) {
            $this->sendTextMessage($context, '同步失败：' . $e->getMessage());
            $this->markAsReplied($context);
        }
    }

}
