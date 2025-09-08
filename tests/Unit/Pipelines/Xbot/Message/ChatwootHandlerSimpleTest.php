<?php

use App\Pipelines\Xbot\Message\ChatwootHandler;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('ChatwootHandler Simple Unit Tests', function () {
    
    beforeEach(function () {
        // 使用真实的数据库数据而不是复杂的Mock
        $this->wechatClient = WechatClient::factory()->create();
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $this->wechatClient->id,
            'chatwoot_account_id' => '123',
            'chatwoot_inbox_id' => '456',
            'chatwoot_token' => 'test_token'
        ]);
        
        $this->handler = new ChatwootHandler();
    });
    
    test('should detect chatwoot configuration correctly', function () {
        // 测试有完整Chatwoot配置的机器人
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('hasChatwootConfig');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->handler, $this->wechatBot);
        expect($result)->toBeTrue();
        
        // 测试缺少配置的机器人
        $incompleteBot = WechatBot::factory()->create([
            'wechat_client_id' => $this->wechatClient->id,
            'chatwoot_account_id' => null,
            'chatwoot_inbox_id' => '456',
            'chatwoot_token' => 'test_token'
        ]);
        
        $result2 = $method->invoke($this->handler, $incompleteBot);
        expect($result2)->toBeFalse();
    });
    
    test('should identify bot as having necessary dependencies', function () {
        // 简单的存在性测试，确保类可以正确实例化
        expect($this->handler)->toBeInstanceOf(ChatwootHandler::class);
        expect($this->wechatBot->chatwoot_account_id)->toBe('123');
        expect($this->wechatBot->chatwoot_inbox_id)->toBe('456');
        expect($this->wechatBot->chatwoot_token)->toBe('test_token');
    });
});