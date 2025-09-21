<?php

namespace Tests\Support;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Clients\XbotClient;
use Illuminate\Support\Facades\Http;
use Tests\Datasets\XbotMessageDataset;
use Tests\Support\TestWechatBot;
use Tests\Support\TestWechatClient;

class XbotTestHelpers
{
    /**
     * 创建测试用的微信客户端
     */
    public static function createWechatClient(array $attributes = []): WechatClient
    {
        return new TestWechatClient(array_merge([
            'id' => rand(1, 999999),
            'token' => 'test-token-' . uniqid(),
            'endpoint' => 'http://localhost:8001',
            'file_url' => 'http://localhost:8004',
            'file_path' => 'C:\\Windows\\test\\',
            'voice_url' => 'http://localhost:8003',
            'silk_path' => '/tmp/test'
        ], $attributes));
    }

    /**
     * 创建测试用的微信机器人
     */
    public static function createWechatBot(array $attributes = [], ?WechatClient $client = null): WechatBot
    {
        if (!$client) {
            $client = self::createWechatClient();
        }

        $bot = new TestWechatBot(array_merge([
            'id' => rand(1, 999999),
            'wxid' => 'test-bot-' . uniqid(),
            'wechat_client_id' => $client->id,
            'client_id' => 1,
            'login_at' => now(),
            'is_live_at' => now(),
            'expires_at' => now()->addMonths(3)
        ], $attributes));

        $bot->setRelation('wechatClient', $client);

        // 设置默认的联系人数据
        $bot->setMeta('contacts', [
            'wxid_user123' => [
                'wxid' => 'wxid_user123',
                'nickname' => '测试用户',
                'remark' => '测试备注',
                'avatar' => 'https://example.com/user123.jpg',
                'type' => 1
            ],
            'wxid_friend456' => [
                'wxid' => 'wxid_friend456',
                'nickname' => '测试好友',
                'remark' => '',
                'avatar' => 'https://example.com/friend456.jpg',
                'type' => 1
            ]
        ]);

        return $bot;
    }

    /**
     * 创建带有Chatwoot配置的机器人
     */
    public static function createWechatBotWithChatwoot(array $chatwootConfig = []): WechatBot
    {
        $bot = self::createWechatBot();

        // 设置Chatwoot配置在meta中
        $defaultChatwootConfig = [
            'chatwoot_account_id' => 1,
            'chatwoot_inbox_id' => 1,
            'chatwoot_token' => 'test-chatwoot-token'
        ];
        
        $bot->setMeta('chatwoot', array_merge($defaultChatwootConfig, $chatwootConfig));
        $bot->setMeta('chatwoot_enabled', true);
        
        return $bot;
    }

    /**
     * 创建消息上下文
     */
    public static function createMessageContext(
        WechatBot $wechatBot,
        array $messageData = null,
        string $msgType = 'MT_RECV_TEXT_MSG',
        int $clientId = 1
    ): XbotMessageContext {
        if (!$messageData) {
            $messageData = XbotMessageDataset::textMessage([
                'client_id' => $clientId,
                'data' => [
                    'to_wxid' => $wechatBot->wxid,
                    'from_wxid' => 'wxid_user123'
                ]
            ]);
        }

        // 提取data字段传递给XbotMessageContext，模拟XbotController的行为
        $requestRawData = $messageData['data'] ?? $messageData;

        return new XbotMessageContext($wechatBot, $requestRawData, $msgType, $clientId);
    }

    /**
     * 创建群消息上下文
     */
    public static function createRoomMessageContext(
        WechatBot $wechatBot,
        string $roomWxid = '56878503348@chatroom',
        array $overrides = []
    ): XbotMessageContext {
        $baseMessageData = XbotMessageDataset::roomTextMessage([
            'data' => [
                'room_wxid' => $roomWxid,
                'to_wxid' => $roomWxid,
                'from_wxid' => 'wxid_user123'
            ]
        ]);
        
        // 合并覆盖数据，确保data字段被正确合并而不是替换
        if (isset($overrides['data'])) {
            $baseMessageData['data'] = array_merge($baseMessageData['data'], $overrides['data']);
            unset($overrides['data']);
        }
        
        $messageData = array_merge($baseMessageData, $overrides);

        // 提取data字段传递给XbotMessageContext，模拟XbotController的行为
        $requestRawData = $messageData['data'] ?? $messageData;

        return new XbotMessageContext($wechatBot, $requestRawData, 'MT_RECV_TEXT_MSG', 1);
    }

