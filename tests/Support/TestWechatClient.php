<?php

namespace Tests\Support;

use App\Models\WechatClient;

class TestWechatClient extends WechatClient
{
    public function __construct(array $attributes = [])
    {
        // 不调用父类构造函数，避免数据库连接
        $this->attributes = $attributes;
        $this->exists = true;
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
     * 获取属性值
     */
    public function __get($key)
    {
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
}