<?php

namespace App\Services\Managers;

use App\Models\WechatBot;
use InvalidArgumentException;

/**
 * 配置管理器
 * 统一管理所有Xbot相关的配置参数
 */
class ConfigManager
{
    /**
     * 统一配置定义
     */
    const CONFIGS = [
        'chatwoot' => 'Chatwoot同步',
        'room_msg' => '群消息处理',
        'keyword_resources' => '关键词资源响应',
        'payment_auto' => '自动收款',
        'check_in' => '签到系统',
        'friend_auto_accept' => '自动同意好友请求',
        'friend_welcome' => '新好友欢迎消息',
        'room_quit' => '退群监控',
    ];

    /**
     * Chatwoot 专用配置项（非布尔值）
     */
    const CHATWOOT_CONFIGS = [
        'chatwoot_account_id' => 'Chatwoot账户ID',
        'chatwoot_inbox_id' => 'Chatwoot收件箱ID',
        'chatwoot_token' => 'ChatwootAPI令牌',
        'chatwoot_endpoint_url' => 'Chatwoot服务器地址',
    ];

    /**
     * 字符串类型配置项（非布尔值）
     */
    const STRING_CONFIGS = [
        'friend_daily_limit' => '每日好友请求处理上限',
        'welcome_msg' => '好友欢迎消息模板',
        'blacklist' => '用户黑名单',
    ];

    /**
     * 群级别配置项（非布尔值）
     */
    const GROUP_CONFIGS = [
        'room_alias' => '群邀请别名',
        'room_welcome_msgs' => '群新成员欢迎消息模板',
        'room_timezone_special' => '群时区设置',
    ];

    /**
     * 配置默认值定义
     * 未在此列表中的配置默认为 false
     */
    const DEFAULT_VALUES = [
        'payment_auto' => true, // 自动收款默认开启
        'friend_auto_accept' => false, // 自动同意好友请求默认关闭
    ];

    /**
     * 字符串配置默认值
     */
    const STRING_DEFAULT_VALUES = [
        'friend_daily_limit' => 50,
        'welcome_msg' => '@nickname 你好，欢迎你！',
    ];

    /**
     * 群级别配置默认值
     */
    const GROUP_DEFAULT_VALUES = [
        'room_alias' => null,
    ];

    private WechatBot $wechatBot;
    private array $cache = [];

    public function __construct(WechatBot $wechatBot)
    {
        $this->wechatBot = $wechatBot;
    }

    /**
     * 获取配置key（约定：{command}_enabled）
     */
    private function getConfigKey(string $command): string
    {
        return "{$command}_enabled";
    }

    /**
     * 获取配置值
     */
    public function get(string $command, ?string $roomWxid = null): mixed
    {
        if (!isset(self::CONFIGS[$command])) {
            return false; // 友好处理，返回默认值
        }

        $cacheKey = $this->getCacheKey($command, $roomWxid);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $configKey = $this->getConfigKey($command);
        $defaultValue = self::DEFAULT_VALUES[$command] ?? false;
        $value = $this->wechatBot->getMeta($configKey, $defaultValue);

        $this->cache[$cacheKey] = $value;
        return $value;
    }

    /**
     * 设置配置值
     */
    public function set(string $command, $value, ?string $roomWxid = null): bool
    {
        if (!isset(self::CONFIGS[$command])) {
            throw new InvalidArgumentException("Unknown command: {$command}");
        }

        $configKey = $this->getConfigKey($command);
        $castedValue = (bool)$value; // 所有配置都是boolean
        $success = $this->setGlobalConfigValue($configKey, $castedValue);

        if ($success) {
            // 清除缓存
            $this->clearCache($command, $roomWxid);
        }

        return $success;
    }

    /**
     * 检查配置是否启用
     */
    public function isEnabled(string $command, ?string $roomWxid = null): bool
    {
        return (bool)$this->get($command, $roomWxid);
    }

    /**
     * 获取所有配置状态
     */
    public function getAll(): array
    {
        $configs = [];

        foreach (self::CONFIGS as $command => $configName) {
            $configs[$command] = $this->get($command);
        }

        return $configs;
    }