    /**
     * 创建机器人发送的消息上下文
     */
    public static function createBotMessageContext(
        WechatBot $wechatBot,
        string $message = '机器人回复',
        array $overrides = []
    ): XbotMessageContext {
        $messageData = XbotMessageDataset::botSentMessage(array_merge([
            'data' => [
                'from_wxid' => $wechatBot->wxid,
                'to_wxid' => 'wxid_user123',
                'msg' => $message
            ]
        ], $overrides));

        // 提取data字段传递给XbotMessageContext，模拟XbotController的行为
        $requestRawData = $messageData['data'] ?? $messageData;

        return new XbotMessageContext($wechatBot, $requestRawData, 'MT_RECV_TEXT_MSG', 1);
    }

    /**
     * Mock Xbot服务的HTTP调用
     */
    public static function mockXbotService(array $responses = []): void
    {
        $defaultResponses = [
            'http://localhost:8001/*' => Http::response(['success' => true, 'data' => null], 200),
            'http://localhost:8004/*' => Http::response('file content', 200),
            'http://localhost:8003/*' => Http::response('voice content', 200)
        ];

        Http::fake(array_merge($defaultResponses, $responses));
    }

    /**
     * Mock Chatwoot API调用
     */
    public static function mockChatwootService(array $responses = []): void
    {
        $defaultResponses = [
            'app.chatwoot.com/*' => Http::response(['success' => true], 200),
            // 修复Chatwoot API响应结构匹配saveContact期望的格式
            '*/api/v1/accounts/*/contacts*' => Http::response([
                'payload' => [
                    'contact' => [
                        'id' => 123, 
                        'name' => 'Test Contact', 
                        'email' => 'test@example.com',
                        'contact_inboxes' => [
                            ['source_id' => 'test-source-id', 'inbox_id' => 1]
                        ]
                    ]
                ]
            ], 200),
            '*/api/v1/accounts/*/conversations*' => Http::response([
                'payload' => ['id' => 456, 'status' => 'open']
            ], 200)
        ];

        Http::fake(array_merge($defaultResponses, $responses));
    }

    /**
     * 验证消息是否被发送
     */
    public static function assertMessageSent(
        string $expectedMessage,
        string $expectedTarget = null,
        string $expectedMsgType = 'MT_SEND_TEXTMSG',
        bool $exact = false
    ): void {
        Http::assertSent(function ($request) use ($expectedMessage, $expectedTarget, $expectedMsgType, $exact) {
            $data = $request->data();
            
            // 检查消息类型
            if (isset($data['type']) && $data['type'] !== $expectedMsgType) {
                return false;
            }

            // 检查消息内容 - XbotClient发送的数据结构是data.content
            $messageContent = $data['data']['content'] ?? null;
            if ($messageContent) {
                if ($exact) {
                    if ($messageContent !== $expectedMessage) {
                        return false;
                    }
                } else {
                    if (!str_contains($messageContent, $expectedMessage)) {
                        return false;
                    }
                }
            } else {
                return false;
            }

            // 检查目标（如果指定）- XbotClient发送的数据结构是data.to_wxid
            $targetWxid = $data['data']['to_wxid'] ?? null;
            if ($expectedTarget && $targetWxid !== $expectedTarget) {
                return false;
            }

            return true;
        });
    }

    /**
     * 验证没有消息被发送
     */
    public static function assertNoMessageSent(): void
    {
        Http::assertNothingSent();
    }

    /**
     * 验证Chatwoot API被调用
     */
    public static function assertChatwootApiCalled(string $endpoint = null): void
    {
        Http::assertSent(function ($request) use ($endpoint) {
            $url = $request->url();
            
            if ($endpoint) {
                return str_contains($url, $endpoint);
            }
            
            // 检查是否包含Chatwoot API的特征
            return str_contains($url, '/api/v1/accounts/') || 
                   str_contains($url, 'chatwoot.com');
        });
    }

    /**
     * 设置机器人配置
     */
    public static function setWechatBotConfig(WechatBot $bot, array $config): void
    {
        foreach ($config as $key => $value) {
            // 使用与实际系统一致的meta key格式
            $bot->setMeta("{$key}_enabled", $value);
        }
        $bot->refresh(); // 刷新模型以确保meta数据已保存
    }

