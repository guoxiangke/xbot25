<?php

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\Message\BuiltinCommandHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'test-bot-123',
        'login_at' => now()->subHours(2)
    ]);
    
    $this->handler = new BuiltinCommandHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    // Mock HTTP请求，防止实际发送消息
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Command Recognition', function () {
    
    test('recognizes help command', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/help')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('Hi，我是一个AI机器人，暂支持以下指令：');
    });
    
    test('recognizes whoami command', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/whoami')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '登陆时长') &&
                   str_contains($msg, '设备端口') &&
                   str_contains($msg, '北京时间');
        });
    });
    
    test('recognizes get wxid command in private chat', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/get wxid')
                ->from('wxid_user123')
                ->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('wxid_user123');
    });
    
    test('recognizes get wxid command in room chat', function () {
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/get wxid']]
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('56878503348@chatroom');
    });
    
    test('handles case insensitive commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/HELP')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('Hi，我是一个AI机器人，暂支持以下指令：');
    });
    
    test('handles commands with extra spaces', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('  /help  ')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('Hi，我是一个AI机器人，暂支持以下指令：');
    });
    
    test('ignores non-command messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('hello world')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertNoMessageSent();
    });
    
    test('ignores non-text messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::pictureMessage()->build(),
            'MT_RECV_PICTURE_MSG'
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertNoMessageSent();
    });
});

describe('Subscription Commands', function () {
    
    test('lists subscriptions when user has subscriptions', function () {
        // 创建测试订阅
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user123',
            'keyword' => '每日新闻',
            'cron' => '0 7 * * *'
        ]);
        
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user123',
            'keyword' => '天气预报',
            'cron' => '0 8 * * *'
        ]);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/get subscriptions')
                ->from('wxid_user123')
                ->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '当前订阅列表') &&
                   str_contains($msg, '每日新闻') &&
                   str_contains($msg, '天气预报') &&
                   str_contains($msg, '7点') &&
                   str_contains($msg, '8点');
        });
    });
    
    test('shows no subscriptions message when user has no subscriptions', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/get subscriptions')
                ->from('wxid_user456')
                ->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('暂无订阅');
    });
    
    test('filters subscriptions by wxid', function () {
        // 为不同用户创建订阅
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user123',
            'keyword' => '用户123的订阅',
            'cron' => '0 7 * * *'
        ]);
        
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user456',
            'keyword' => '用户456的订阅',
            'cron' => '0 8 * * *'
        ]);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/get subscriptions')
                ->from('wxid_user123')
                ->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '用户123的订阅') &&
                   !str_contains($msg, '用户456的订阅');
        });
    });
});

describe('Command Processing Flow', function () {
    
    test('continues to next handler after processing command', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/help')->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $result = $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($result)->toBe($context);
    });
    
    test('continues to next handler for non-commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('regular message')->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $result = $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($result)->toBe($context);
    });
    
    test('marks context as replied after processing command', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/help')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        // 验证context被标记为已回复
        XbotTestHelpers::assertContextReplied($context);
    });
});

describe('Edge Cases', function () {
    
    test('handles empty message gracefully', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertNoMessageSent();
    });
    
    test('handles missing message field gracefully', function () {
        $messageData = MessageDataBuilder::textMessage()->build();
        unset($messageData['data']['msg']);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            $messageData
        );
        
        $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertNoMessageSent();
    });
    
    test('handles malformed subscription cron gracefully', function () {
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user123',
            'keyword' => '测试订阅',
            'cron' => 'invalid_cron'
        ]);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/get subscriptions')
                ->from('wxid_user123')
                ->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '测试订阅') &&
                   str_contains($msg, '7点'); // 默认值
        });
    });
});

describe('Integration with Context', function () {
    
    test('uses correct wxid for room messages', function () {
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/get subscriptions']]
        );
        
        // 为群创建订阅
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => '56878503348@chatroom',
            'keyword' => '群订阅',
            'cron' => '0 9 * * *'
        ]);
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, '群订阅');
        });
    });
    
    test('uses login time from wechat bot in whoami command', function () {
        $loginTime = now()->subMinutes(30);
        $this->wechatBot->login_at = $loginTime;
        $this->wechatBot->save();
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/whoami')->build()
        );
        
        $this->handler->handle($context, $this->next);
        
        Http::assertSent(function ($request) use ($loginTime) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && 
                   str_contains($msg, $loginTime->format('Y-m-d H:i:s'));
        });
    });
});