    /**
     * 获取配置显示名称
     */
    public function getConfigName(string $command): string
    {
        return self::CONFIGS[$command] ?? self::CHATWOOT_CONFIGS[$command] ?? self::STRING_CONFIGS[$command] ?? self::GROUP_CONFIGS[$command] ?? $command;
    }

    /**
     * 获取所有可用命令列表
     */
    public static function getAvailableCommands(): array
    {
        return array_unique(array_merge(array_keys(self::CONFIGS), array_keys(self::CHATWOOT_CONFIGS), array_keys(self::STRING_CONFIGS), array_keys(self::GROUP_CONFIGS)));
    }

    /**
     * 获取主要配置项列表（用于显示帮助信息）
     */
    public static function getMainConfigs(): array
    {
        return array_keys(self::CONFIGS);
    }

    /**
     * 检查命令是否有效
     */
    public function isValidCommand(string $command): bool
    {
        return isset(self::CONFIGS[$command]) || isset(self::CHATWOOT_CONFIGS[$command]) || isset(self::STRING_CONFIGS[$command]) || isset(self::GROUP_CONFIGS[$command]);
    }

    /**
     * 检查是否为 Chatwoot 配置项
     */
    public function isChatwootConfig(string $command): bool
    {
        return isset(self::CHATWOOT_CONFIGS[$command]);
    }

    /**
     * 获取 Chatwoot 配置值
     */
    public function getChatwootConfig(string $command, $default = null)
    {
        if (!$this->isChatwootConfig($command)) {
            return $default;
        }

        $chatwootMeta = $this->wechatBot->getMeta('chatwoot', []);
        
        // 为 chatwoot_endpoint_url 提供默认值
        if ($command === 'chatwoot_endpoint_url' && empty($chatwootMeta[$command])) {
            return 'https://app.chatwoot.com';
        }
        
        return $chatwootMeta[$command] ?? $default;
    }

    /**
     * 设置 Chatwoot 配置值
     */
    public function setChatwootConfig(string $command, $value): bool
    {
        if (!$this->isChatwootConfig($command)) {
            return false;
        }

        $chatwootMeta = $this->wechatBot->getMeta('chatwoot', []);
        $chatwootMeta[$command] = $value;
        $this->wechatBot->setMeta('chatwoot', $chatwootMeta);
        
        return true;
    }

    /**
     * 获取所有 Chatwoot 配置
     */
    public function getAllChatwootConfigs(): array
    {
        $chatwootMeta = $this->wechatBot->getMeta('chatwoot', []);
        $configs = [];
        
        foreach (self::CHATWOOT_CONFIGS as $command => $description) {
            $configs[$command] = $chatwootMeta[$command] ?? null;
        }
        
        return $configs;
    }

    /**
     * 检查 Chatwoot 配置是否完整
     */
    public function isChatwootConfigComplete(): array
    {
        $chatwootMeta = $this->wechatBot->getMeta('chatwoot', []);
        $missingConfigs = [];

        foreach (array_keys(self::CHATWOOT_CONFIGS) as $configKey) {
            if (empty($chatwootMeta[$configKey])) {
                $missingConfigs[] = $configKey;
            }
        }

        return $missingConfigs;
    }

    /**
     * 检查是否为字符串配置项
     */
    public function isStringConfig(string $command): bool
    {
        return isset(self::STRING_CONFIGS[$command]);
    }

    /**
     * 获取字符串配置值
     */
    public function getStringConfig(string $command, $default = null)
    {
        if (!$this->isStringConfig($command)) {
            return $default;
        }

        return $this->wechatBot->getMeta($command, self::STRING_DEFAULT_VALUES[$command] ?? $default);
    }

    /**
     * 设置字符串配置值
     */
    public function setStringConfig(string $command, $value): bool
    {
        if (!$this->isStringConfig($command)) {
            return false;
        }

        $this->wechatBot->setMeta($command, $value);
        
        return true;
    }

    /**
     * 获取所有字符串配置
     */
    public function getAllStringConfigs(): array
    {
        $configs = [];
        
        foreach (self::STRING_CONFIGS as $command => $description) {
            $configs[$command] = $this->wechatBot->getMeta($command, self::STRING_DEFAULT_VALUES[$command] ?? null);
        }
        
        return $configs;
    }

