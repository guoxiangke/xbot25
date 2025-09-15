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
        'friend_welcome_enabled' => '新好友欢迎消息',
    ];

    /**
     * Chatwoot 专用配置项（非布尔值）
     */
    const CHATWOOT_CONFIGS = [
        'chatwoot_account_id' => 'Chatwoot账户ID',
        'chatwoot_inbox_id' => 'Chatwoot收件箱ID',
        'chatwoot_token' => 'ChatwootAPI令牌',
    ];

    /**
     * 好友请求相关配置项（非布尔值）
     */
    const FRIEND_CONFIGS = [
        'friend_daily_limit' => '每日好友请求处理上限',
        'welcome_msg' => '好友欢迎消息模板',
        'room_welcome_msg' => '群聊欢迎消息模板',
    ];

    /**
     * 配置默认值定义
     * 未在此列表中的配置默认为 false
     */
    const DEFAULT_VALUES = [
        'payment_auto' => true, // 自动收款默认开启
        'friend_auto_accept' => false, // 自动同意好友请求默认关闭
        'friend_welcome_enabled' => false, // 新好友欢迎消息默认关闭
    ];

    /**
     * 好友配置默认值
     */
    const FRIEND_DEFAULT_VALUES = [
        'friend_daily_limit' => 50,
        'welcome_msg' => '@nickname 你好，欢迎你！',
        'room_welcome_msg' => '@nickname 欢迎入群',
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
        return self::CONFIGS[$command] ?? self::CHATWOOT_CONFIGS[$command] ?? self::FRIEND_CONFIGS[$command] ?? $command;
    }

    /**
     * 获取所有可用命令列表
     */
    public static function getAvailableCommands(): array
    {
        return array_merge(array_keys(self::CONFIGS), array_keys(self::CHATWOOT_CONFIGS), array_keys(self::FRIEND_CONFIGS));
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
        return isset(self::CONFIGS[$command]) || isset(self::CHATWOOT_CONFIGS[$command]) || isset(self::FRIEND_CONFIGS[$command]);
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
     * 检查是否为好友配置项
     */
    public function isFriendConfig(string $command): bool
    {
        return isset(self::FRIEND_CONFIGS[$command]);
    }

    /**
     * 获取好友配置值
     */
    public function getFriendConfig(string $command, $default = null)
    {
        if (!$this->isFriendConfig($command)) {
            return $default;
        }

        $friendMeta = $this->wechatBot->getMeta('friend', []);
        return $friendMeta[$command] ?? (self::FRIEND_DEFAULT_VALUES[$command] ?? $default);
    }

    /**
     * 设置好友配置值
     */
    public function setFriendConfig(string $command, $value): bool
    {
        if (!$this->isFriendConfig($command)) {
            return false;
        }

        $friendMeta = $this->wechatBot->getMeta('friend', []);
        $friendMeta[$command] = $value;
        $this->wechatBot->setMeta('friend', $friendMeta);
        
        return true;
    }

    /**
     * 获取所有好友配置
     */
    public function getAllFriendConfigs(): array
    {
        $friendMeta = $this->wechatBot->getMeta('friend', []);
        $configs = [];
        
        foreach (self::FRIEND_CONFIGS as $command => $description) {
            $configs[$command] = $friendMeta[$command] ?? (self::FRIEND_DEFAULT_VALUES[$command] ?? null);
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
}