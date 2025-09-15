<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Services\CheckInPermissionService;
use Closure;
use Illuminate\Support\Str;

/**
 * 自消息处理器
 * 处理机器人发给自己的消息（系统指令）
 */
class SelfMessageHandler extends BaseXbotHandler
{
    /**
     * 群级别配置项
     */
    private const GROUP_LEVEL_CONFIGS = [
        'room_msg' => '群消息处理',
        'check_in' => '群签到系统',
        'youtube_room' => 'YouTube链接响应'
    ];

    /**
     * 群级别配置命令别名映射 (用户命令 => 实际配置项)
     */
    private const GROUP_CONFIG_ALIASES = [
        'room_listen' => 'room_msg',
        'check_in_room' => 'check_in',
        'youtube' => 'youtube_room'
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // 只处理机器人自己发送的消息（私聊给自己 或 在群里发送）
        if (!$this->shouldProcess($context) || !$context->isFromBot) {
            return $next($context);
        }

        if ($this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            $msg = $context->requestRawData['msg'] ?? '';

            if (Str::startsWith($msg, '/set ')) {
                $this->handleSetCommand($context, $msg);
                // 配置命令处理完成，标记为已处理，避免 TextMessageHandler 重复处理
                $context->markAsProcessed(static::class);
                return $context;
            }

            // 处理 /get chatwoot 命令
            if ($msg === '/get chatwoot') {
                $this->handleGetChatwootCommand($context);
                $context->markAsProcessed(static::class);
                return $context;
            }

            // 处理 /sync contacts 命令
            if ($msg === '/sync contacts') {
                $this->handleSyncContactsCommand($context);
                $context->markAsProcessed(static::class);
                return $context;
            }

            // 处理 /check online 命令
            if ($msg === '/check online') {
                $this->handleCheckOnlineCommand($context);
                $context->markAsProcessed(static::class);
                return $context;
            }

            // 处理 /config 命令（带参数设置配置，不带参数显示帮助）
            if (Str::startsWith($msg, '/config')) {
                // 使用更可靠的方法检查参数个数：按空格分割并过滤空字符串
                $parts = array_values(array_filter(preg_split('/\s+/', trim($msg)), 'strlen'));
                
                if (count($parts) === 1) { // 只有 /config
                    $this->handleConfigHelpCommand($context);
                    $context->markAsProcessed(static::class);
                    return $context;
                } elseif (count($parts) >= 3) { // 至少需要 /config、key、value 三个部分
                    $this->handleSetCommand($context, $msg);
                    // 配置命令处理完成，标记为已处理，避免 TextMessageHandler 重复处理
                    $context->markAsProcessed(static::class);
                    return $context;
                }
            }
        }

        return $next($context);
    }

