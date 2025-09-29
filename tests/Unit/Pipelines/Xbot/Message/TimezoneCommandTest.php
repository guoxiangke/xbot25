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
        'wxid' => 'test-bot-timezone',
        'login_at' => now()->subHours(2)
    ]);
    
    $this->handler = new SelfMessageHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    // Mock HTTP请求，防止实际发送消息
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Timezone Command Processing', function () {
    
    test('handles valid positive timezone offset', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +8'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '✅ 群时区设置成功') && 
                   str_contains($message, 'UTC+8');
        });
        
        // 验证配置已保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->toHaveKey('56878503348@chatroom');
        expect($timezoneConfigs['56878503348@chatroom'])->toBe(8);
    });
    
    test('handles valid negative timezone offset', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone -7'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '✅ 群时区设置成功') && 
                   str_contains($message, 'UTC-7');
        });
        
        // 验证配置已保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs['56878503348@chatroom'])->toBe(-7);
    });
    
    test('handles zero timezone offset', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +0'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '✅ 群时区设置成功') && 
                   str_contains($message, 'UTC+0');
        });
        
        // 验证配置已保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs['56878503348@chatroom'])->toBe(0);
    });
    
    test('rejects invalid timezone format', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +abc'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('❌ 时区格式错误');
        
        // 验证配置未保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->not->toHaveKey('56878503348@chatroom');
    });
    
    test('rejects timezone offset out of range positive', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +15'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '❌ 时区偏移值超出范围') && 
                   str_contains($message, '您输入的：15');
        });
        
        // 验证配置未保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->not->toHaveKey('56878503348@chatroom');
    });
    
    test('rejects timezone offset out of range negative', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone -15'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = '56878503348@chatroom';
        $context->requestRawData['room_wxid'] = '56878503348@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '❌ 时区偏移值超出范围') && 
                   str_contains($message, '您输入的：-15');
        });
        
        // 验证配置未保存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->not->toHaveKey('56878503348@chatroom');
    });
    
    test('rejects timezone setting in private chat', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +8'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('❌ 时区设置只能在群聊中执行');
    });
    
    test('handles various timezone format variations', function () {
        $testCases = [
            ['+8', 8],
            ['-7', -7],
            ['8', 8],
            ['-0', 0],
            ['+12', 12],
            ['-12', -12]
        ];
        
        foreach ($testCases as [$input, $expected]) {
            // 清理之前的配置
            $this->wechatBot->setMeta('room_timezone_specials', []);
            
            $context = XbotTestHelpers::createBotMessageContext(
                $this->wechatBot,
                "/set timezone $input"
            );
            
            // 模拟在群聊中发送
            $context->isRoom = true;
            $context->roomWxid = '56878503348@chatroom';
            $context->requestRawData['room_wxid'] = '56878503348@chatroom';
            
            $this->handler->handle($context, $this->next);
            
            // 验证配置已保存
            $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
            expect($timezoneConfigs['56878503348@chatroom'])->toBe($expected, "Failed for input: $input");
        }
    });
});

describe('Get Timezone Command', function () {
    
    test('shows empty timezone config when no rooms configured', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/get timezone'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '🕐 群时区配置状态') && 
                   str_contains($message, '❌ 暂无群级别时区配置') &&
                   str_contains($message, '🌐 默认时区: UTC+8');
        });
    });
    
    test('shows timezone configurations for multiple rooms', function () {
        // 设置多个群的时区配置
        $this->wechatBot->setMeta('room_timezone_specials', [
            'room1@chatroom' => 8,
            'room2@chatroom' => -7,
            'room3@chatroom' => 0
        ]);
        
        // 设置联系人信息以便显示群名
        $this->wechatBot->setMeta('contacts', [
            'room1@chatroom' => ['nickname' => '测试群1', 'remark' => ''],
            'room2@chatroom' => ['nickname' => '测试群2', 'remark' => ''],
            'room3@chatroom' => ['nickname' => '测试群3', 'remark' => '']
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/get timezone'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, '🕐 群时区配置状态') && 
                   str_contains($message, '✅ 已配置 3 个群时区') &&
                   str_contains($message, 'UTC+8') &&
                   str_contains($message, 'UTC-7') &&
                   str_contains($message, 'UTC+0') &&
                   str_contains($message, '测试群1') &&
                   str_contains($message, '测试群2') &&
                   str_contains($message, '测试群3');
        });
    });
    
    test('handles rooms without contact info gracefully', function () {
        // 设置时区配置但不设置联系人信息
        $this->wechatBot->setMeta('room_timezone_specials', [
            'unknown_room@chatroom' => 5
        ]);
        
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/get timezone'
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $message = XbotTestHelpers::extractMessageContent($data);
            return str_contains($message, 'UTC+5') &&
                   str_contains($message, 'unknown_room@chatroom'); // 使用wxid作为备用名称
        });
    });
});

describe('Edge Cases and Error Handling', function () {
    
    test('handles malformed timezone commands gracefully', function () {
        $malformedCommands = [
            '/set timezone',           // 缺少参数
            '/set timezone abc def',   // 多余参数
            '/set timezone +',         // 不完整的符号
            '/set timezone -',         // 不完整的符号
            '/set timezone ++8',       // 双符号
            '/set timezone +-8',       // 冲突符号
        ];
        
        foreach ($malformedCommands as $command) {
            Http::fake(); // 重置HTTP mock
            XbotTestHelpers::mockXbotService();
            
            $context = XbotTestHelpers::createBotMessageContext(
                $this->wechatBot,
                $command
            );
            
            // 模拟在群聊中发送
            $context->isRoom = true;
            $context->roomWxid = '56878503348@chatroom';
            $context->requestRawData['room_wxid'] = '56878503348@chatroom';
            
            $this->handler->handle($context, $this->next);
            
            // 应该收到错误消息
            Http::assertSent(function ($request) use ($command) {
                $data = $request->data();
                $message = XbotTestHelpers::extractMessageContent($data);
                $isError = str_contains($message, '❌ 时区格式错误') || 
                          str_contains($message, '用法:');
                
                if (!$isError) {
                    dump("Command: $command", "Response: $message");
                }
                return $isError;
            });
        }
    });
    
    test('preserves existing timezone configs when setting new ones', function () {
        // 设置初始配置
        $this->wechatBot->setMeta('room_timezone_specials', [
            'room1@chatroom' => 8,
            'room2@chatroom' => -5
        ]);
        
        // 为第三个群设置时区
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone +2'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = 'room3@chatroom';
        $context->requestRawData['room_wxid'] = 'room3@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        // 验证所有配置都保留
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->toHaveKey('room1@chatroom');
        expect($timezoneConfigs)->toHaveKey('room2@chatroom');
        expect($timezoneConfigs)->toHaveKey('room3@chatroom');
        expect($timezoneConfigs['room1@chatroom'])->toBe(8);
        expect($timezoneConfigs['room2@chatroom'])->toBe(-5);
        expect($timezoneConfigs['room3@chatroom'])->toBe(2);
    });
    
    test('updates existing timezone config for same room', function () {
        // 设置初始配置
        $this->wechatBot->setMeta('room_timezone_specials', [
            'room1@chatroom' => 8
        ]);
        
        // 更新同一个群的时区
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set timezone -3'
        );
        
        // 模拟在群聊中发送
        $context->isRoom = true;
        $context->roomWxid = 'room1@chatroom';
        $context->requestRawData['room_wxid'] = 'room1@chatroom';
        
        $this->handler->handle($context, $this->next);
        
        // 验证配置已更新
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs['room1@chatroom'])->toBe(-3);
    });
});