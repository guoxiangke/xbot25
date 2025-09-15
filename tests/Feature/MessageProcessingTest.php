<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\Message\ChatwootHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Message Processing Integration Tests', function () {
    
    beforeEach(function () {
        $this->wechatClient = WechatClient::factory()->create([
            'token' => 'test-token',
            'endpoint' => 'http://localhost:8001',
        ]);
        
        $this->wechatBot = WechatBot::factory()->create([
            'wxid' => 'test-bot-wxid',
            'wechat_client_id' => $this->wechatClient->id,
            'client_id' => 1,
        ]);
    });

    describe('Chatwoot Sync Logic', function () {
        
        test('user message always sync to chatwoot', function () {
            // 启用Chatwoot配置
            $this->wechatBot->setMeta('chatwoot_enabled', true);
            
            // 模拟用户发送消息
            $userMessageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 1,
                'data' => [
                    'from_wxid' => 'user123',
                    'to_wxid' => 'test-bot-wxid',
                    'msg' => '621',
                    'msgid' => '123456789',
                    'timestamp' => time(),
                    'room_wxid' => '',
                    'at_user_list' => [],
                    'is_pc' => 1,
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $userMessageData['data'], 'MT_RECV_TEXT_MSG', 123);
            
            // 验证用户消息应该被处理
            expect($context->isFromBot)->toBeFalse();
            expect($context->msgType)->toBe('MT_RECV_TEXT_MSG');
            expect($context->requestRawData['msg'])->toBe('621');
            
            // 测试 ChatwootHandler 对用户消息的处理
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('shouldSyncToChatwoot');
            $method->setAccessible(true);
            
            // 用户消息应该始终同步（当Chatwoot启用时）
            expect($method->invoke($handler, $context, '621'))->toBeTrue();
        });
        
        test('bot messages always sync to chatwoot', function () {
            // 启用Chatwoot配置
            $this->wechatBot->setMeta('chatwoot_enabled', true);
            
            // 模拟机器人发送的消息
            $botResponseData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 1,
                'data' => [
                    'from_wxid' => 'test-bot-wxid', // 机器人自己发送的消息
                    'to_wxid' => 'user123',
                    'msg' => '【621】真道分解 09-08',
                    'msgid' => '123456790',
                    'timestamp' => time(),
                    'room_wxid' => '',
                    'at_user_list' => [],
                    'is_pc' => 1,
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $botResponseData['data'], 'MT_RECV_TEXT_MSG', 123);
            
            // 验证这是机器人消息
            expect($context->isFromBot)->toBeTrue();
            
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $shouldSyncMethod = $reflection->getMethod('shouldSyncToChatwoot');
            $shouldSyncMethod->setAccessible(true);
            
            // 所有机器人消息都应该同步到 Chatwoot（当Chatwoot启用时）
            expect($shouldSyncMethod->invoke($handler, $context, '【621】真道分解 09-08'))->toBeTrue();
        });
        
        test('messages do not sync to chatwoot when disabled', function () {
            // 不启用Chatwoot配置（默认为false）
            
            // 模拟用户发送消息
            $userMessageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 1,
                'data' => [
                    'from_wxid' => 'user123',
                    'to_wxid' => 'test-bot-wxid',
                    'msg' => 'hi',
                    'msgid' => '123456789',
                    'timestamp' => time(),
                    'room_wxid' => '',
                    'at_user_list' => [],
                    'is_pc' => 1,
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $userMessageData['data'], 'MT_RECV_TEXT_MSG', 123);
            
            // 测试 ChatwootHandler 对消息的处理
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('shouldSyncToChatwoot');
            $method->setAccessible(true);
            
            // 当Chatwoot未启用时，消息不应该同步
            expect($method->invoke($handler, $context, 'hi'))->toBeFalse();
        });
        
        test('group message with room_wxid handling', function () {
            // 模拟群消息
            $groupMessageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 1,
                'data' => [
                    'from_wxid' => 'user123', // 群成员发送
                    'to_wxid' => 'test-bot-wxid',
                    'msg' => '621',
                    'msgid' => '123456792',
                    'timestamp' => time(),
                    'room_wxid' => 'testroom@chatroom', // 群消息标识
                    'at_user_list' => [],
                    'is_pc' => 1,
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $groupMessageData['data'], 'MT_RECV_TEXT_MSG', 123);
            
            // 验证群消息特征
            expect($context->isRoom)->toBeTrue();
            expect($context->roomWxid)->toBe('testroom@chatroom');
            expect($context->isFromBot)->toBeFalse();
            
            // 验证wxid获取逻辑（群消息应该用room_wxid）
            expect($context->wxid)->toBe('testroom@chatroom');
        });
    });

    describe('Configuration Edge Cases', function () {
        
        test('chatwoot configuration validation when enabling', function () {
            $configManager = new \App\Services\Managers\ConfigManager($this->wechatBot);
            
            // 场景1：缺少chatwoot配置时不应该通过验证
            $configManager->setChatwootConfig('chatwoot_account_id', null);
            $configManager->setChatwootConfig('chatwoot_inbox_id', null);
            $configManager->setChatwootConfig('chatwoot_token', null);
            
            // 这里我们只能测试配置是否正确存储，实际的验证逻辑在SelfMessageHandler中
            expect($configManager->getChatwootConfig('chatwoot_account_id'))->toBeNull();
            expect($configManager->getChatwootConfig('chatwoot_inbox_id'))->toBeNull();
            expect($configManager->getChatwootConfig('chatwoot_token'))->toBeNull();
            
            // 场景2：有完整配置时应该可以启用
            $configManager->setChatwootConfig('chatwoot_account_id', 1);
            $configManager->setChatwootConfig('chatwoot_inbox_id', 1);
            $configManager->setChatwootConfig('chatwoot_token', 'test-token');
            
            expect($configManager->getChatwootConfig('chatwoot_account_id'))->toBe(1);
            expect($configManager->getChatwootConfig('chatwoot_inbox_id'))->toBe(1);
            expect($configManager->getChatwootConfig('chatwoot_token'))->toBe('test-token');
        });
    });

    describe('Real Message Data Processing', function () {
        
        test('process actual login message format', function () {
            // 基于实际日志的登录消息
            $loginData = [
                'type' => 'MT_USER_LOGIN',
                'client_id' => 3,
                'data' => [
                    'account' => '',
                    'avatar' => 'http://mmhead.c2c.wechat.com/mmhead/ver_1/test.jpg',
                    'nickname' => 'AI助理',
                    'phone' => '+16268881668',
                    'pid' => 9156,
                    'unread_msg_count' => 0,
                    'wx_user_dir' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_test\\',
                    'wxid' => 'wxid_test123'
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $loginData['data'], 'MT_USER_LOGIN', 123);
            
            expect($context->msgType)->toBe('MT_USER_LOGIN');
            expect($context->requestRawData['wxid'])->toBe('wxid_test123');
            expect($context->requestRawData['nickname'])->toBe('AI助理');
        });
        
        test('process actual text message format', function () {
            // 基于实际日志的文本消息
            $textData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 3,
                'data' => [
                    'at_user_list' => [],
                    'from_wxid' => 'wxid_user123',
                    'is_pc' => 1,
                    'msg' => '621',
                    'msgid' => '1519446518643495357',
                    'room_wxid' => '',
                    'timestamp' => 1757282611,
                    'to_wxid' => 'wxid_test123',
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $textData['data'], 'MT_RECV_TEXT_MSG', 123);
            
            expect($context->msgType)->toBe('MT_RECV_TEXT_MSG');
            expect($context->fromWxid)->toBe('wxid_user123');
            expect($context->requestRawData['msg'])->toBe('621');
            expect($context->isRoom)->toBeFalse(); // room_wxid为空表示私聊
        });
    });
});