    /**
     * setConfig 方法的别名，用于向后兼容
     */
    public function setConfig(string $command, $value, ?string $roomWxid = null): bool
    {
        return $this->set($command, $value, $roomWxid);
    }

    // ========= 内部辅助方法 =========

    /**
     * 设置全局配置值
     */
    private function setGlobalConfigValue(string $configKey, bool $value): bool
    {
        $this->wechatBot->setMeta($configKey, $value);
        return true;
    }


    /**
     * 生成缓存键
     */
    private function getCacheKey(string $command, ?string $roomWxid = null): string
    {
        return $roomWxid ? "{$command}:{$roomWxid}" : $command;
    }

    /**
     * 清除缓存
     */
    private function clearCache(string $command, ?string $roomWxid = null): void
    {
        $cacheKey = $this->getCacheKey($command, $roomWxid);
        unset($this->cache[$cacheKey]);
    }

    /**
     * 检查是否为群级配置项
     */
    public function isGroupConfig(string $command): bool
    {
        return isset(self::GROUP_CONFIGS[$command]);
    }

    /**
     * 获取群级配置值
     */
    public function getGroupConfig(string $command, ?string $roomWxid = null, $default = null)
    {
        if (!$this->isGroupConfig($command)) {
            return $default;
        }

        if (!$roomWxid) {
            return self::GROUP_DEFAULT_VALUES[$command] ?? $default;
        }

        // room_alias 使用集中化存储
        if ($command === 'room_alias') {
            $aliasMap = $this->wechatBot->getMeta('room_alias', []);
            return $aliasMap[$roomWxid] ?? $default;
        }

        // room_welcome_msgs 使用集中化存储（数组格式）
        if ($command === 'room_welcome_msgs') {
            if ($roomWxid) {
                // 获取特定群的欢迎消息
                $welcomeMsgs = $this->wechatBot->getMeta('room_welcome_msgs', []);
                return $welcomeMsgs[$roomWxid] ?? $default;
            } else {
                // 获取所有群的欢迎消息数组
                return $this->wechatBot->getMeta('room_welcome_msgs', $default);
            }
        }

        // room_timezone_special 使用集中化存储
        if ($command === 'room_timezone_special') {
            $timezoneMap = $this->wechatBot->getMeta('room_timezone_specials', []);
            return $timezoneMap[$roomWxid] ?? $default;
        }

        // 其他群配置使用原有存储方式
        $groupMeta = $this->wechatBot->getMeta("group.{$roomWxid}", []);
        return $groupMeta[$command] ?? (self::GROUP_DEFAULT_VALUES[$command] ?? $default);
    }

    /**
     * 设置群级配置值
     */
    public function setGroupConfig(string $command, $value, string $roomWxid): bool
    {
        if (!$this->isGroupConfig($command)) {
            return false;
        }

        // room_alias 使用集中化存储
        if ($command === 'room_alias') {
            $aliasMap = $this->wechatBot->getMeta('room_alias', []);
            
            if (empty($value)) {
                // 删除别名
                unset($aliasMap[$roomWxid]);
            } else {
                // 设置别名
                $aliasMap[$roomWxid] = $value;
            }
            
            $this->wechatBot->setMeta('room_alias', $aliasMap);
            return true;
        }

        // room_welcome_msgs 使用集中化存储（数组格式）
        if ($command === 'room_welcome_msgs') {
            // 直接设置整个数组（value 应该是完整的数组）
            $this->wechatBot->setMeta('room_welcome_msgs', $value);
            return true;
        }

        // room_timezone_special 使用集中化存储
        if ($command === 'room_timezone_special') {
            $timezoneMap = $this->wechatBot->getMeta('room_timezone_specials', []);
            
            if ($value === null) {
                // 删除时区配置
                unset($timezoneMap[$roomWxid]);
            } else {
                // 设置时区配置
                $timezoneMap[$roomWxid] = $value;
            }
            
            $this->wechatBot->setMeta('room_timezone_specials', $timezoneMap);
            return true;
        }

        // 其他群配置使用原有存储方式
        $groupMeta = $this->wechatBot->getMeta("group.{$roomWxid}", []);
        $groupMeta[$command] = $value;
        $this->wechatBot->setMeta("group.{$roomWxid}", $groupMeta);
        
        return true;
    }

