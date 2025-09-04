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
        'keyword_sync' => '关键词同步',
        'chatroom_listen' => '群监听',
    ];

    private WechatBot $wechatBot;
    private array $cache = [];

    public function __construct(WechatBot $wechatBot)
    {
        $this->wechatBot = $wechatBot;
    }

    /**
     * 获取配置key（约定：{command}_enabled，特殊情况除外）
     */
    private function getConfigKey(string $command): string
    {
        return match($command) {
            'chatroom_listen' => 'chatroom_listen_enabled',
            default => "{$command}_enabled"
        };
    }

    /**
     * 获取配置值
     */
    public function get(string $command, string $roomWxid = null): mixed
    {
        if (!isset(self::CONFIGS[$command])) {
            return false; // 友好处理，返回默认值
        }

        $cacheKey = $this->getCacheKey($command, $roomWxid);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $configKey = $this->getConfigKey($command);

        // 只有 chatroom_listen 支持群级配置，其他都是全局配置
        $value = ($command === 'chatroom_listen' && $roomWxid) 
            ? $this->getRoomConfigValue($roomWxid, $configKey)
            : $this->wechatBot->getMeta($configKey, false);

        $this->cache[$cacheKey] = $value;
        return $value;
    }

    /**
     * 设置配置值
     */
    public function set(string $command, $value, string $roomWxid = null): bool
    {
        if (!isset(self::CONFIGS[$command])) {
            throw new InvalidArgumentException("Unknown command: {$command}");
        }

        $configKey = $this->getConfigKey($command);
        $castedValue = (bool)$value; // 所有配置都是boolean

        // 只有 chatroom_listen 支持群级配置，其他都是全局配置
        $success = ($command === 'chatroom_listen' && $roomWxid)
            ? $this->setRoomConfigValue($roomWxid, $configKey, $castedValue)
            : $this->setGlobalConfigValue($configKey, $castedValue);

        if ($success) {
            // 清除缓存
            $this->clearCache($command, $roomWxid);
        }

        return $success;
    }

    /**
     * 检查配置是否启用
     */
    public function isEnabled(string $command, string $roomWxid = null): bool
    {
        return (bool)$this->get($command, $roomWxid);
    }

    /**
     * 获取所有配置状态
     */
    public function getAll(string $roomWxid = null): array
    {
        $configs = [];
        
        foreach (self::CONFIGS as $command => $configName) {
            if ($roomWxid && $command === 'chatroom_listen') {
                $configs[$command] = $this->get($command, $roomWxid);
            } elseif (!$roomWxid && $command !== 'chatroom_listen') {
                $configs[$command] = $this->get($command);
            }
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
     * 获取群配置值
     */
    private function getRoomConfigValue(string $roomWxid, string $configKey): bool
    {
        $contacts = $this->wechatBot->getMeta('contacts', []);
        return $contacts[$roomWxid][$configKey] ?? false;
    }

    /**
     * 设置群配置值
     */
    private function setRoomConfigValue(string $roomWxid, string $configKey, bool $value): bool
    {
        $contacts = $this->wechatBot->getMeta('contacts', []);
        
        if (!isset($contacts[$roomWxid])) {
            $contacts[$roomWxid] = [];
        }
        
        $contacts[$roomWxid][$configKey] = $value;
        $this->wechatBot->setMeta('contacts', $contacts);
        
        return true;
    }

    /**
     * 生成缓存键
     */
    private function getCacheKey(string $command, string $roomWxid = null): string
    {
        return $roomWxid ? "{$command}:{$roomWxid}" : $command;
    }

    /**
     * 清除缓存
     */
    private function clearCache(string $command, string $roomWxid = null): void
    {
        $cacheKey = $this->getCacheKey($command, $roomWxid);
        unset($this->cache[$cacheKey]);
    }
}