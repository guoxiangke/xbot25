<?php

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\State\ZombieCheckHandler;
use App\Pipelines\Xbot\Contact\FriendRequestHandler;
use App\Pipelines\Xbot\Contact\NotificationHandler;
use App\Pipelines\Xbot\Message\BuiltinCommandHandler;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\Message\TextMessageHandler;
use App\Pipelines\Xbot\Message\KeywordResponseHandler;
use App\Pipelines\Xbot\Message\ChatwootHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBotWithChatwoot([
        'wxid' => 'test-bot-123'
    ]);
    
    // Mock HTTP服务
    XbotTestHelpers::mockXbotService();
    XbotTestHelpers::mockChatwootService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Three-Stage Pipeline Architecture', function () {
    
    test('processes message through complete pipeline', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('hello world')
                ->from('wxid_user123')
                ->build()
        );
        
        // 模拟完整的三阶段Pipeline
        $statePipeline = [
            ZombieCheckHandler::class
        ];
        
        $contactPipeline = [
            NotificationHandler::class,
            FriendRequestHandler::class
        ];
        
        $messagePipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            KeywordResponseHandler::class,
            ChatwootHandler::class
        ];
        
        // Stage 1: State Pipeline
        $result = app(Pipeline::class)
            ->send($context)
            ->through($statePipeline)
            ->thenReturn();
        
        expect($result)->toBe($context);
        XbotTestHelpers::assertContextNotProcessed($context);
        
        // Stage 2: Contact Pipeline  
        $result = app(Pipeline::class)
            ->send($context)
            ->through($contactPipeline)
            ->thenReturn();
        
        expect($result)->toBe($context);
        XbotTestHelpers::assertContextNotProcessed($context);
        
        // Stage 3: Message Pipeline
        $result = app(Pipeline::class)
            ->send($context)
            ->through($messagePipeline)
            ->thenReturn();
        
        expect($result)->toBe($context);
        
        // 验证消息被处理和同步到Chatwoot
        XbotTestHelpers::assertChatwootApiCalled();
    });
    
    test('early termination in state pipeline', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('test')->build()
        );
        
        // 手动标记为已处理，模拟在State阶段被处理
        $context->markAsProcessed('TestStateHandler');
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        $result = app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 所有Message阶段的处理器都应该跳过处理
        XbotTestHelpers::assertNoMessageSent();
    });
});

describe('Handler Interaction and Priority', function () {
    
    test('builtin commands are processed first', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('/help')->build()
        );
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证帮助消息被发送
        Http::assertSent(function ($request) {
            $data = $request->data();
            return isset($data['msg']) && str_contains($data['msg'], 'AI机器人');
        });
        
        // 验证消息也被同步到Chatwoot（因为BuiltinCommandHandler继续传递）
        XbotTestHelpers::assertChatwootApiCalled();
    });
    
    test('self message config commands are processed and stopped', function () {
        $context = XbotTestHelpers::createBotMessageContext(
            $this->wechatBot,
            '/set room_msg 1'
        );
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证配置被设置
        expect($this->wechatBot->refresh()->getMeta('room_msg_enabled'))->toBeTrue();
        
        // 验证context被标记为已处理
        XbotTestHelpers::assertContextProcessed($context, SelfMessageHandler::class);
    });
    
    test('unauthorized config commands are blocked in text handler', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('/set chatwoot 1')
                ->from('wxid_user123') // 普通用户
                ->build()
        );
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证权限错误消息被发送
        XbotTestHelpers::assertMessageSent('⚠️ 无权限执行配置命令，仅机器人管理员可用');
        
        // 验证context被标记为已处理（在TextMessageHandler）
        XbotTestHelpers::assertContextProcessed($context, TextMessageHandler::class);
    });
});

describe('Message Flow and Context State', function () {
    
    test('context preserves data through pipeline', function () {
        $originalMessage = 'test message content';
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage($originalMessage)->build()
        );
        
        $pipeline = [
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证消息内容被正确处理和保留
        expect($context->getProcessedMessage())->toBe($originalMessage);
        
        // 验证原始数据仍然存在
        expect($context->requestRawData['msg'])->toBe($originalMessage);
    });
    
    test('pipeline respects processing status', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('test')->build()
        );
        
        // 创建一个自定义处理器来标记消息为已处理
        $earlyTerminationHandler = new class {
            public function handle($context, $next) {
                $context->markAsProcessed('EarlyTermination');
                return $context; // 不调用$next，停止Pipeline
            }
        };
        
        $pipeline = [
            $earlyTerminationHandler,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证后续处理器没有被执行
        XbotTestHelpers::assertNoMessageSent();
        XbotTestHelpers::assertContextProcessed($context, 'EarlyTermination');
    });
});

