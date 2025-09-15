<?php

use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'test-bot-123',
        'chatwoot_account_id' => null,
        'chatwoot_inbox_id' => null,
        'chatwoot_token' => null,
    ]);
    
    $this->handler = new SelfMessageHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    // Mock HTTP请求，防止实际发送消息
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Message Filtering', function () {
    
    test('only processes bot self messages', function () {
        // 用户消息 - 应该被忽略
        $userContext = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set room_msg 1')
                ->from('wxid_user123')
                ->to($this->wechatBot->wxid)
                ->build()
        );
        
        $this->handler->handle($userContext, $this->next);
        XbotTestHelpers::assertNoMessageSent();
        
        // 机器人消息 - 应该被处理
        $botContext = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 1'
        );
        
        $this->handler->handle($botContext, $this->next);
        XbotTestHelpers::assertMessageSent('设置成功: room_msg 已启用');
    });
    
    test('ignores non-text messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::pictureMessage()
                ->asBotMessage($this->wechatBot->wxid)
                ->build(),
            'MT_RECV_PICTURE_MSG'
        );
        
        $this->handler->handle($context, $this->next);
        XbotTestHelpers::assertNoMessageSent();
    });
});

describe('Set Command Processing', function () {
    
    test('handles basic set command', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: room_msg 已启用');
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
    
    test('handles config command format', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/config keyword_resources 0'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: keyword_resources 已禁用');
        expect($this->wechatBot->getMeta('keyword_resources_enabled'))->toBeFalse();
    });
    
    test('validates command parameters', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set invalid_key 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains(XbotTestHelpers::extractMessageContent($data), '未知的设置项: invalid_key');
        });
    });
    
    test('handles insufficient parameters', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('用法: /set <key> <value>');
    });
    
    test('parses boolean values correctly', function () {
        $testCases = [
            ['1', true],
            ['0', false], 
            ['on', true],
            ['off', false],
            ['true', true],
            ['false', false],
            ['yes', true],
            ['no', false],
            ['enable', true],
            ['disable', false]
        ];
        
        foreach ($testCases as [$value, $expected]) {
            $context = XbotTestHelpers::createBotMessageContext(
                $this->wechatBot,
                "/set room_msg {$value}"
            );
            
            $this->handler->handle($context, $this->next);
            
            expect($this->wechatBot->getMeta('room_msg_enabled'))->toBe($expected);
            XbotTestHelpers::mockXbotService(); // 重新初始化HTTP mock以清除记录
        }
    });
    
    test('rejects invalid boolean values', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg invalid'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('无效的值: invalid');
    });
});

describe('Chatwoot Configuration', function () {
    
    test('prevents enabling chatwoot without required configs', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains(XbotTestHelpers::extractMessageContent($data), '❌ 无法启用 Chatwoot，缺少必要配置');
        });
    });
    
    test('allows enabling chatwoot with complete configs', function () {
        // 设置完整的Chatwoot配置
        $this->wechatBot->setMeta('chatwoot', [
            'chatwoot_account_id' => 1,
            'chatwoot_inbox_id' => 1,
            'chatwoot_token' => 'test-token'
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: chatwoot 已启用');
        expect($this->wechatBot->getMeta('chatwoot_enabled'))->toBeTrue();
    });
    
    test('handles chatwoot config setting', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot_account_id 17'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: Chatwoot账户ID = 17');
        $chatwootConfig = $this->wechatBot->getMeta('chatwoot');
        expect($chatwootConfig['chatwoot_account_id'])->toBe(17);
    });
    
    test('validates numeric chatwoot configs', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot_account_id abc'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('❌ Chatwoot账户ID 必须是大于0的数字');
    });
    
    test('rejects zero values for numeric configs', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot_inbox_id 0'
        );
        
        $this->handler->handle($context, $this->next);
        
        // 实际上代码将"0"视为空值，所以期望"不能为空"消息
        XbotTestHelpers::assertMessageSent('❌ Chatwoot收件箱ID 的值不能为空');
    });
    
    test('accepts empty chatwoot token as valid value', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot_token ""'
        );
        
        $this->handler->handle($context, $this->next);
        
        // 系统接受空字符串作为有效的token值
        XbotTestHelpers::assertMessageSent('设置成功: ChatwootAPI令牌 = ""');
    });
});

describe('Get Chatwoot Command', function () {
    
    test('displays chatwoot config status', function () {
        // 设置Chatwoot配置
        $this->wechatBot->setMeta('chatwoot', [
            'chatwoot_account_id' => 17,
            'chatwoot_inbox_id' => 2,
            'chatwoot_token' => 'very-long-secret-token-12345'
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/get chatwoot'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg &&
                   str_contains($msg, '🔧 Chatwoot 配置状态') &&
                   str_contains($msg, 'Chatwoot账户ID: 17') &&
                   str_contains($msg, 'Chatwoot收件箱ID: 2') &&
                   str_contains($msg, 'very***2345') && // Token被遮掩
                   str_contains($msg, '✅ 配置完整');
        });
    });
    
    test('shows missing config warning', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/get chatwoot'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains(XbotTestHelpers::extractMessageContent($data), '⚠️ 缺少配置');
        });
    });
});

describe('Special Configuration Logic', function () {
    
    test('auto-enables room_msg when enabling check_in', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set check_in 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $messageContent = XbotTestHelpers::extractMessageContent($data);
            return str_contains($messageContent, '设置成功: check_in 已启用') &&
                   str_contains($messageContent, '签到功能需要群消息处理，已自动开启 room_msg');
        });
        
        expect($this->wechatBot->getMeta('check_in_enabled'))->toBeTrue();
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
});

describe('Config Help Command', function () {
    
    test('shows config help when no parameters provided', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/config'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   (str_contains($msg, '📋 当前配置状态') ||
                    str_contains($msg, '🔧 配置管理命令'));
        });
    });
});

describe('Command Processing Control', function () {
    
    test('marks context as processed after handling config commands', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 1'
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertContextProcessed($context, SelfMessageHandler::class);
        expect($result)->toBe($context); // 应该返回context而不是继续到next
    });
    
    test('continues to next handler for non-config messages', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            'regular bot message'
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $result = $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        XbotTestHelpers::assertContextNotProcessed($context);
    });
});

describe('Edge Cases', function () {
    
    test('handles multiple spaces in commands', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set   room_msg    1   '
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('设置成功: room_msg 已启用');
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
    
    test('is case sensitive for commands', function () {
        // 测试大写命令不被识别（系统是大小写敏感的）
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/SET room_msg ON'
        );
        
        $this->handler->handle($context, $this->next);
        
        // 大写的 /SET 不应该被识别，所以不会发送HTTP请求
        XbotTestHelpers::assertNoMessageSent();
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeNull();
    });
    
    test('handles empty message gracefully', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            ''
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        XbotTestHelpers::assertNoMessageSent();
    });
});

describe('Integration with XbotConfigManager', function () {
    
    test('uses available commands from config manager', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set unknown_config 1'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            $configManager = new ConfigManager($this->wechatBot);
            $allowedKeys = ConfigManager::getAvailableCommands();
            
            // 验证错误消息包含所有允许的配置项
            foreach ($allowedKeys as $key) {
                if (!str_contains($msg, $key)) {
                    return false;
                }
            }
            return str_contains($msg, '未知的设置项: unknown_config');
        });
    });
});