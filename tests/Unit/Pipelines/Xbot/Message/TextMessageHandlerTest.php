<?php

use App\Pipelines\Xbot\Message\TextMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'test-bot-123'
    ]);
    
    $this->handler = new TextMessageHandler();
    $this->next = XbotTestHelpers::createPipelineNext();
    
    // Mock HTTP请求，防止实际发送消息
    XbotTestHelpers::mockXbotService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Message Type Filtering', function () {
    
    test('only processes text messages', function () {
        // 文本消息应该被处理
        $textContext = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('hello world')->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($textContext, $next);
        expect($nextCalled)->toBeTrue();
        
        // 图片消息应该被忽略
        $imageContext = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::pictureMessage()->build(),
            'MT_RECV_PICTURE_MSG'
        );
        
        $nextCalled = false;
        $this->handler->handle($imageContext, $next);
        expect($nextCalled)->toBeTrue(); // 应该直接传递到下一个处理器
    });
    
    test('respects shouldProcess check', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('test message')->build()
        );
        
        // 手动标记为已处理
        $context->markAsProcessed('SomeOtherHandler');
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        expect($nextCalled)->toBeTrue(); // 应该直接传递到下一个处理器
    });
});

describe('Message Content Processing', function () {
    
    test('processes regular text messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('  hello world  ')->build()
        );
        
        $nextCalled = false;
        $capturedContext = null;
        $next = function ($ctx) use (&$nextCalled, &$capturedContext) {
            $nextCalled = true;
            $capturedContext = $ctx;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($capturedContext->getProcessedMessage())->toBe('hello world'); // 应该去除首尾空格
    });
    
    test('handles empty messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('   ')->build()
        );
        
        $nextCalled = false;
        $capturedContext = null;
        $next = function ($ctx) use (&$nextCalled, &$capturedContext) {
            $nextCalled = true;
            $capturedContext = $ctx;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($capturedContext->getProcessedMessage())->toBe('');
    });
    
    test('handles missing message field', function () {
        $messageData = MessageDataBuilder::textMessage()->build();
        unset($messageData['data']['msg']);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            $messageData
        );
        
        $nextCalled = false;
        $capturedContext = null;
        $next = function ($ctx) use (&$nextCalled, &$capturedContext) {
            $nextCalled = true;
            $capturedContext = $ctx;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($capturedContext->getProcessedMessage())->toBe('');
    });
});

describe('Configuration Command Protection', function () {
    
    test('blocks unauthorized set commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set chatwoot 1')
                ->from('wxid_user123') // 普通用户
                ->build()
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('⚠️ 无权限执行配置命令，仅机器人管理员可用');
        XbotTestHelpers::assertContextProcessed($context, TextMessageHandler::class);
        expect($result)->toBe($context); // 应该返回context而不是继续到next
    });
    
    test('blocks unauthorized config commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/config room_msg 1')
                ->from('wxid_user123') // 普通用户
                ->build()
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('⚠️ 无权限执行配置命令，仅机器人管理员可用');
        XbotTestHelpers::assertContextProcessed($context, TextMessageHandler::class);
    });
    
    test('allows group level config commands from users', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set room_listen 1')
                ->from('wxid_user123') // 普通用户
                ->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue(); // 应该继续到下一个处理器
        XbotTestHelpers::assertNoMessageSent(); // 不应该发送权限错误消息
        XbotTestHelpers::assertContextNotProcessed($context);
    });
    
    test('allows bot self commands', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 1'
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue(); // 应该继续到下一个处理器
        XbotTestHelpers::assertNoMessageSent(); // 不应该发送权限错误消息
    });
    
    test('ignores incomplete config commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set room_msg') // 缺少值
                ->from('wxid_user123')
                ->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue(); // 应该继续到下一个处理器
        XbotTestHelpers::assertNoMessageSent(); // 不应该发送权限错误消息
    });
    
    test('handles case insensitive commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/SET CHATWOOT 1')
                ->from('wxid_user123')
                ->build()
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('⚠️ 无权限执行配置命令，仅机器人管理员可用');
    });
    
    test('handles commands with extra spaces', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('  /set   room_msg   1  ')
                ->from('wxid_user123')
                ->build()
        );
        
        $result = $this->handler->handle($context, $this->next);
        
        XbotTestHelpers::assertMessageSent('⚠️ 无权限执行配置命令，仅机器人管理员可用');
    });
});

describe('Regular Message Processing', function () {
    
    test('processes normal user messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('Hello, how are you?')
                ->from('wxid_user123')
                ->build()
        );
        
        $nextCalled = false;
        $capturedContext = null;
        $next = function ($ctx) use (&$nextCalled, &$capturedContext) {
            $nextCalled = true;
            $capturedContext = $ctx;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($capturedContext->getProcessedMessage())->toBe('Hello, how are you?');
        XbotTestHelpers::assertNoMessageSent();
        XbotTestHelpers::assertContextNotProcessed($context);
    });
    
    test('processes messages that look like commands but are not', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('Can you help me set up my account?')
                ->from('wxid_user123')
                ->build()
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

describe('Context Flow Control', function () {
    
    test('continues to next handler for normal messages', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('normal message')->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $result = $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue();
        expect($result)->toBe($context);
        XbotTestHelpers::assertContextNotProcessed($context);
    });
    
    test('stops processing for unauthorized commands', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set chatwoot 1')
                ->from('wxid_user123')
                ->build()
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $result = $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeFalse(); // next不应该被调用
        expect($result)->toBe($context);
        XbotTestHelpers::assertContextProcessed($context, TextMessageHandler::class);
    });
});

describe('Integration Tests', function () {
    
    test('works correctly in room context', function () {
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => '/set room_listen 1', 'from_wxid' => 'wxid_user123']]
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue(); // 群级别配置应该被允许
        XbotTestHelpers::assertNoMessageSent();
    });
    
    test('correctly identifies bot self messages', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set chatwoot 1'
        );
        
        $nextCalled = false;
        $next = function ($ctx) use (&$nextCalled) {
            $nextCalled = true;
            return $ctx;
        };
        
        $this->handler->handle($context, $next);
        
        expect($nextCalled)->toBeTrue(); // 机器人自己的消息应该被允许
        XbotTestHelpers::assertNoMessageSent();
    });
});