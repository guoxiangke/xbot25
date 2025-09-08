<?php

namespace App\Services;

use App\Models\WechatBot;
use InvalidArgumentException;

/**
 * Xbot配置管理器
 * 统一管理所有Xbot相关的配置参数
 */
class XbotConfigManager
{
    /**
     * 统一配置定义
     */
    const CONFIGS = [
        'chatwoot' => 'Chatwoot同步',
        'room_msg' => '群消息处理',
        'keyword_resources' => '关键词资源响应',
        'keyword_sync' => 'Chatwoot同步关键词',
        'payment_auto' => '自动收款',
        'check_in' => '签到系统',
    ];

    /**
     * 配置默认值定义
     * 未在此列表中的配置默认为 false
     */
    const DEFAULT_VALUES = [
        'payment_auto' => true, // 自动收款默认开启
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
        return self::CONFIGS[$command] ?? $command;
    }

    /**
     * 获取所有可用命令列表
     */
    public static function getAvailableCommands(): array
    {
        return array_keys(self::CONFIGS);
    }

    /**
     * 检查命令是否有效
     */
    public function isValidCommand(string $command): bool
    {
        return isset(self::CONFIGS[$command]);
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