describe('Complex Message Scenarios', function () {
    
    test('subscription message processing', function () {
        // 创建订阅
        XbotSubscription::factory()->create([
            'wechat_bot_id' => $this->wechatBot->id,
            'wxid' => 'wxid_user123',
            'keyword' => '天气',
            'cron' => '0 7 * * *'
        ]);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()
                ->withMessage('天气')
                ->from('wxid_user123')
                ->build()
        );
        
        // 启用关键词响应
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'keyword_resources' => true
        ]);
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            KeywordResponseHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证订阅响应被触发
        // （具体的验证取决于SubscriptionHandler的实现）
        
        // 验证消息被同步到Chatwoot
        XbotTestHelpers::assertChatwootApiCalled();
    });
    
    test('room message with configuration', function () {
        $context = XbotTestHelpers::createRoomMessageContext(
            $this->wechatBot,
            '56878503348@chatroom',
            ['data' => ['msg' => 'hello group', 'from_wxid' => 'wxid_user123']]
        );
        
        // 启用群消息处理
        XbotTestHelpers::setWechatBotConfig($this->wechatBot, [
            'room_msg' => true
        ]);
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        // 验证群消息被处理
        expect($context->isRoom)->toBeTrue();
        expect($context->roomWxid)->toBe('56878503348@chatroom');
        
        // 验证消息被同步到Chatwoot
        XbotTestHelpers::assertChatwootApiCalled();
    });
});

describe('Error Handling in Pipeline', function () {
    
    test('handles handler exceptions gracefully', function () {
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            MessageDataBuilder::textMessage()->withMessage('test')->build()
        );
        
        // 创建一个会抛出异常的处理器
        $faultyHandler = new class {
            public function handle($context, $next) {
                throw new \Exception('Handler error');
            }
        };
        
        $pipeline = [
            $faultyHandler,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        // 在实际应用中，异常应该被捕获和处理
        try {
            app(Pipeline::class)
                ->send($context)
                ->through($pipeline)
                ->thenReturn();
        } catch (\Exception $e) {
            expect($e->getMessage())->toBe('Handler error');
        }
    });
    
    test('handles malformed context data', function () {
        // 创建一个数据不完整的context
        $incompleteData = MessageDataBuilder::textMessage()->build();
        unset($incompleteData['data']['from_wxid']);
        
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            $incompleteData
        );
        
        $pipeline = [
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        // 应该能够处理不完整的数据而不崩溃
        $result = app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->thenReturn();
        
        expect($result)->toBe($context);
    });
});

describe('Performance and Optimization', function () {
    
    test('pipeline processes messages efficiently', function () {
        $contexts = [];
        
        // 创建多个消息上下文
        for ($i = 0; $i < 50; $i++) {
            $contexts[] = XbotTestHelpers::createMessageContext(
                $this->wechatBot,
                MessageDataBuilder::textMessage()
                    ->withMessage("message {$i}")
                    ->withRandomData()
                    ->build()
            );
        }
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        $startTime = microtime(true);
        
        foreach ($contexts as $context) {
            app(Pipeline::class)
                ->send($context)
                ->through($pipeline)
                ->thenReturn();
        }
        
        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;
        
        // 50条消息应该在合理时间内处理完成
        expect($totalTime)->toBeLessThan(2.0);
    });
    
    test('pipeline memory usage is reasonable', function () {
        $initialMemory = memory_get_usage();
        
        $pipeline = [
            BuiltinCommandHandler::class,
            SelfMessageHandler::class,
            TextMessageHandler::class,
            ChatwootHandler::class
        ];
        
        // 处理大量消息
        for ($i = 0; $i < 100; $i++) {
            $context = XbotTestHelpers::createMessageContext(
                $this->wechatBot,
                MessageDataBuilder::textMessage()
                    ->withMessage("memory test {$i}")
                    ->withRandomData()
                    ->build()
            );
            
            app(Pipeline::class)
                ->send($context)
                ->through($pipeline)
                ->thenReturn();
        }
        
        $finalMemory = memory_get_usage();
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // 内存增长应该在合理范围内（小于10MB）
        expect($memoryIncrease)->toBeLessThan(10 * 1024 * 1024);
    });
});