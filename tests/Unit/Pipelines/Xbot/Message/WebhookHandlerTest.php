<?php

namespace Tests\Unit;

use App\Models\WechatBot;
use App\Pipelines\Xbot\Message\WebhookHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_handler_skips_bot_sent_messages()
    {
        // 创建机器人
        $wechatBot = new WechatBot([
            'wxid' => 'test_bot_wxid'
        ]);
        $wechatBot->id = 1;

        // 设置 webhook 配置
        $wechatBot->setMeta('webhook', [
            'url' => 'https://webhook.site/test',
            'secret' => 'test-secret'
        ]);

        // 模拟机器人发送的消息
        $requestRawData = [
            'msgid' => '12345',
            'msg' => '机器人发送的消息',
            'from_wxid' => 'test_bot_wxid', // 机器人自己发送
            'to_wxid' => 'user123',
            'room_wxid' => '',
            'timestamp' => time()
        ];

        // 创建消息上下文
        $context = new XbotMessageContext($wechatBot, $requestRawData, 'MT_RECV_TEXT_MSG', 1);

        // Mock HTTP 请求，确保不会发送
        Http::fake();

        $handler = new WebhookHandler();
        $next = function($ctx) { return $ctx; };

        // 执行处理
        $result = $handler->handle($context, $next);

        // 验证没有HTTP请求被发送
        Http::assertNothingSent();
        
        // 确保返回了context
        $this->assertSame($context, $result);
    }

    public function test_webhook_handler_sends_webhook_for_received_messages()
    {
        // 创建机器人
        $wechatBot = new WechatBot([
            'wxid' => 'test_bot_wxid'
        ]);
        $wechatBot->id = 2;

        // 设置 webhook 配置
        $wechatBot->setMeta('webhook', [
            'url' => 'https://webhook.site/test',
            'secret' => 'test-secret'
        ]);

        // 设置联系人数据
        $wechatBot->setMeta('contacts', [
            'user123' => [
                'wxid' => 'user123',
                'remark' => '测试用户',
                'nickname' => '测试昵称',
                'avatar' => 'https://example.com/avatar.jpg'
            ]
        ]);

        // 模拟用户发送的消息
        $requestRawData = [
            'msgid' => '12345',
            'msg' => '用户发送的消息',
            'from_wxid' => 'user123', // 用户发送
            'to_wxid' => 'test_bot_wxid',
            'room_wxid' => '',
            'timestamp' => time()
        ];

        // 创建消息上下文
        $context = new XbotMessageContext($wechatBot, $requestRawData, 'MT_RECV_TEXT_MSG', 1);

        // Mock HTTP 请求
        Http::fake([
            'webhook.site/test' => Http::response(['success' => true], 200)
        ]);

        $handler = new WebhookHandler();
        $next = function($ctx) { return $ctx; };

        // 执行处理
        $result = $handler->handle($context, $next);

        // 验证HTTP请求被发送
        Http::assertSent(function ($request) {
            return $request->url() === 'https://webhook.site/test' &&
                   $request->hasHeader('X-Webhook-Signature') &&
                   $request['msgid'] === '12345' &&
                   $request['content'] === '用户发送的消息' &&
                   $request['wxid'] === 'user123';
        });
        
        // 确保返回了context
        $this->assertSame($context, $result);
    }

    public function test_webhook_handler_skips_when_no_url_configured()
    {
        // 创建机器人，不设置 webhook URL
        $wechatBot = new WechatBot([
            'wxid' => 'test_bot_wxid'
        ]);
        $wechatBot->id = 3;

        // 模拟用户发送的消息
        $requestRawData = [
            'msgid' => '12345',
            'msg' => '用户发送的消息',
            'from_wxid' => 'user123',
            'to_wxid' => 'test_bot_wxid',
            'room_wxid' => '',
            'timestamp' => time()
        ];

        // 创建消息上下文
        $context = new XbotMessageContext($wechatBot, $requestRawData, 'MT_RECV_TEXT_MSG', 1);

        // Mock HTTP 请求
        Http::fake();

        $handler = new WebhookHandler();
        $next = function($ctx) { return $ctx; };

        // 执行处理
        $result = $handler->handle($context, $next);

        // 验证没有HTTP请求被发送
        Http::assertNothingSent();
        
        // 确保返回了context
        $this->assertSame($context, $result);
    }
}