    /**
     * 处理设置命令（支持 /set 和 /config 两种格式）
     */
    private function handleSetCommand(XbotMessageContext $context, string $message): void
    {
        // 使用 preg_split 处理多个连续空格，并过滤空元素，重新索引
        $parts = array_values(array_filter(preg_split('/\s+/', trim($message)), 'strlen'));

        if (count($parts) < 3) {
            $commandFormat = Str::startsWith($message, '/config') ? '/config' : '/set';
            $this->sendTextMessage($context, "用法: {$commandFormat} <key> <value>\n例如: {$commandFormat} room_msg 1");
            return;
        }

        $originalKey = $parts[1];
        $value = $parts[2];
        
        // 处理群级别配置命令别名（只在群聊中生效）
        $key = $this->resolveGroupConfigAlias($originalKey, $context->isRoom);

        // 检查是否为群级别配置（只在群聊中作为群级别配置处理）
        if (array_key_exists($key, self::GROUP_LEVEL_CONFIGS) && $context->isRoom) {
            $this->handleGroupLevelConfig($context, $key, $value);
            return;
        }

        // 允许处理的全局设置项（从 ConfigManager 获取所有可用配置）
        $configManager = new ConfigManager($context->wechatBot);
        $allowedKeys = ConfigManager::getAvailableCommands();
        if (!in_array($key, $allowedKeys)) {
            $globalKeys = implode(', ', $allowedKeys);
            // 显示用户可以实际使用的群配置命令（包括别名）
            $groupKeys = implode(', ', array_merge(array_keys(self::GROUP_LEVEL_CONFIGS), array_keys(self::GROUP_CONFIG_ALIASES)));
            $this->sendTextMessage($context, "未知的设置项: $originalKey\n全局配置: {$globalKeys}\n群配置: {$groupKeys}");
            return;
        }

        // 检查是否为 Chatwoot 配置项
        if ($configManager->isChatwootConfig($key)) {
            $this->handleChatwootConfigCommand($context, $key, $value);
            return;
        }

        // 检查是否为好友配置项
        if ($configManager->isFriendConfig($key)) {
            $this->handleFriendConfigCommand($context, $key, $value);
            return;
        }

        // 解析值：支持 0/1, ON/OFF, true/false
        $boolValue = $this->parseBooleanValue($value);

        if ($boolValue === null) {
            $this->sendTextMessage($context, "无效的值: $value\n请使用: 0/1, ON/OFF, true/false");
            return;
        }

        // 特殊处理：chatwoot 启用时检查必要配置
        if ($key === 'chatwoot' && $boolValue === true) {
            $missingConfigs = $configManager->isChatwootConfigComplete();
            
            if (!empty($missingConfigs)) {
                $configInstructions = "❌ 无法启用 Chatwoot，缺少必要配置：\n" . implode("\n", $missingConfigs);
                $configInstructions .= "\n\n📝 请使用以下命令设置配置项：";
                
                foreach ($missingConfigs as $configKey) {
                    $configName = $configManager->getConfigName($configKey);
                    if ($configKey === 'chatwoot_account_id') {
                        $configInstructions .= "\n• /set {$configKey} 1";
                    } elseif ($configKey === 'chatwoot_inbox_id') {
                        $configInstructions .= "\n• /set {$configKey} 2";
                    } elseif ($configKey === 'chatwoot_token') {
                        $configInstructions .= "\n• /set {$configKey} xxxx";
                    }
                }
                $this->sendTextMessage($context, $configInstructions);
                $this->markAsReplied($context);
                return;
            }
        }

        // 'chatwoot_enabled'
        // 'room_msg_enabled' ...
        $metaKey = "{$key}_enabled";
        $context->wechatBot->setMeta($metaKey, $boolValue);
        $status = $boolValue ? '已启用' : '已禁用';

        // 特殊处理：开启签到时自动开启群消息处理
        if ($key === 'check_in' && $boolValue === true) {
            $roomMsgKey = "room_msg_enabled";
            $context->wechatBot->setMeta($roomMsgKey, true);
            $this->sendTextMessage($context, "设置成功: $key $status\n⚠️ 签到功能需要群消息处理，已自动开启 room_msg");
        } else {
            $this->sendTextMessage($context, "设置成功: $key $status");
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 解析群级别配置命令别名（只在群聊中生效）
     */
    private function resolveGroupConfigAlias(string $key, bool $isRoom): string
    {
        // 只在群聊中应用别名映射
        if (!$isRoom) {
            return $key;
        }

        return self::GROUP_CONFIG_ALIASES[$key] ?? $key;
    }

    /**
     * 处理 Chatwoot 配置命令
     */
    private function handleChatwootConfigCommand(XbotMessageContext $context, string $key, string $value): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 验证值不为空
        if (empty(trim($value))) {
            $configName = $configManager->getConfigName($key);
            $this->sendTextMessage($context, "❌ {$configName} 的值不能为空");
            $this->markAsReplied($context);
            return;
        }

        // 特殊处理：对于数字类型的配置项进行验证
        if (in_array($key, ['chatwoot_account_id', 'chatwoot_inbox_id'])) {
            if (!is_numeric($value) || (int)$value <= 0) {
                $configName = $configManager->getConfigName($key);
                $this->sendTextMessage($context, "❌ {$configName} 必须是大于0的数字");
                $this->markAsReplied($context);
                return;
            }
            $value = (int)$value; // 转换为整数
        }

        // 设置配置
        $success = $configManager->setChatwootConfig($key, $value);
        
        if ($success) {
            $configName = $configManager->getConfigName($key);
            $this->sendTextMessage($context, "设置成功: {$configName} = {$value}");
            
            // 检查是否所有 Chatwoot 配置都已设置完成
            $missingConfigs = $configManager->isChatwootConfigComplete();
            if (empty($missingConfigs)) {
                // 自动启用 Chatwoot
                $configManager->setConfig('chatwoot', true);
                $this->sendTextMessage($context, "✅ 所有 Chatwoot 配置已完成，已自动启用 Chatwoot");
            } else {
                $this->sendTextMessage($context, "💡 还需设置：" . implode(', ', $missingConfigs));
            }
        } else {
            $this->sendTextMessage($context, "❌ 设置失败");
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 处理获取 Chatwoot 配置命令
     */
    private function handleGetChatwootCommand(XbotMessageContext $context): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        $chatwootConfigs = $configManager->getAllChatwootConfigs();
        
        $message = "🔧 Chatwoot 配置状态：\n\n";
        
        foreach ($chatwootConfigs as $configKey => $value) {
            $configName = $configManager->getConfigName($configKey);
            $displayValue = !empty($value) ? $value : '❌未设置';
            
            // 对于 token 只显示前几位和后几位，中间用星号代替
            if ($configKey === 'chatwoot_token' && !empty($value)) {
                $displayValue = strlen($value) > 10 
                    ? substr($value, 0, 4) . '***' . substr($value, -4)
                    : '***' . substr($value, -2);
            }
            
            $message .= "• {$configName}: {$displayValue}\n";
        }

        // 检查配置完整性
        $missingConfigs = $configManager->isChatwootConfigComplete();
        if (empty($missingConfigs)) {
            $message .= "\n✅ 配置完整";
            
            // 检查 chatwoot 是否已启用
            $isChatwootEnabled = $configManager->isEnabled('chatwoot');
            $message .= $isChatwootEnabled ? "，Chatwoot 已启用" : "，但 Chatwoot 未启用";
        } else {
            $message .= "\n⚠️ 缺少配置：" . implode(', ', $missingConfigs);
        }

        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 解析布尔值
     */
    private function parseBooleanValue(string $value): ?bool
    {
        $value = strtolower(trim($value));

        $trueValues = ['1', 'on', 'true', 'yes', 'enable'];
        $falseValues = ['0', 'off', 'false', 'no', 'disable'];

        if (in_array($value, $trueValues)) {
            return true;
        }

        if (in_array($value, $falseValues)) {
            return false;
        }

        return null;
    }

    /**
     * 处理群级别配置
     */
    private function handleGroupLevelConfig(XbotMessageContext $context, string $key, string $value): void
    {
        // 解析值
        $boolValue = $this->parseBooleanValue($value);
        if ($boolValue === null) {
            $this->sendTextMessage($context, "无效的值: $value\n请使用: 0/1, ON/OFF, true/false");
            return;
        }

        // 群级别配置必须在群聊中执行
        if (!$context->isRoom) {
            $this->sendTextMessage($context, "群级别配置只能在群聊中设置");
            return;
        }

        $roomWxid = $context->roomWxid;
        $status = $boolValue ? '已启用' : '已禁用';
        $configName = self::GROUP_LEVEL_CONFIGS[$key];

        switch ($key) {
            case 'room_msg':
                $this->handleRoomMsgConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;

            case 'check_in':
                $autoEnabledRoomMsg = $this->handleCheckInRoomConfig($context, $roomWxid, $boolValue);
                $message = "群设置成功: {$configName} {$status}";
                if ($autoEnabledRoomMsg) {
                    $message .= "\n自动启用了该群的消息处理 (room_msg)";
                }
                $this->sendTextMessage($context, $message);
                break;

            case 'youtube_room':
                $this->handleYouTubeRoomConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;
        }

        $this->markAsReplied($context);
    }

    /**
     * 处理群消息监听配置
     */
    private function handleRoomMsgConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        $filter = new ChatroomMessageFilter($context->wechatBot, $configManager);
        $filter->setRoomListenStatus($roomWxid, $enabled);
    }

    /**
     * 处理群签到配置
     * 
     * @return bool 是否自动启用了 room_msg
     */
    private function handleCheckInRoomConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): bool
    {
        $checkInService = new CheckInPermissionService($context->wechatBot);
        $checkInService->setRoomCheckInStatus($roomWxid, $enabled);
        
        $autoEnabledRoomMsg = false;
        
        // 当启用群签到时，自动启用该群的消息处理以确保签到功能可以正常工作
        if ($enabled) {
            $configManager = new ConfigManager($context->wechatBot);
            $filter = new ChatroomMessageFilter($context->wechatBot, $configManager);
            
            // 只有在全局 room_msg 关闭且该群没有设置 room_msg 时才自动启用
            if (!$configManager->isEnabled('room_msg')) {
                $roomConfigs = $context->wechatBot->getMeta('room_msg_specials', []);
                
                // 如果该群还没有专门的 room_msg 配置，则自动设置为开启
                if (!isset($roomConfigs[$roomWxid])) {
                    $filter->setRoomListenStatus($roomWxid, true);
                    $autoEnabledRoomMsg = true;
                }
            }
        }
        
        return $autoEnabledRoomMsg;
    }

    /**
     * 处理YouTube群配置
     */
    private function handleYouTubeRoomConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $wechatBot = $context->wechatBot;
        $allowedRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        
        if ($enabled) {
            // 添加到允许列表
            if (!in_array($roomWxid, $allowedRooms)) {
                $allowedRooms[] = $roomWxid;
            }
        } else {
            // 从允许列表中移除
            $allowedRooms = array_filter($allowedRooms, function($room) use ($roomWxid) {
                return $room !== $roomWxid;
            });
        }
        
        $wechatBot->setMeta('youtube_allowed_rooms', array_values($allowedRooms));
    }

