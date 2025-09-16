<?php

use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\Message\BuiltinCommandHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'wxid_t36o5djpivk312',
        'chatwoot_account_id' => 17,
        'chatwoot_inbox_id' => 2,
        'chatwoot_token' => 'test-token'
    ]);
    
    $this->selfHandler = new SelfMessageHandler();
    $this->builtinHandler = new BuiltinCommandHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Real Configuration Commands Based on Manual Testing', function () {
    
    test('config command shows complete status as in real test', function () {
        // 基于真实测试数据，设置一些配置项
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'chatwoot' => true,
            'room_msg' => true,
            'keyword_resources' => true,
            'payment_auto' => true,
            'check_in' => false,
            'friend_auto_accept' => true,
            'friend_welcome' => true
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/config'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        // 验证配置状态显示包含所有关键信息
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data) ?? '';
            
            return str_contains($message, '📋 当前配置状态：') &&
                   str_contains($message, '🌐 全局配置：') &&
                   str_contains($message, '• chatwoot: ✅开启') &&
                   str_contains($message, '• room_msg: ✅开启') &&
                   str_contains($message, '• keyword_resources: ✅开启') &&
                   str_contains($message, '• payment_auto: ✅开启') &&
                   str_contains($message, '• check_in: ❌关闭') &&
                   str_contains($message, '🔧 配置管理命令：') &&
                   str_contains($message, '/set <key> <value>') &&
                   str_contains($message, '/config <key> <value>');
        });
    });
    
    test('set chatwoot command works as in real test', function () {
        // 测试禁用chatwoot
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: chatwoot 已禁用');
        
        expect($this->wechatBot->getMeta('chatwoot_enabled'))->toBeFalse();
        
        // 重新初始化HTTP mock以清理记录
        XbotTestHelpers::mockXbotService();
        
        // 设置Chatwoot配置以满足启用要求
        $this->wechatBot->setMeta('chatwoot', [
            'chatwoot_account_id' => 17,
            'chatwoot_inbox_id' => 2,
            'chatwoot_token' => 'test-token'
        ]);
        
        // 测试启用chatwoot
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: chatwoot 已启用');
        
        expect($this->wechatBot->getMeta('chatwoot_enabled'))->toBeTrue();
    });
    
    test('set room_msg command works as in real test', function () {
        // 测试禁用群消息处理
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: room_msg 已禁用');
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeFalse();
        
        
        // 测试启用群消息处理
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: room_msg 已启用');
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
    
    test('set keyword_resources command works as in real test', function () {
        // 测试启用关键词资源
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set keyword_resources 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: keyword_resources 已启用');
        expect($this->wechatBot->getMeta('keyword_resources_enabled'))->toBeTrue();
        
        
        // 测试禁用关键词资源
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set keyword_resources 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: keyword_resources 已禁用');
        expect($this->wechatBot->getMeta('keyword_resources_enabled'))->toBeFalse();
    });
    
    test('set check_in command auto-enables room_msg as in real test', function () {
        // 确保room_msg初始为禁用状态
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'room_msg' => false
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set check_in 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        // 验证特殊消息：启用签到时自动启用群消息处理
        Http::assertSent(function ($request) {
            $data = $request->data();
            return XbotTestHelpers::extractMessageContent($data) === '设置成功: check_in 已启用' . "\n" . 
                   '⚠️ 签到功能需要群消息处理，已自动开启 room_msg';
        });
        
        expect($this->wechatBot->getMeta('check_in_enabled'))->toBeTrue();
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
        
        
        // 测试禁用签到
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set check_in 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: check_in 已禁用');
        expect($this->wechatBot->getMeta('check_in_enabled'))->toBeFalse();
    });
    
    test('set payment_auto command works as in real test', function () {
        // 测试禁用自动收款
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set payment_auto 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: payment_auto 已禁用');
        expect($this->wechatBot->getMeta('payment_auto_enabled'))->toBeFalse();
        
        
        // 测试启用自动收款
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set payment_auto 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: payment_auto 已启用');
        expect($this->wechatBot->getMeta('payment_auto_enabled'))->toBeTrue();
    });
    
    test('friend configuration commands work as in real test', function () {
        // 测试好友自动接受配置
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set friend_auto_accept 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: friend_auto_accept 已启用');
        expect($this->wechatBot->getMeta('friend_auto_accept_enabled'))->toBeTrue();
        
        
        // 测试禁用好友自动接受
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set friend_auto_accept 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: friend_auto_accept 已禁用');
        expect($this->wechatBot->getMeta('friend_auto_accept_enabled'))->toBeFalse();
        
        
        // 测试好友欢迎消息配置
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set friend_welcome 0'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: friend_welcome 已禁用');
        expect($this->wechatBot->getMeta('friend_welcome_enabled'))->toBeFalse();
        
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set friend_welcome 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: friend_welcome 已启用');
        expect($this->wechatBot->getMeta('friend_welcome_enabled'))->toBeTrue();
    });
});

