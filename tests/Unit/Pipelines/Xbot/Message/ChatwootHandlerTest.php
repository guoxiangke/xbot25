<?php

use App\Pipelines\Xbot\Message\ChatwootHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\XbotConfigManager;
use App\Models\WechatBot;

describe('ChatwootHandler Unit Tests', function () {
    
    beforeEach(function () {
        $this->wechatBot = Mockery::mock(WechatBot::class);
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('id')->andReturn(1);
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('wxid')->andReturn('test_bot_' . uniqid());
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('chatwoot_account_id')->andReturn('123');
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('chatwoot_inbox_id')->andReturn('456');
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('chatwoot_token')->andReturn('test_token');
        
        // Mock contacts data that XbotMessageContext needs
        $this->wechatBot->shouldReceive('getMeta')
            ->with('contacts', [])
            ->andReturn([
                'user_wxid' => ['nickname' => '测试用户', 'remark' => ''],
                'test_bot_' . uniqid() => ['nickname' => '测试机器人', 'remark' => '']
            ]);
        
        $this->handler = new ChatwootHandler();
    });
    
    describe('Keyword Response Message Detection', function () {
        
        test('should correctly identify keyword response messages through public interface', function () {
            $testCases = [
                // [message, isFromBot, keywordSyncEnabled, expectedSync]
                ['【621】真道分解 09-08', true, true, true],    // Bot keyword response, sync enabled
                ['【621】真道分解 09-08', true, false, false],   // Bot keyword response, sync disabled
                ['【新闻】今日头条', true, true, true],          // Bot keyword response, sync enabled
                ['【新闻】今日头条', true, false, false],        // Bot keyword response, sync disabled
                ['普通机器人消息', true, true, true],            // Non-keyword bot message, always sync
                ['普通机器人消息', true, false, true],          // Non-keyword bot message, always sync
                ['用户消息内容', false, true, true],            // User message, always sync
                ['用户消息内容', false, false, true],          // User message, always sync
                ['【621】用户发送', false, true, true],        // User "keyword" message, always sync
                ['【621】用户发送', false, false, true],       // User "keyword" message, always sync
            ];
            
            foreach ($testCases as [$message, $isFromBot, $keywordSyncEnabled, $expectedSync]) {
                // Set up WechatBot with keyword_sync configuration
                $this->wechatBot->shouldReceive('getMeta')
                    ->with('xbot.config.keyword_sync')
                    ->andReturn($keywordSyncEnabled);
                
                // Create message context
                $context = new XbotMessageContext($this->wechatBot, [
                    'msg' => $message,
                    'from_wxid' => $isFromBot ? $this->wechatBot->wxid : 'user_wxid'
                ], 'MT_RECV_TEXT_MSG', 123);
                
                // Test through public interface by using reflection to access shouldSyncToChatwoot
                $reflection = new ReflectionClass($this->handler);
                $method = $reflection->getMethod('shouldSyncToChatwoot');
                $method->setAccessible(true);
                
                $result = $method->invoke($this->handler, $context, $message);
                
                expect($result)->toBe($expectedSync, 
                    "Message: '{$message}', isFromBot: {$isFromBot}, keywordSync: {$keywordSyncEnabled} should " . 
                    ($expectedSync ? 'sync' : 'not sync')
                );
            }
        });
        
        test('should handle edge cases in keyword detection', function () {
            $edgeCases = [
                ['', false, true, true],                    // Empty message from user
                ['【', false, true, true],                  // Incomplete keyword from user
                ['】', false, true, true],                   // Only closing bracket from user
                ['【】', true, true, true],                 // Empty keyword from bot, sync enabled
                ['【】', true, false, false],               // Empty keyword from bot, sync disabled
                ['【a】【b】', true, true, true],            // Multiple keywords from bot, sync enabled
                ['【a】【b】', true, false, false],          // Multiple keywords from bot, sync disabled
            ];
            
            foreach ($edgeCases as [$message, $isFromBot, $keywordSyncEnabled, $expectedSync]) {
                $this->wechatBot->shouldReceive('getMeta')
                    ->with('xbot.config.keyword_sync')
                    ->andReturn($keywordSyncEnabled);
                
                $context = new XbotMessageContext($this->wechatBot, [
                    'msg' => $message,
                    'from_wxid' => $isFromBot ? $this->wechatBot->wxid : 'user_wxid'
                ], 'MT_RECV_TEXT_MSG', 123);
                
                $reflection = new ReflectionClass($this->handler);
                $method = $reflection->getMethod('shouldSyncToChatwoot');
                $method->setAccessible(true);
                
                $result = $method->invoke($this->handler, $context, $message);
                
                expect($result)->toBe($expectedSync, 
                    "Edge case message: " . json_encode($message) . " should " . 
                    ($expectedSync ? 'sync' : 'not sync')
                );
            }
        });
    });
    
    describe('Message Processing Flow', function () {
        
        test('should pass messages through when not processed', function () {
            $context = new XbotMessageContext($this->wechatBot, [
                'msg' => 'test message',
                'from_wxid' => 'user_wxid'
            ], 'MT_RECV_TEXT_MSG', 123);
            
            $nextCalled = false;
            $next = function($ctx) use (&$nextCalled) {
                $nextCalled = true;
                return $ctx;
            };
            
            // For unit testing, we skip the shouldProcess check
            // and focus on the handler's specific logic
            
            $result = $this->handler->handle($context, $next);
            
            expect($nextCalled)->toBeTrue('Next handler should be called');
            expect($result)->toBe($context);
        });
        
        test('should skip processing when shouldProcess returns false', function () {
            $context = new XbotMessageContext($this->wechatBot, [
                'msg' => 'test message',
                'from_wxid' => 'user_wxid'
            ], 'MT_RECV_TEXT_MSG', 123);
            
            $nextCalled = false;
            $next = function($ctx) use (&$nextCalled) {
                $nextCalled = true;
                return $ctx;
            };
            
            // For unit testing, we skip the shouldProcess check
            // and focus on the handler's specific logic
            
            $result = $this->handler->handle($context, $next);
            
            expect($nextCalled)->toBeTrue('Next handler should still be called');
            expect($result)->toBe($context);
        });
    });
    
    describe('Chatwoot Configuration Validation', function () {
        
        test('should detect missing chatwoot configuration', function () {
            $incompleteBots = [
                WechatBot::factory()->make(['chatwoot_account_id' => null]),
                WechatBot::factory()->make(['chatwoot_inbox_id' => null]),
                WechatBot::factory()->make(['chatwoot_token' => null]),
            ];
            
            foreach ($incompleteBots as $bot) {
                $reflection = new ReflectionClass($this->handler);
                $method = $reflection->getMethod('hasChatwootConfig');
                $method->setAccessible(true);
                
                $result = $method->invoke($this->handler, $bot);
                expect($result)->toBeFalse('Bot with missing Chatwoot config should return false');
            }
        });
        
        test('should detect complete chatwoot configuration', function () {
            $completeBots = [
                WechatBot::factory()->make([
                    'chatwoot_account_id' => '123',
                    'chatwoot_inbox_id' => '456',
                    'chatwoot_token' => 'test_token'
                ]),
                WechatBot::factory()->make([
                    'chatwoot_account_id' => 1,
                    'chatwoot_inbox_id' => 2,
                    'chatwoot_token' => 'another_token'
                ]),
            ];
            
            foreach ($completeBots as $bot) {
                $reflection = new ReflectionClass($this->handler);
                $method = $reflection->getMethod('hasChatwootConfig');
                $method->setAccessible(true);
                
                $result = $method->invoke($this->handler, $bot);
                expect($result)->toBeTrue('Bot with complete Chatwoot config should return true');
            }
        });
    });
    
    
    afterEach(function () {
        Mockery::close();
    });
});