    /**
     * 处理 /sync contacts 命令
     * 同步联系人列表
     */
    private function handleSyncContactsCommand(XbotMessageContext $context): void
    {
        // 检查是否启用Chatwoot同步
        $configManager = new ConfigManager($context->wechatBot);
        $isChatwootEnabled = $configManager->isEnabled('chatwoot');
        if (!$isChatwootEnabled) {
            $this->sendTextMessage($context, "⚠️ Chatwoot同步未启用\n请先启用 chatwoot 配置");
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
     * 处理 /check online 命令
     * 发送 xbot->getSelfInfo() 检查在线状态
     */
    private function handleCheckOnlineCommand(XbotMessageContext $context): void
    {
        $context->wechatBot->xbot()->getSelfInfo();
        $this->sendTextMessage($context, "已发送状态检查请求，请稍候...");
    }

    /**
     * 处理配置帮助命令（显示配置状态和可用命令）
     */
    private function handleConfigHelpCommand(XbotMessageContext $context): void
    {
        $configManager = new ConfigManager($context->wechatBot);

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

        // 添加配置命令帮助
        $message .= "\n🔧 配置管理命令：\n";
        $message .= "/set <key> <value> - 设置配置项\n";
        $message .= "/get chatwoot - 查看Chatwoot配置详情\n";
        $message .= "/sync contacts - 同步联系人列表\n";
        $message .= "/check online - 检查微信在线状态\n\n";

        $message .= "\n💡 其他配置项：\n";
        $chatwootConfigs = array_keys(ConfigManager::CHATWOOT_CONFIGS);
        $message .= "• " . implode("\n• ", $chatwootConfigs);

        // 添加简化的群命令提示
        $message .= "\n\n🏘️ 群级别配置 (简化命令)：\n";
        $message .= "• /set check_in 1 - 群签到系统\n";
        $message .= "• /set youtube 1 - YouTube链接响应\n";
        $message .= "• /set room_msg 1 - 群消息处理\n";

        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 获取群级别配置信息
     */
    private function getGroupLevelConfigs(XbotMessageContext $context): string
    {
        $wechatBot = $context->wechatBot;
        $configManager = new ConfigManager($wechatBot);

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
        $groupConfigs .= "• room_msg: {$roomListenDisplay}\n";

        // 2. 签到系统配置
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInStatus = $checkInService->getRoomCheckInStatus($roomWxid);
        $globalCheckIn = $configManager->isEnabled('check_in');
        
        if ($checkInStatus === null) {
            $checkInDisplay = $globalCheckIn ? "✅继承(开启)" : "❌继承(关闭)";
        } else {
            $checkInDisplay = $checkInStatus ? "✅特例开启" : "❌特例关闭";
        }
        $groupConfigs .= "• check_in (/set check_in): {$checkInDisplay}\n";

        // 3. YouTube 响应配置
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeAllowed = isset($youtubeRooms[$roomWxid]) && $youtubeRooms[$roomWxid];
        $youtubeDisplay = $youtubeAllowed ? "✅开启" : "❌关闭";
        $groupConfigs .= "• youtube (/set youtube): {$youtubeDisplay}\n";

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
            $groupConfigs .= "• room_msg: {$roomCount}个群特例配置\n";
        } else {
            $groupConfigs .= "• room_msg: 无特例配置\n";
        }

        // 2. 签到系统配置
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInRoomConfigs = $checkInService->getAllRoomCheckInConfigs();
        $checkInCount = count($checkInRoomConfigs);
        if ($checkInCount > 0) {
            $groupConfigs .= "• check_in: {$checkInCount}个群特例配置\n";
        } else {
            $groupConfigs .= "• check_in: 无特例配置\n";
        }

        // 3. YouTube 响应配置
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeUsers = $wechatBot->getMeta('youtube_allowed_users', []);
        $youtubeCount = count($youtubeRooms) + count($youtubeUsers);
        if ($youtubeCount > 0) {
            $groupConfigs .= "• youtube: {$youtubeCount}个群/用户配置\n";
        } else {
            $groupConfigs .= "• youtube: 无配置\n";
        }

        return $groupConfigs;
    }

    /**
     * 处理好友配置命令
     */
    private function handleFriendConfigCommand(XbotMessageContext $context, string $key, string $value): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 验证值不为空
        if (empty(trim($value))) {
            $configName = $configManager->getConfigName($key);
            $this->sendTextMessage($context, "❌ {$configName} 的值不能为空");
            $this->markAsReplied($context);
            return;
        }

        // 特殊处理数字类型配置（friend_daily_limit）
        if ($key === 'friend_daily_limit') {
            if (!is_numeric($value) || (int)$value <= 0) {
                $this->sendTextMessage($context, "❌ 每日好友请求处理上限必须是大于0的数字");
                $this->markAsReplied($context);
                return;
            }
            
            $configManager->setFriendConfig($key, (int)$value);
            $this->sendTextMessage($context, "✅ 好友配置更新成功\n每日处理上限: {$value}");
            $this->markAsReplied($context);
            return;
        }

        // 处理欢迎消息模板（支持@nickname变量）
        if (in_array($key, ['welcome_msg', 'room_welcome_msg'])) {
            $configManager->setFriendConfig($key, $value);
            $configName = $configManager->getConfigName($key);
            
            // 提示变量使用说明
            $tips = "✅ {$configName}更新成功\n";
            $tips .= "消息模板: {$value}\n";
            
            if (strpos($value, '@nickname') !== false) {
                $tips .= "\n💡 @nickname 会自动替换为好友的昵称或备注";
            } else {
                $tips .= "\n💡 提示: 可以使用 @nickname 变量自动替换为好友昵称";
            }
            
            $this->sendTextMessage($context, $tips);
            $this->markAsReplied($context);
            return;
        }

        // 其他未知的好友配置项
        $this->sendTextMessage($context, "❌ 未知的好友配置项: {$key}");
        $this->markAsReplied($context);
    }

}