    /**
     * 获取所有群级配置
     */
    public function getAllGroupConfigs(?string $roomWxid = null): array
    {
        if (!$roomWxid) {
            return self::GROUP_DEFAULT_VALUES;
        }

        $configs = [];
        
        foreach (self::GROUP_CONFIGS as $command => $description) {
            // 使用 getGroupConfig 方法，它会自动处理不同的存储格式
            $configs[$command] = $this->getGroupConfig($command, $roomWxid, self::GROUP_DEFAULT_VALUES[$command] ?? null);
        }
        
        return $configs;
    }

    /**
     * 获取所有房间别名映射
     */
    public function getAllRoomAliases(): array
    {
        return $this->wechatBot->getMeta('room_alias', []);
    }

    /**
     * 检查别名是否已被使用
     */
    public function isAliasUsed(string $alias, ?string $excludeRoomWxid = null): bool
    {
        $aliasMap = $this->getAllRoomAliases();
        
        foreach ($aliasMap as $roomWxid => $roomAlias) {
            if ($roomAlias === $alias && $roomWxid !== $excludeRoomWxid) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 通过别名查找房间
     */
    public function findRoomByAlias(string $alias): ?string
    {
        $aliasMap = $this->getAllRoomAliases();
        
        foreach ($aliasMap as $roomWxid => $roomAlias) {
            if ($roomAlias === $alias) {
                return $roomWxid;
            }
        }
        
        return null;
    }

    /**
     * 检查好友欢迎消息是否设置（仅检查消息模板是否存在）
     */
    public function hasWelcomeMessage(): bool
    {
        $welcomeMsg = $this->getStringConfig('welcome_msg');
        return !empty(trim($welcomeMsg));
    }

    /**
     * 检查欢迎消息功能是否启用
     */
    public function isWelcomeMessageEnabled(): bool
    {
        return $this->isEnabled('friend_welcome');
    }

    /**
     * 获取黑名单列表
     */
    public function getBlacklist(): array
    {
        $blacklistData = $this->wechatBot->getMeta('blacklist', []);
        
        // 确保返回数组格式
        if (is_array($blacklistData)) {
            return $blacklistData;
        }
        
        return [];
    }

    /**
     * 添加用户到黑名单
     */
    public function addToBlacklist(string $wxid): bool
    {
        if (empty(trim($wxid))) {
            return false;
        }

        $blacklist = $this->getBlacklist();
        
        // 避免重复添加
        if (in_array($wxid, $blacklist)) {
            return false; // 已存在
        }
        
        $blacklist[] = $wxid;
        $this->wechatBot->setMeta('blacklist', $blacklist);
        
        return true;
    }

    /**
     * 从黑名单移除用户
     */
    public function removeFromBlacklist(string $wxid): bool
    {
        $blacklist = $this->getBlacklist();
        
        $index = array_search($wxid, $blacklist);
        if ($index === false) {
            return false; // 不在黑名单中
        }
        
        unset($blacklist[$index]);
        // 重新索引数组
        $blacklist = array_values($blacklist);
        
        $this->wechatBot->setMeta('blacklist', $blacklist);
        
        return true;
    }

    /**
     * 检查用户是否在黑名单中
     */
    public function isInBlacklist(string $wxid): bool
    {
        if (empty(trim($wxid))) {
            return false;
        }
        
        $blacklist = $this->getBlacklist();
        return in_array($wxid, $blacklist);
    }

    /**
     * 清空黑名单
     */
    public function clearBlacklist(): bool
    {
        $this->wechatBot->setMeta('blacklist', []);
        return true;
    }

    /**
     * 获取黑名单统计信息
     */
    public function getBlacklistStats(): array
    {
        $blacklist = $this->getBlacklist();
        
        return [
            'total' => count($blacklist),
            'list' => $blacklist
        ];
    }
}