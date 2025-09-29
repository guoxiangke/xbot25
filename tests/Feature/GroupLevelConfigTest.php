<?php

use App\Pipelines\Xbot\Message\SelfMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'wxid_bot_test',
        'chatwoot_account_id' => 17,
        'chatwoot_inbox_id' => 2,
        'chatwoot_token' => 'test-token'
    ]);
    
    $this->selfHandler = new SelfMessageHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Group Level Configuration Commands', function () {
    
    test('room_msg configuration works in group chat', function () {
        // 测试在群聊中启用room_msg
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set room_msg 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群设置成功: 群消息处理 已启用');
        
        // 重新初始化HTTP mock
        XbotTestHelpers::mockXbotService();
        
        // 测试禁用room_msg
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set room_msg 0', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群设置成功: 群消息处理 已禁用');
    });
    
    test('check_in configuration auto-enables room_msg', function () {
        // 测试启用check_in会自动启用room_msg
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set check_in 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '群设置成功: 群签到系统 已启用') &&
                   str_contains($msg, '自动启用了该群的消息监听');
        });
        
        // 重新初始化HTTP mock
        XbotTestHelpers::mockXbotService();
        
        // 测试禁用check_in
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set check_in 0', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群设置成功: 群签到系统 已禁用');
    });
    
    test('youtube_room configuration works in group chat', function () {
        // 测试YouTube群级别配置
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set youtube_room 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群设置成功: YouTube链接响应 已启用');
        
        // 重新初始化HTTP mock
        XbotTestHelpers::mockXbotService();
        
        // 测试禁用youtube_room
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set youtube_room 0', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群设置成功: YouTube链接响应 已禁用');
    });
    
    test('group level configs require group chat context', function () {
        // 测试群级别配置在私聊中会失败
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set youtube_room 1'
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('群级别配置只能在群聊中设置');
    });
    
    test('invalid group level config values are rejected', function () {
        // 测试无效的配置值
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set room_msg invalid', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('无效的值: invalid');
    });
});

describe('Group Level Configuration Integration', function () {
    
    test('multiple group configs can be set independently', function () {
        $roomWxid = '56878503348@chatroom';
        
        // 设置多个群级别配置
        $configs = [
            'room_msg' => ['command' => '/set room_msg 1', 'response' => '群设置成功: 群消息处理 已启用'],
            'youtube_room' => ['command' => '/set youtube_room 1', 'response' => '群设置成功: YouTube链接响应 已启用'],
        ];
        
        foreach ($configs as $key => $config) {
            $context = XbotTestHelpers::createRoomMessageContext(
                $this->wechatBot,
                $roomWxid,
                ['data' => ['msg' => $config['command'], 'from_wxid' => $this->wechatBot->wxid]]
            );
            
            $this->selfHandler->handle($context, $this->next);
            
            XbotTestHelpers::assertMessageSent($config['response']);
            
            // 重新初始化HTTP mock为下一个配置
            XbotTestHelpers::mockXbotService();
        }
    });
    
    test('config command with invalid group config shows help', function () {
        // 测试未知的群级别配置
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set unknown_group_config 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '未知的设置项: unknown_group_config') &&
                   str_contains($msg, '群配置:') && str_contains($msg, 'youtube');
        });
    });
});

describe('Group Message Processing Logic', function () {
    
    test('group message context is properly identified', function () {
        // 验证群消息上下文被正确识别
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set room_msg 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        expect($context->isRoom)->toBeTrue();
        expect($context->roomWxid)->toBe('56878503348@chatroom');
        expect($context->isFromBot)->toBeTrue();
    });
    
    test('different groups can have different configurations', function () {
        // 测试不同群可以有不同的配置
        $room1 = '111@chatroom';
        $room2 = '222@chatroom';
        
        // 在群1中启用room_msg
        $context1 = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            $room1,
            ['data' => ['msg' => '/set room_msg 1', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context1, $this->next);
        XbotTestHelpers::assertMessageSent('群设置成功: 群消息处理 已启用');
        
        // 重新初始化HTTP mock
        XbotTestHelpers::mockXbotService();
        
        // 在群2中禁用room_msg
        $context2 = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            $room2,
            ['data' => ['msg' => '/set room_msg 0', 'from_wxid' => $this->wechatBot->wxid]]
        );
        
        $this->selfHandler->handle($context2, $this->next);
        XbotTestHelpers::assertMessageSent('群设置成功: 群消息处理 已禁用');
    });
});