describe('Configuration Sequence Testing', function () {
    
    test('sequential configuration changes as performed in manual test', function () {
        // 设置Chatwoot配置以满足启用要求
        $this->wechatBot->setMeta('chatwoot', [
            'chatwoot_account_id' => 17,
            'chatwoot_inbox_id' => 2,
            'chatwoot_token' => 'test-token'
        ]);
        
        // 模拟手动测试中的配置序列
        $commands = [
            '/set chatwoot 0' => '设置成功: chatwoot 已禁用',
            '/set chatwoot 1' => '设置成功: chatwoot 已启用',
            '/set room_msg 0' => '设置成功: room_msg 已禁用',
            '/set room_msg 1' => '设置成功: room_msg 已启用',
            '/set keyword_resources 1' => '设置成功: keyword_resources 已启用',
            '/set keyword_resources 0' => '设置成功: keyword_resources 已禁用',
            '/set keyword_resources 1' => '设置成功: keyword_resources 已启用',
            '/set payment_auto 0' => '设置成功: payment_auto 已禁用',
            '/set payment_auto 1' => '设置成功: payment_auto 已启用',
        ];
        
        foreach ($commands as $command => $expectedResponse) {
            $context = XbotTestHelpers::createBotMessageContext(
                $this->wechatBot,
                $command
            );
            
            $this->selfHandler->handle($context, $this->next);
            
            XbotTestHelpers::assertMessageSent($expectedResponse);
            
            // 重新初始化HTTP mock以清理记录，为下一个命令准备
            XbotTestHelpers::mockXbotService();
        }
    });
    
    test('check_in enabling automatically enables room_msg', function () {
        // 先禁用room_msg
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'room_msg' => false
        ]);
        
        // 启用check_in应该自动启用room_msg
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set check_in 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        // 验证特殊的双重消息
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains(XbotTestHelpers::extractMessageContent($data), '设置成功: check_in 已启用') &&
                   str_contains(XbotTestHelpers::extractMessageContent($data), '已自动开启 room_msg');
        });
        
        // 验证两个配置都被启用
        expect($this->wechatBot->getMeta('check_in_enabled'))->toBeTrue();
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
});

describe('Configuration Display Format Validation', function () {
    
    test('config status display matches real format', function () {
        // 设置一组配置项来匹配真实测试的状态
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'chatwoot' => true,
            'room_msg' => true,
            'keyword_resources' => true,
            'payment_auto' => true,
            'check_in' => false,
            'friend_auto_accept' => true,
            'friend_welcome' => true
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/config'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data) ?? '';
            
            // 验证格式符合真实测试的输出
            $checks = [
                '📋 当前配置状态：',
                '🌐 全局配置：',
                '• chatwoot: ✅开启 Chatwoot同步',
                '• room_msg: ✅开启 群消息处理',
                '• keyword_resources: ✅开启 关键词资源响应',
                '• payment_auto: ✅开启 自动收款',
                '• check_in: ❌关闭 签到系统',
                '🔧 配置管理命令：',
                '/set <key> <value> - 设置配置项',
                '/config <key> <value> - 设置配置项（与/set等效）',
                '/get chatwoot - 查看Chatwoot配置详情',
                '/sync contacts - 同步联系人列表',
                '/check online - 检查微信在线状态'
            ];
            
            foreach ($checks as $check) {
                if (!str_contains($message, $check)) {
                    return false;
                }
            }
            
            return true;
        });
    });
});