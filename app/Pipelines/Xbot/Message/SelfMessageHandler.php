<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\WechatBot;
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
        'youtube_room' => 'YouTube链接响应',
        'room_quit' => '退群监控',
        'room_alias' => '群邀请别名',
    ];

    /**
     * 群级别配置命令别名映射 (用户命令 => 实际配置项)
     */
    private const GROUP_CONFIG_ALIASES = [
        'room_listen' => 'room_msg',
        'check_in_room' => 'check_in',
        'youtube' => 'youtube_room'
    ];

    /**
     * 检查是否应该处理此消息
     */
    protected function shouldProcess(XbotMessageContext $context): bool
    {
        // 基础检查：消息未被处理
        if ($context->isProcessed()) {
            return false;
        }

        // 只处理文本消息
        if (!$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return false;
        }

        // 只处理机器人发送的消息
        return $context->isFromBot;
    }

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // 处理机器人发送的消息 或 管理员发送的配置命令
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

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

        // 处理 /get room_alias 命令
        if ($msg === '/get room_alias') {
            $this->handleGetRoomAliasCommand($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /get room_msg 命令
        if ($msg === '/get room_msg') {
            $this->handleGetRoomMsgCommand($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /get check_in 命令
        if ($msg === '/get check_in') {
            $this->handleGetCheckInCommand($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /get room_quit 命令
        if ($msg === '/get room_quit') {
            $this->handleGetRoomQuitCommand($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /get youtube 命令
        if ($msg === '/get youtube') {
            $this->handleGetYoutubeCommand($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /get 黑名单 命令
        if ($msg === '/get 黑名单') {
            $this->handleGetBlacklistCommand($context);
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

        return $next($context);
    }

    /**
     * 处理设置命令（支持 /set 和 /config 两种格式）
     */
    private function handleSetCommand(XbotMessageContext $context, string $message): void
    {
        // 改进的命令解析，支持引号内的空格
        $parts = $this->parseCommandArguments($message);

        if (count($parts) < 3) {
            $commandFormat = Str::startsWith($message, '/config') ? '/config' : '/set';
            $this->sendTextMessage($context, "用法: {$commandFormat} <key> <value>\n例如: {$commandFormat} room_msg 1");
            return;
        }

        $originalKey = $parts[1];
        // 对于支持空格的配置项，将所有剩余部分作为 value
        if (in_array($originalKey, ['welcome_msg', 'room_alias']) && count($parts) > 3) {
            $value = implode(' ', array_slice($parts, 2));
        } else {
            $value = $parts[2];
        }

        // 处理黑名单命令
        if ($originalKey === '黑名单') {
            $this->handleBlacklistCommand($context, $value);
            return;
        }
        
        // 处理群级别配置命令别名（只在群聊中生效）
        $key = $this->resolveGroupConfigAlias($originalKey, $context->isRoom);

        // 检查是否为群级别配置
        if (array_key_exists($key, self::GROUP_LEVEL_CONFIGS)) {
            // room_alias 是字符串配置，不是布尔配置
            if ($key === 'room_alias') {
                $this->handleRoomAliasConfig($context, $key, $value);
                return;
            }
            
            // room_msg 等配置既可以作为系统级配置，也可以作为群级别配置
            $systemLevelKeys = ['room_msg', 'check_in', 'room_quit'];
            
            if (in_array($key, $systemLevelKeys)) {
                if ($context->isRoom) {
                    // 在群聊中设置群级别配置
                    $this->handleGroupLevelConfig($context, $key, $value);
                    return;
                }
                // 在私聊中不返回，继续执行系统级配置处理逻辑
            } else {
                // 其他群级别配置只在群聊中作为群级别配置处理
                if ($context->isRoom) {
                    $this->handleGroupLevelConfig($context, $key, $value);
                } else {
                    $this->sendTextMessage($context, "群级别配置只能在群聊中设置");
                    $this->markAsReplied($context);
                }
                return;
            }
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

        // 检查是否为字符串配置项
        if ($configManager->isStringConfig($key)) {
            $this->handleStringConfigCommand($context, $key, $value);
            return;
        }

        // 检查是否为其他群级配置项（只在群聊中可设置群级配置）
        if ($configManager->isGroupConfig($key) && $context->isRoom) {
            $this->handleGroupConfigCommand($context, $key, $value);
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
                $configInstructions = "❎ 无法启用 Chatwoot，缺少必要配置：\n" . implode("\n", $missingConfigs);
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
            $this->sendTextMessage($context, "❎ {$configName} 的值不能为空");
            $this->markAsReplied($context);
            return;
        }

        // 特殊处理：对于数字类型的配置项进行验证
        if (in_array($key, ['chatwoot_account_id', 'chatwoot_inbox_id'])) {
            if (!is_numeric($value) || (int)$value <= 0) {
                $configName = $configManager->getConfigName($key);
                $this->sendTextMessage($context, "❎ {$configName} 必须是大于0的数字");
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
            $this->sendTextMessage($context, "❎ 设置失败");
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
            $displayValue = !empty($value) ? $value : '❎未设置';
            
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

            case 'room_quit':
                $this->handleRoomQuitConfig($context, $roomWxid, $boolValue);
                $this->sendTextMessage($context, "群设置成功: {$configName} {$status}");
                break;
        }

        $this->markAsReplied($context);
    }

    /**
     * 处理群邀请别名配置
     */
    private function handleRoomAliasConfig(XbotMessageContext $context, string $key, string $value): void
    {
        // 群级别配置必须在群聊中执行
        if (!$context->isRoom) {
            $this->sendTextMessage($context, "群邀请别名只能在群聊中设置");
            $this->markAsReplied($context);
            return;
        }

        $configManager = new ConfigManager($context->wechatBot);
        $roomWxid = $context->roomWxid;
        
        // 验证值不为空且为数字或字母
        $alias = trim($value);
        if (empty($alias)) {
            $this->sendTextMessage($context, "❎ 群邀请别名不能为空");
            $this->markAsReplied($context);
            return;
        }
        
        // 检查别名格式（只允许数字和字母）
        if (!preg_match('/^[a-zA-Z0-9]+$/', $alias)) {
            $this->sendTextMessage($context, "❎ 群邀请别名只能包含数字和字母");
            $this->markAsReplied($context);
            return;
        }
        
        // 检查别名是否已被其他群使用
        if ($configManager->isAliasUsed($alias, $roomWxid)) {
            $this->sendTextMessage($context, "❎ 别名 '{$alias}' 已被其他群使用，请选择其他别名");
            $this->markAsReplied($context);
            return;
        }

        // 设置群邀请别名
        $configManager->setGroupConfig($key, $alias, $roomWxid);
        
        $this->sendTextMessage($context, "✅ 群邀请别名设置成功\n别名: {$alias}\n用户私聊回复此别名即可收到群邀请");
        $this->markAsReplied($context);
    }

    /**
     * 处理欢迎消息配置（根据发送场景自动选择存储位置）
     */
    private function handleWelcomeMessageConfig(XbotMessageContext $context, string $key, string $value): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 验证值不为空
        $welcomeMsg = trim($value);
        if (empty($welcomeMsg)) {
            $this->sendTextMessage($context, "❎ 欢迎消息不能为空");
            $this->markAsReplied($context);
            return;
        }
        
        if ($context->isRoom) {
            // 群聊中：设置该群的新成员欢迎消息
            $roomWxid = $context->roomWxid;
            
            // 获取现有的群欢迎消息数组
            $roomWelcomeMsgs = $configManager->getGroupConfig('room_welcome_msgs', null, []);
            
            // 更新该群的欢迎消息
            $roomWelcomeMsgs[$roomWxid] = $welcomeMsg;
            
            // 保存到 room_welcome_msgs 配置
            $configManager->setGroupConfig('room_welcome_msgs', $roomWelcomeMsgs, $roomWxid);
            
            $this->sendTextMessage($context, "✅ 群新成员欢迎消息设置成功\n模板: {$welcomeMsg}\n\n💡 支持变量：\n@nickname - 新成员昵称\n【xx】 - 群名称\n📧 新成员加入时将同时发送私聊和群内消息");
        } else {
            // 私聊中：设置系统级好友欢迎消息
            $configManager->setStringConfig($key, $welcomeMsg);
            
            $tips = "✅ 好友欢迎消息设置成功\n";
            $tips .= "消息模板: {$welcomeMsg}\n";
            
            if (strpos($welcomeMsg, '@nickname') !== false) {
                $tips .= "\n💡 @nickname 会自动替换为好友的昵称或备注";
            } else {
                $tips .= "\n💡 提示: 可以使用 @nickname 变量自动替换为好友昵称";
            }
            
            $this->sendTextMessage($context, $tips);
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
     * 处理退群监控群配置
     */
    private function handleRoomQuitConfig(XbotMessageContext $context, string $roomWxid, bool $enabled): void
    {
        $wechatBot = $context->wechatBot;
        $roomQuitConfigs = $wechatBot->getMeta('room_quit_specials', []);
        
        if ($enabled) {
            // 设置为特例开启
            $roomQuitConfigs[$roomWxid] = true;
        } else {
            // 设置为特例关闭
            $roomQuitConfigs[$roomWxid] = false;
        }
        
        $wechatBot->setMeta('room_quit_specials', $roomQuitConfigs);
    }

    /**
     * 处理获取群消息处理配置命令
     */
    private function handleGetRoomMsgCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $configManager = new ConfigManager($wechatBot);
        $contacts = $wechatBot->getMeta('contacts', []);
        
        $chatroomFilter = new ChatroomMessageFilter($wechatBot, $configManager);
        $roomConfigs = $chatroomFilter->getAllRoomConfigs();
        
        // 构建响应消息
        if (empty($roomConfigs)) {
            $globalStatus = $configManager->isEnabled('room_msg') ? '全局开启' : '全局关闭';
            $message = "📋 群消息处理配置状态\n\n❌ 暂无群级别特例配置\n\n🌐 全局配置: $globalStatus\n\n💡 使用方法：\n在群聊中发送：/set room_msg 1 开启该群消息处理\n在群聊中发送：/set room_msg 0 关闭该群消息处理";
        } else {
            $globalStatus = $configManager->isEnabled('room_msg') ? '全局开启' : '全局关闭';
            $message = "📋 群消息处理配置状态\n\n🌐 全局配置: $globalStatus\n\n";
            $message .= "✅ 已配置 " . count($roomConfigs) . " 个群级别特例：\n\n";
            
            foreach ($roomConfigs as $roomWxid => $enabled) {
                $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? $roomWxid;
                $status = $enabled ? '特例开启' : '特例关闭';
                $statusEmoji = $enabled ? '✅' : '❌';
                $message .= "$statusEmoji $status\n";
                $message .= "   群名: $roomName\n";
                $message .= "   群ID: $roomWxid\n\n";
            }
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 处理获取群签到配置命令
     */
    private function handleGetCheckInCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $configManager = new ConfigManager($wechatBot);
        $contacts = $wechatBot->getMeta('contacts', []);
        
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInConfigs = $checkInService->getAllRoomCheckInConfigs();
        
        // 构建响应消息
        if (empty($checkInConfigs)) {
            $globalStatus = $configManager->isEnabled('check_in') ? '全局开启' : '全局关闭';
            $message = "📋 群签到配置状态\n\n❌ 暂无群级别特例配置\n\n🌐 全局配置: $globalStatus\n\n💡 使用方法：\n在群聊中发送：/set check_in 1 开启该群签到\n在群聊中发送：/set check_in 0 关闭该群签到";
        } else {
            $globalStatus = $configManager->isEnabled('check_in') ? '全局开启' : '全局关闭';
            $message = "📋 群签到配置状态\n\n🌐 全局配置: $globalStatus\n\n";
            $message .= "✅ 已配置 " . count($checkInConfigs) . " 个群级别特例：\n\n";
            
            foreach ($checkInConfigs as $roomWxid => $enabled) {
                $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? $roomWxid;
                $status = $enabled ? '特例开启' : '特例关闭';
                $statusEmoji = $enabled ? '✅' : '❌';
                $message .= "$statusEmoji $status\n";
                $message .= "   群名: $roomName\n";
                $message .= "   群ID: $roomWxid\n\n";
            }
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 处理获取群退出监控配置命令
     */
    private function handleGetRoomQuitCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $configManager = new ConfigManager($wechatBot);
        $contacts = $wechatBot->getMeta('contacts', []);
        
        $roomQuitConfigs = $wechatBot->getMeta('room_quit_specials', []);
        
        // 构建响应消息
        if (empty($roomQuitConfigs)) {
            $globalStatus = $configManager->isEnabled('room_quit') ? '全局开启' : '全局关闭';
            $message = "📋 群退出监控配置状态\n\n❌ 暂无群级别特例配置\n\n🌐 全局配置: $globalStatus\n\n💡 使用方法：\n在群聊中发送：/set room_quit 1 开启该群退出监控\n在群聊中发送：/set room_quit 0 关闭该群退出监控";
        } else {
            $globalStatus = $configManager->isEnabled('room_quit') ? '全局开启' : '全局关闭';
            $message = "📋 群退出监控配置状态\n\n🌐 全局配置: $globalStatus\n\n";
            $message .= "✅ 已配置 " . count($roomQuitConfigs) . " 个群级别特例：\n\n";
            
            foreach ($roomQuitConfigs as $roomWxid => $enabled) {
                $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? $roomWxid;
                $status = $enabled ? '特例开启' : '特例关闭';
                $statusEmoji = $enabled ? '✅' : '❌';
                $message .= "$statusEmoji $status\n";
                $message .= "   群名: $roomName\n";
                $message .= "   群ID: $roomWxid\n\n";
            }
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 处理获取YouTube响应配置命令
     */
    private function handleGetYoutubeCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $contacts = $wechatBot->getMeta('contacts', []);
        
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeUsers = $wechatBot->getMeta('youtube_allowed_users', []);
        
        $totalConfigs = count($youtubeRooms) + count($youtubeUsers);
        
        // 构建响应消息
        if ($totalConfigs === 0) {
            $message = "📋 YouTube响应配置状态\n\n❌ 暂无已配置的YouTube响应\n\n💡 使用方法：\n在群聊中发送：/set youtube 1 开启该群YouTube响应\n在私聊中发送：/set youtube 1 开启该用户YouTube响应";
        } else {
            $message = "📋 YouTube响应配置状态\n\n";
            $message .= "✅ 已配置 $totalConfigs 个YouTube响应：\n\n";
            
            // 显示群级别配置
            if (!empty($youtubeRooms)) {
                $message .= "🏘️ 群级别配置 (" . count($youtubeRooms) . "个)：\n";
                foreach ($youtubeRooms as $roomWxid) {
                    $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? $roomWxid;
                    $message .= "  ✅ $roomName\n";
                    $message .= "     群ID: $roomWxid\n";
                }
                $message .= "\n";
            }
            
            // 显示用户级别配置
            if (!empty($youtubeUsers)) {
                $message .= "👤 用户级别配置 (" . count($youtubeUsers) . "个)：\n";
                foreach ($youtubeUsers as $userWxid) {
                    $userName = $contacts[$userWxid]['nickname'] ?? $contacts[$userWxid]['remark'] ?? $userWxid;
                    $message .= "  ✅ $userName\n";
                    $message .= "     用户ID: $userWxid\n";
                }
            }
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 处理获取黑名单命令
     */
    private function handleGetBlacklistCommand(XbotMessageContext $context): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        $contacts = $context->wechatBot->getMeta('contacts', []);
        
        $blacklistStats = $configManager->getBlacklistStats();
        $blacklist = $blacklistStats['list'];
        $totalCount = $blacklistStats['total'];
        
        // 构建响应消息
        if ($totalCount === 0) {
            $message = "📋 黑名单配置状态\n\n❌ 黑名单为空\n\n💡 使用方法：\n/set 黑名单 wxid123 - 添加用户到黑名单\n黑名单中的用户发送的消息将被完全忽略";
        } else {
            $message = "📋 黑名单配置状态\n\n";
            $message .= "⚠️ 已拉黑 $totalCount 个用户：\n\n";
            
            foreach ($blacklist as $index => $wxid) {
                $userName = $contacts[$wxid]['nickname'] ?? $contacts[$wxid]['remark'] ?? $wxid;
                $message .= "🚫 " . ($index + 1) . ". $userName\n";
                $message .= "   wxid: $wxid\n\n";
            }
            
            $message .= "💡 移除黑名单：/set 黑名单 -wxid123";
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
    }

    /**
     * 处理黑名单设置命令
     */
    private function handleBlacklistCommand(XbotMessageContext $context, string $wxid): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        $contacts = $context->wechatBot->getMeta('contacts', []);
        
        $wxid = trim($wxid);
        
        // 检查是否为移除操作（以 - 开头）
        if (str_starts_with($wxid, '-')) {
            $targetWxid = substr($wxid, 1);
            if (empty($targetWxid)) {
                $this->sendTextMessage($context, "❌ 请提供要移除的wxid\n例如：/set 黑名单 -wxid123");
                $this->markAsReplied($context);
                return;
            }
            
            $success = $configManager->removeFromBlacklist($targetWxid);
            if ($success) {
                $userName = $contacts[$targetWxid]['nickname'] ?? $contacts[$targetWxid]['remark'] ?? $targetWxid;
                $this->sendTextMessage($context, "✅ 已将 $userName 从黑名单中移除\nwxid: $targetWxid");
            } else {
                $this->sendTextMessage($context, "❌ 该用户不在黑名单中\nwxid: $targetWxid");
            }
            
            $this->markAsReplied($context);
            return;
        }
        
        // 验证wxid格式
        if (empty($wxid)) {
            $this->sendTextMessage($context, "❌ 请提供要拉黑的wxid\n例如：/set 黑名单 wxid123");
            $this->markAsReplied($context);
            return;
        }
        
        // 防止拉黑自己
        if ($wxid === $context->wechatBot->wxid) {
            $this->sendTextMessage($context, "❌ 不能将自己加入黑名单");
            $this->markAsReplied($context);
            return;
        }
        
        // 添加到黑名单
        $success = $configManager->addToBlacklist($wxid);
        if ($success) {
            $userName = $contacts[$wxid]['nickname'] ?? $contacts[$wxid]['remark'] ?? $wxid;
            $this->sendTextMessage($context, "✅ 已将 $userName 加入黑名单\nwxid: $wxid\n\n⚠️ 该用户的所有消息将被忽略，不会触发任何响应");
        } else {
            $userName = $contacts[$wxid]['nickname'] ?? $contacts[$wxid]['remark'] ?? $wxid;
            $this->sendTextMessage($context, "⚠️ $userName 已经在黑名单中\nwxid: $wxid");
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 处理获取群邀请别名命令
     */
    private function handleGetRoomAliasCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $configManager = new ConfigManager($wechatBot);
        $contacts = $wechatBot->getMeta('contacts', []);
        
        $aliasConfigs = [];
        $totalAliases = 0;
        
        // 获取所有房间别名映射
        $aliasMap = $configManager->getAllRoomAliases();
        
        foreach ($aliasMap as $roomWxid => $alias) {
            $roomName = $contacts[$roomWxid]['nickname'] ?? $contacts[$roomWxid]['remark'] ?? $roomWxid;
            $aliasConfigs[] = [
                'wxid' => $roomWxid,
                'name' => $roomName,
                'alias' => $alias
            ];
            $totalAliases++;
        }
        
        // 构建响应消息
        if (empty($aliasConfigs)) {
            $message = "📋 群邀请别名配置状态\n\n❎ 暂无已配置的群邀请别名\n\n💡 使用方法：\n在群聊中发送：/set room_alias 1234\n用户私聊发送：1234 即可收到群邀请";
        } else {
            $message = "📋 群邀请别名配置状态\n\n";
            $message .= "✅ 已配置 {$totalAliases} 个群邀请别名：\n\n";
            
            foreach ($aliasConfigs as $config) {
                $message .= "🏷️ 别名: {$config['alias']}\n";
                $message .= "   群名: {$config['name']}\n";
                $message .= "   群ID: {$config['wxid']}\n\n";
            }
            
            $message .= "💡 用户私聊发送别名即可收到对应群邀请";
        }
        
        $this->sendTextMessage($context, $message);
        $this->markAsReplied($context);
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
            $status = $value ? '✅开启' : '❎关闭';
            $configName = $configManager->getConfigName($command);
            $message .= "• {$command}: {$status} {$configName}\n";
        }

        // 添加群级别配置显示
        $message .= "\n🏘️ 群级别配置：\n";
        $message .= $this->getGroupLevelConfigs($context);

        $message .= "\n💡 其他配置项：\n";
        $chatwootConfigs = array_keys(ConfigManager::CHATWOOT_CONFIGS);
        $message .= "• " . implode("\n• ", $chatwootConfigs);
        
        $message .= "\n";
        $stringConfigs = array_keys(ConfigManager::STRING_CONFIGS);
        $message .= "• " . implode("\n• ", $stringConfigs);

        // 添加配置命令帮助
        $message .= "\n\n🔧 配置管理命令：\n";
        $message .= "/set <key> <value> - 设置配置项\n";
        $message .= "/config <key> <value> - 设置配置项\n";
        $message .= "/get chatwoot - 查看Chatwoot配置详情\n";
        $message .= "/get room_alias - 查看群邀请别名配置\n";
        $message .= "/sync contacts - 同步联系人列表\n";
        $message .= "/check online - 检查微信在线状态\n\n";

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
            $roomListenDisplay = $globalRoomMsg ? "✅继承(开启)" : "❎继承(关闭)";
        } else {
            $roomListenDisplay = $roomListenStatus ? "✅特例开启" : "❎特例关闭";
        }
        $groupConfigs .= "• room_msg: {$roomListenDisplay}\n";

        // 2. 签到系统配置
        $checkInService = new CheckInPermissionService($wechatBot);
        $checkInStatus = $checkInService->getRoomCheckInStatus($roomWxid);
        $globalCheckIn = $configManager->isEnabled('check_in');
        
        if ($checkInStatus === null) {
            $checkInDisplay = $globalCheckIn ? "✅继承(开启)" : "❎继承(关闭)";
        } else {
            $checkInDisplay = $checkInStatus ? "✅特例开启" : "❎特例关闭";
        }
        $groupConfigs .= "• check_in (/set check_in): {$checkInDisplay}\n";

        // 3. 退群监控配置
        $roomQuitStatus = $this->getGroupLevelConfig($wechatBot, $roomWxid, 'room_quit');
        $globalRoomQuit = $configManager->isEnabled('room_quit');
        
        if ($roomQuitStatus === null) {
            $roomQuitDisplay = $globalRoomQuit ? "✅继承(开启)" : "❎继承(关闭)";
        } else {
            $roomQuitDisplay = $roomQuitStatus ? "✅特例开启" : "❎特例关闭";
        }
        $groupConfigs .= "• room_quit (/set room_quit): {$roomQuitDisplay}\n";

        // 4. YouTube 响应配置
        $youtubeRooms = $wechatBot->getMeta('youtube_allowed_rooms', []);
        $youtubeAllowed = isset($youtubeRooms[$roomWxid]) && $youtubeRooms[$roomWxid];
        $youtubeDisplay = $youtubeAllowed ? "✅开启" : "❎关闭";
        $groupConfigs .= "• youtube (/set youtube): {$youtubeDisplay}\n";

        return $groupConfigs;
    }

    /**
     * 获取所有群配置的统计信息
     */
    private function getAllRoomsConfigSummary($wechatBot, $configManager): string
    {
        $groupConfigs = "";

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

        // 3. 退群监控配置
        $roomQuitConfigs = $this->getRoomQuitConfigs($wechatBot);
        $roomQuitCount = count($roomQuitConfigs);
        if ($roomQuitCount > 0) {
            $groupConfigs .= "• room_quit: {$roomQuitCount}个群特例配置\n";
        } else {
            $groupConfigs .= "• room_quit: 无特例配置\n";
        }

        // 4. YouTube 响应配置
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
     * 获取退群监控的群级别配置
     */
    private function getRoomQuitConfigs($wechatBot): array
    {
        // room_quit 配置存储在 room_quit_specials 中
        return $wechatBot->getMeta('room_quit_specials', []);
    }

    /**
     * 处理字符串配置命令
     */
    private function handleStringConfigCommand(XbotMessageContext $context, string $key, string $value): void
    {
        $configManager = new ConfigManager($context->wechatBot);
        
        // 验证值不为空
        if (empty(trim($value))) {
            $configName = $configManager->getConfigName($key);
            $this->sendTextMessage($context, "❎ {$configName} 的值不能为空");
            $this->markAsReplied($context);
            return;
        }

        // 特殊处理数字类型配置（friend_daily_limit）
        if ($key === 'friend_daily_limit') {
            if (!is_numeric($value) || (int)$value <= 0) {
                $this->sendTextMessage($context, "❎ 每日好友请求处理上限必须是大于0的数字");
                $this->markAsReplied($context);
                return;
            }
            
            $configManager->setStringConfig($key, (int)$value);
            $this->sendTextMessage($context, "✅ 配置更新成功\n每日处理上限: {$value}");
            $this->markAsReplied($context);
            return;
        }

        // 处理欢迎消息模板（根据发送场景自动选择存储位置）
        if ($key === 'welcome_msg') {
            $this->handleWelcomeMessageConfig($context, $key, $value);
            return;
        }

        // 其他未知的字符串配置项
        $this->sendTextMessage($context, "❎ 未知的字符串配置项: {$key}");
        $this->markAsReplied($context);
    }

    /**
     * 处理群级配置命令
     */
    private function handleGroupConfigCommand(XbotMessageContext $context, string $key, string $value): void
    {
        // 群级配置只能在群聊中设置
        if (!$context->isRoom) {
            $this->sendTextMessage($context, "❎ 群级配置只能在群聊中设置");
            $this->markAsReplied($context);
            return;
        }

        $configManager = new ConfigManager($context->wechatBot);
        $roomWxid = $context->roomWxid;
        
        // 验证值不为空
        if (empty(trim($value))) {
            $configName = $configManager->getConfigName($key);
            $this->sendTextMessage($context, "❎ {$configName} 的值不能为空");
            $this->markAsReplied($context);
            return;
        }

        // 其他未知的群级配置项
        $this->sendTextMessage($context, "❎ 未知的群级配置项: {$key}");
        $this->markAsReplied($context);
    }

    /**
     * 获取群级别配置项的值
     * 
     * @param WechatBot $wechatBot
     * @param string $roomWxid  
     * @param string $configKey 配置键名
     * @return bool|null null表示没有群级别配置，使用全局配置
     */
    private function getGroupLevelConfig($wechatBot, string $roomWxid, string $configKey): ?bool
    {
        switch ($configKey) {
            case 'room_msg':
                $filter = new ChatroomMessageFilter($wechatBot, new ConfigManager($wechatBot));
                return $filter->getRoomListenStatus($roomWxid);
                
            case 'check_in':
                $service = new CheckInPermissionService($wechatBot);
                return $service->getRoomCheckInStatus($roomWxid);
                
            case 'room_quit':
                // room_quit 配置存储在 room_quit_specials metadata 中
                $quitConfigs = $wechatBot->getMeta('room_quit_specials', []);
                return $quitConfigs[$roomWxid] ?? null;
                
            default:
                return null;
        }
    }

    /**
     * 检查是否为配置命令
     */
    private function isConfigCommand(string $message): bool
    {
        $normalizedMessage = strtolower(trim($message));
        
        // 解析命令参数
        $parts = array_values(array_filter(preg_split('/\s+/', trim($message)), 'strlen'));
        
        // 检查 /set 命令（必须有key和value）
        if (Str::startsWith($normalizedMessage, '/set ') && count($parts) >= 3) {
            return true;
        }
        
        // 检查 /config 命令
        if (Str::startsWith($normalizedMessage, '/config')) {
            return true;
        }
        
        // 检查其他配置相关命令
        if (in_array($normalizedMessage, ['/get chatwoot', '/get room_alias', '/sync contacts', '/check online'])) {
            return true;
        }
        
        return false;
    }

    /**
     * 解析命令参数，支持引号内的空格
     */
    private function parseCommandArguments(string $message): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;
        $escaped = false;
        
        $chars = mb_str_split(trim($message));
        
        for ($i = 0; $i < count($chars); $i++) {
            $char = $chars[$i];
            
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }
            
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            
            if (!$inQuotes && ($char === '"' || $char === "'")) {
                $inQuotes = true;
                $quoteChar = $char;
                continue;
            }
            
            if ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = null;
                continue;
            }
            
            if (!$inQuotes && preg_match('/\s/', $char)) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }
                continue;
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $parts[] = $current;
        }
        
        return $parts;
    }

}