    /**
     * 获取机器人配置
     */
    public static function getWechatBotConfig(WechatBot $bot, string $key = null)
    {
        if ($key) {
            return $bot->getMeta("xbot_config.{$key}");
        }
        
        return $bot->getMeta('xbot_config', []);
    }

    /**
     * 创建带有联系人数据的机器人
     */
    public static function createWechatBotWithContacts(array $contacts = []): WechatBot
    {
        $bot = self::createWechatBot();
        
        $defaultContacts = [
            'wxid_user123' => [
                'wxid' => 'wxid_user123',
                'nickname' => '测试用户',
                'remark' => '测试备注',
                'avatar' => 'https://example.com/user123.jpg',
                'type' => 1
            ]
        ];
        
        $bot->setMeta('contacts', array_merge($defaultContacts, $contacts));
        
        return $bot;
    }

    /**
     * 模拟Pipeline处理器的next回调
     */
    public static function createPipelineNext(): \Closure
    {
        return function ($context) {
            return $context;
        };
    }

    /**
     * 验证上下文是否被标记为已处理
     */
    public static function assertContextProcessed(XbotMessageContext $context, string $handlerClass = null): void
    {
        if ($handlerClass) {
            \PHPUnit\Framework\Assert::assertTrue(
                $context->isProcessed(),
                "Context should be marked as processed by {$handlerClass}"
            );
        } else {
            \PHPUnit\Framework\Assert::assertTrue(
                $context->isProcessed(),
                "Context should be marked as processed"
            );
        }
    }

    /**
     * 验证上下文未被标记为已处理
     */
    public static function assertContextNotProcessed(XbotMessageContext $context): void
    {
        \PHPUnit\Framework\Assert::assertFalse(
            $context->isProcessed(),
            "Context should not be marked as processed"
        );
    }

    /**
     * 验证消息是否被标记为已回复
     */
    public static function assertContextReplied(XbotMessageContext $context): void
    {
        \PHPUnit\Framework\Assert::assertTrue(
            cache()->has($context->isRepliedKey),
            "Context should be marked as replied"
        );
    }

    /**
     * 验证消息未被标记为已回复
     */
    public static function assertContextNotReplied(XbotMessageContext $context): void
    {
        \PHPUnit\Framework\Assert::assertFalse(
            cache()->has($context->isRepliedKey),
            "Context should not be marked as replied"
        );
    }

    /**
     * 创建模拟的Xbot服务实例
     */
    public static function createMockXbot(WechatClient $client): XbotClient
    {
        return new XbotClient($client);
    }

    /**
     * 生成测试用的文件路径
     */
    public static function generateTestFilePath(string $type = 'image', string $extension = 'jpg'): string
    {
        $basePath = 'C:\\Users\\test\\Documents\\WeChat Files\\test_wxid\\FileStorage';
        $filename = uniqid() . '.' . $extension;
        
        switch ($type) {
            case 'image':
                return "{$basePath}\\Image\\2025-09\\{$filename}";
            case 'voice':
                return "{$basePath}\\Audio\\2025-09\\{$filename}";
            case 'video':
                return "{$basePath}\\Video\\2025-09\\{$filename}";
            case 'file':
                return "{$basePath}\\File\\2025-09\\{$filename}";
            default:
                return "{$basePath}\\{$filename}";
        }
    }

    /**
     * 从请求数据中提取消息内容（兼容新旧格式）
     */
    public static function extractMessageContent(array $data): ?string
    {
        return $data['data']['content'] ?? $data['msg'] ?? null;
    }

    /**
     * 从请求数据中提取目标wxid（兼容新旧格式）
     */
    public static function extractTargetWxid(array $data): ?string
    {
        return $data['data']['to_wxid'] ?? $data['to_wxid'] ?? null;
    }

    /**
     * 检查请求是否为指定的消息类型
     */
    public static function isMessageType(array $data, string $expectedType): bool
    {
        return ($data['type'] ?? '') === $expectedType;
    }

    /**
     * 清理测试环境
     */
    public static function cleanup(): void
    {
        // Laravel HTTP fake会在测试之间自动重置，无需手动清理
    }
}