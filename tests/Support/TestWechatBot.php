<?php

namespace Tests\Support;

use App\Models\WechatBot;

class TestWechatBot extends WechatBot
{
    private array $metaStorage = [];
    private $testWechatClient;
    
    public function __construct(array $attributes = [])
    {
        // 不调用父类构造函数，避免数据库连接
        $this->attributes = $attributes;
        $this->exists = true;
        
        // 创建模拟的wechatClient
        $this->testWechatClient = (object) [
            'token' => 'test-token',
            'endpoint' => 'http://localhost:8001',
            'file_url' => 'http://localhost:8004',
            'file_path' => 'C:\\test\\',
            'voice_url' => 'http://localhost:8003',
            'silk_path' => '/tmp/test'
        ];
    }
    
    /**
     * 模拟 Metable trait 的 setMeta 方法
     */
    public function setMeta(string $key, mixed $value, bool $encrypt = false): void
    {
        $this->metaStorage[$key] = $value;
    }
    
    /**
     * 模拟 Metable trait 的 getMeta 方法
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->metaStorage[$key] ?? $default;
    }
    
    /**
     * 模拟 Metable trait 的 hasMeta 方法
     */
    public function hasMeta(string $key): bool
    {
        return isset($this->metaStorage[$key]);
    }
    
    /**
     * 重写 save 方法，避免数据库操作
     */
    public function save(array $options = []): bool
    {
        return true;
    }
    
    /**
     * 重写 refresh 方法
     */
    public function refresh(): self
    {
        return $this;
    }
    
    /**
     * 重写 update 方法
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        $this->fill($attributes);
        return true;
    }
    
    /**
     * 获取属性值
     */
    public function __get($key)
    {
        if ($key === 'wechatClient') {
            return $this->testWechatClient;
        }
        return $this->attributes[$key] ?? null;
    }
    
    /**
     * 设置属性值
     */
    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }
    
    /**
     * 检查属性是否存在
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }
    
    /**
     * 模拟 xbot 方法，返回一个测试用的Xbot实例
     */
    public function xbot($clientId = 99)
    {
        return new TestXbot();
    }
}