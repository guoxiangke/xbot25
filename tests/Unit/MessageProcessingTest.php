<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\Message\KeywordResponseHandler;
use App\Pipelines\Xbot\Message\ChatwootHandler;
use App\Services\XbotConfigManager;
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

    describe('Keyword Response and Chatwoot Sync Logic', function () {
        
        test('user message triggers keyword response and both sync when keyword_sync enabled', function () {
            // 设置配置
            $this->wechatBot->setMeta('keyword_resources_enabled', true);
            $this->wechatBot->setMeta('keyword_sync_enabled', true);
            
            // 模拟用户发送关键词消息
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
            
            $context = new XbotMessageContext($this->wechatBot, $userMessageData);
            
            // 验证用户消息应该被处理
            expect($context->isFromBot)->toBeFalse();
            expect($context->msgType)->toBe('MT_RECV_TEXT_MSG');
            expect($context->requestRawData['data']['msg'])->toBe('621');
            
            // 测试 ChatwootHandler 对用户消息的处理
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('shouldSyncToChatwoot');
            $method->setAccessible(true);
            
            // 用户消息应该同步，无论keyword_sync如何设置
            expect($method->invoke($handler, $context, '621'))->toBeTrue();
        });
        
        test('bot keyword response message sync depends on keyword_sync setting', function () {
            // 模拟机器人发送的关键词响应消息
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
            
            $context = new XbotMessageContext($this->wechatBot, $botResponseData);
            
            // 验证这是机器人消息
            expect($context->isFromBot)->toBeTrue();
            
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $shouldSyncMethod = $reflection->getMethod('shouldSyncToChatwoot');
            $shouldSyncMethod->setAccessible(true);
            $isKeywordMethod = $reflection->getMethod('isKeywordResponseMessage');
            $isKeywordMethod->setAccessible(true);
            
            // 验证能正确识别关键词响应消息
            expect($isKeywordMethod->invoke($handler, '【621】真道分解 09-08'))->toBeTrue();
            
            // 场景1：keyword_sync 启用时，关键词响应应该同步
            $this->wechatBot->setMeta('keyword_sync_enabled', true);
            expect($shouldSyncMethod->invoke($handler, $context, '【621】真道分解 09-08'))->toBeTrue();
            
            // 场景2：keyword_sync 禁用时，关键词响应不应该同步
            $this->wechatBot->setMeta('keyword_sync_enabled', false);
            expect($shouldSyncMethod->invoke($handler, $context, '【621】真道分解 09-08'))->toBeFalse();
        });
        
        test('bot system messages always sync regardless of keyword_sync', function () {
            // 模拟机器人发送的系统消息
            $botSystemData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 1,
                'data' => [
                    'from_wxid' => 'test-bot-wxid',
                    'to_wxid' => 'user123',
                    'msg' => '设置成功: chatwoot 已启用',
                    'msgid' => '123456791',
                    'timestamp' => time(),
                    'room_wxid' => '',
                    'at_user_list' => [],
                    'is_pc' => 1,
                    'wx_type' => 1
                ]
            ];
            
            $context = new XbotMessageContext($this->wechatBot, $botSystemData);
            
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $shouldSyncMethod = $reflection->getMethod('shouldSyncToChatwoot');
            $shouldSyncMethod->setAccessible(true);
            $isKeywordMethod = $reflection->getMethod('isKeywordResponseMessage');
            $isKeywordMethod->setAccessible(true);
            
            // 验证不是关键词响应消息
            expect($isKeywordMethod->invoke($handler, '设置成功: chatwoot 已启用'))->toBeFalse();
            
            // 系统消息应该始终同步，无论keyword_sync如何设置
            $this->wechatBot->setMeta('keyword_sync_enabled', true);
            expect($shouldSyncMethod->invoke($handler, $context, '设置成功: chatwoot 已启用'))->toBeTrue();
            
            $this->wechatBot->setMeta('keyword_sync_enabled', false);
            expect($shouldSyncMethod->invoke($handler, $context, '设置成功: chatwoot 已启用'))->toBeTrue();
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
            
            $context = new XbotMessageContext($this->wechatBot, $groupMessageData);
            
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
            $configManager = new XbotConfigManager($this->wechatBot);
            
            // 场景1：缺少chatwoot配置时不应该通过验证
            $this->wechatBot->chatwoot_account_id = null;
            $this->wechatBot->chatwoot_inbox_id = null;
            $this->wechatBot->chatwoot_token = null;
            $this->wechatBot->save();
            
            // 这里我们只能测试配置是否正确存储，实际的验证逻辑在SelfMessageHandler中
            expect($this->wechatBot->chatwoot_account_id)->toBeNull();
            expect($this->wechatBot->chatwoot_inbox_id)->toBeNull();
            expect($this->wechatBot->chatwoot_token)->toBeNull();
            
            // 场景2：有完整配置时应该可以启用
            $this->wechatBot->chatwoot_account_id = 1;
            $this->wechatBot->chatwoot_inbox_id = 1;
            $this->wechatBot->chatwoot_token = 'test-token';
            $this->wechatBot->save();
            
            expect($this->wechatBot->chatwoot_account_id)->toBe(1);
            expect($this->wechatBot->chatwoot_inbox_id)->toBe(1);
            expect($this->wechatBot->chatwoot_token)->toBe('test-token');
        });
        
        test('different keyword response message formats', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('isKeywordResponseMessage');
            $method->setAccessible(true);
            
            // 测试各种关键词响应格式
            $testCases = [
                '【621】真道分解 09-08' => true,
                '【新闻】今日头条' => true,
                '【音乐】赞美诗歌' => true,
                '【】空标签' => true,
                '【多个】【标签】测试' => true,
                '普通消息' => false,
                'help指令' => false,
                '/config 配置' => false,
                '设置成功: room_msg 已启用' => false,
                '恭喜！登陆成功，正在初始化...' => false,
            ];
            
            foreach ($testCases as $message => $expected) {
                expect($method->invoke($handler, $message))
                    ->toBe($expected, "Message: '{$message}' should " . ($expected ? 'match' : 'not match') . ' keyword response pattern');
            }
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
            
            $context = new XbotMessageContext($this->wechatBot, $loginData);
            
            expect($context->msgType)->toBe('MT_USER_LOGIN');
            expect($context->requestRawData['data']['wxid'])->toBe('wxid_test123');
            expect($context->requestRawData['data']['nickname'])->toBe('AI助理');
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
            
            $context = new XbotMessageContext($this->wechatBot, $textData);
            
            expect($context->msgType)->toBe('MT_RECV_TEXT_MSG');
            expect($context->fromWxid)->toBe('wxid_user123');
            expect($context->requestRawData['data']['msg'])->toBe('621');
            expect($context->isRoom)->toBeFalse(); // room_wxid为空表示私聊
        });
    });
});