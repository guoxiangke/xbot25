<?php

use App\Pipelines\Xbot\Message\ChatwootHandler;
use Tests\Support\XbotTestHelpers;
use Illuminate\Support\Facades\Queue;

describe('ChatwootHandler Simple Unit Tests', function () {
    
    beforeEach(function () {
        // 使用TestWechatBot避免数据库操作
        $this->wechatBot = XbotTestHelpers::createWechatBotWithChatwoot([
            'chatwoot_account_id' => 123,
            'chatwoot_inbox_id' => 456,
            'chatwoot_token' => 'test_token'
        ]);
        
        $this->handler = new ChatwootHandler();
        $this->next = XbotTestHelpers::createPipelineNext();
        
        // Mock队列，避免实际分发任务
        Queue::fake();
    });
    
    test('should process text messages and dispatch to queue', function () {
        // 测试文本消息处理
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            [
                'msg' => '这是一个测试消息',
                'from_wxid' => 'wxid_user123',
                'to_wxid' => $this->wechatBot->wxid,
            ]
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        // 验证处理结果
        expect($result)->toBe($context);
        
        // 验证队列任务被分发
        Queue::assertPushed(\App\Jobs\ChatwootHandleQueue::class);
    });
    
    test('should handle empty messages gracefully', function () {
        // 测试空消息处理
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            [
                'msg' => '',
                'from_wxid' => 'wxid_user123',
                'to_wxid' => $this->wechatBot->wxid,
            ]
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        // 验证处理结果
        expect($result)->toBe($context);
        
        // 空消息不应该分发到队列
        Queue::assertNothingPushed();
    });
    
    test('should instantiate correctly', function () {
        // 简单的存在性测试，确保类可以正确实例化
        expect($this->handler)->toBeInstanceOf(ChatwootHandler::class);
        
        // 验证Chatwoot配置存储在meta中
        $chatwootConfig = $this->wechatBot->getMeta('chatwoot');
        expect($chatwootConfig['chatwoot_account_id'])->toBe(123);
        expect($chatwootConfig['chatwoot_inbox_id'])->toBe(456);
        expect($chatwootConfig['chatwoot_token'])->toBe('test_token');
    });
});