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
        '/check online' => ['method' => 'handleCheckOnlineCommand', 'description' => '检查微信在线状态'],
        '/sync contacts' => ['method' => 'handleSyncContactsCommand', 'description' => '同步联系人列表'],
        '/list subscriptions' => ['method' => 'handleListSubscriptionsCommand', 'description' => '查看当前订阅列表'],
        '/get room_id' => ['method' => 'handleGetRoomIdCommand', 'description' => '获取群聊ID'],
        '/config' => ['method' => 'handleConfigCommand', 'description' => '', 'hidden' => true], // 隐藏命令，不在帮助中显示
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
            $this->sendTextMessage($context, '⚠️ Chatwoot同步未启用\n请先启用 chatwoot 配置');
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
        // 检查权限：只有机器人自己可以查看配置
        if (!$context->isSelfToSelf) {
            $this->sendTextMessage($context, "⚠️ 无权限执行此命令，仅机器人管理员可用");
            $this->markAsReplied($context);
            return;
        }

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

        // 添加群级别配置显示
        $message .= "\n🏘️ 群级别配置：\n";
        $message .= $this->getGroupLevelConfigs($context);

        $message .= "\n💡 使用 /help 查看所有命令";

        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);

        $this->log('Config status displayed', [
            'is_room' => $context->isRoom,
            'room_wxid' => $context->roomWxid ?? null
        ]);
    }

    /**
     * 获取群级别配置信息
     */
    private function getGroupLevelConfigs(XbotMessageContext $context): string
    {
        $wechatBot = $context->wechatBot;
        $configManager = new XbotConfigManager($wechatBot);

        // 如果是在群聊中执行，显示当前群的具体配置状态
        if ($context->isRoom && $context->roomWxid) {
            return $this->getCurrentRoomConfig($wechatBot, $configManager, $context->roomWxid);
        }

        // 如果是私聊，显示所有群的统计信息
        return $this->getAllRoomsConfigSummary($wechatBot, $configManager);
    }

    /**
     * 获取当前群的配置状态
     */
    private function getCurrentRoomConfig($wechatBot, $configManager, string $roomWxid): string
    {
        $groupConfigs = "📍 当前群配置状态：\n";

        // 1. 群消息处理配置
        $chatroomFilter = new ChatroomMessageFilter($wechatBot, $configManager);
        $roomListenStatus = $chatroomFilter->getRoomListenStatus($roomWxid);
        $globalRoomMsg = $configManager->isEnabled('room_msg');
        
        if ($roomListenStatus === null) {
            $roomListenDisplay = $globalRoomMsg ? "✅继承(开启)" : "❌继承(关闭)";
        } else {
            $roomListenDisplay = $roomListenStatus ? "✅特例开启" : "❌特例关闭";
        }
        $groupConfigs .= "• room_listen: {$roomListenDisplay}\n";

        // 2. 签到系统配置
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInStatus = $checkInService->getRoomCheckInStatus($roomWxid);
        $globalCheckIn = $configManager->isEnabled('check_in');
        
        if ($checkInStatus === null) {
            $checkInDisplay = $globalCheckIn ? "✅继承(开启)" : "❌继承(关闭)";
        } else {
            $checkInDisplay = $checkInStatus ? "✅特例开启" : "❌特例关闭";
        }
        $groupConfigs .= "• check_in_room: {$checkInDisplay}\n";

        // 3. YouTube 响应配置
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeAllowed = isset($youtubeRooms[$roomWxid]) && $youtubeRooms[$roomWxid];
        $youtubeDisplay = $youtubeAllowed ? "✅开启" : "❌关闭";
        $groupConfigs .= "• youtube_room: {$youtubeDisplay}\n";

        return $groupConfigs;
    }

    /**
     * 获取所有群配置的统计信息
     */
    private function getAllRoomsConfigSummary($wechatBot, $configManager): string
    {
        $groupConfigs = "📊 群级别配置统计：\n";

        // 1. 群消息处理配置
        $chatroomFilter = new ChatroomMessageFilter($wechatBot, $configManager);
        $roomConfigs = $chatroomFilter->getAllRoomConfigs();
        $roomCount = count($roomConfigs);
        if ($roomCount > 0) {
            $groupConfigs .= "• room_listen: {$roomCount}个群特例配置\n";
        } else {
            $groupConfigs .= "• room_listen: 无特例配置\n";
        }

        // 2. 签到系统配置
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInRoomConfigs = $checkInService->getAllRoomCheckInConfigs();
        $checkInCount = count($checkInRoomConfigs);
        if ($checkInCount > 0) {
            $groupConfigs .= "• check_in_room: {$checkInCount}个群特例配置\n";
        } else {
            $groupConfigs .= "• check_in_room: 无特例配置\n";
        }

        // 3. YouTube 响应配置
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeUsers = $wechatBot->getMeta('youtube_allowed_users', []);
        $youtubeCount = count($youtubeRooms) + count($youtubeUsers);
        if ($youtubeCount > 0) {
            $groupConfigs .= "• youtube_room: {$youtubeCount}个群/用户配置\n";
        } else {
            $groupConfigs .= "• youtube_room: 无配置\n";
        }

        return $groupConfigs;
    }

}
