<?php

namespace Tests\Unit\Pipelines\Xbot\Message;

use Tests\TestCase;
use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\XbotConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SelfMessageCheckInRoomAutoConfigTest extends TestCase
{
    use RefreshDatabase;

    private WechatBot $wechatBot;
    private SelfMessageHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        
        $client = WechatClient::factory()->create();
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $client->id,
        ]);
        
        $this->handler = new SelfMessageHandler();
    }

    /**
     * 测试：当全局 room_msg 关闭时，启用 check_in_room 应该自动启用 room_listen
     */
    public function test_check_in_room_auto_enables_room_listen_when_global_room_msg_disabled()
    {
        $roomWxid = 'test_room_123@chatroom';
        
        // 设置全局 room_msg 为关闭状态
        $configManager = new XbotConfigManager($this->wechatBot);
        $configManager->setConfig('room_msg', false);
        
        // 确认该群还没有 room_listen 配置
        $this->assertNull($this->wechatBot->getMeta('room_msg_enabled_specials')[$roomWxid] ?? null);
        
        // 构造消息上下文
        $context = $this->createMessageContext($roomWxid, '/config check_in_room 1');
        
        // 调用处理方法
        $this->handler->handle($context, function($ctx) { return $ctx; });
        
        // 验证 check_in_room 被设置
        $this->assertTrue($this->wechatBot->getMeta('check_in_specials')[$roomWxid] ?? false);
        
        // 验证 room_listen 被自动设置
        $this->assertTrue($this->wechatBot->getMeta('room_msg_enabled_specials')[$roomWxid] ?? false);
    }

    /**
     * 测试：当全局 room_msg 开启时，启用 check_in_room 不应该自动设置 room_listen
     */
    public function test_check_in_room_does_not_auto_enable_room_listen_when_global_room_msg_enabled()
    {
        $roomWxid = 'test_room_456@chatroom';
        
        // 设置全局 room_msg 为开启状态
        $configManager = new XbotConfigManager($this->wechatBot);
        $configManager->setConfig('room_msg', true);
        
        // 构造消息上下文
        $context = $this->createMessageContext($roomWxid, '/config check_in_room 1');
        
        // 调用处理方法
        $this->handler->handle($context, function($ctx) { return $ctx; });
        
        // 验证 check_in_room 被设置
        $this->assertTrue($this->wechatBot->getMeta('check_in_specials')[$roomWxid] ?? false);
        
        // 验证 room_listen 没有被自动设置
        $this->assertNull($this->wechatBot->getMeta('room_msg_enabled_specials')[$roomWxid] ?? null);
    }

    /**
     * 测试：当群已经有 room_listen 配置时，不应该覆盖现有配置
     */
    public function test_check_in_room_does_not_override_existing_room_listen_config()
    {
        $roomWxid = 'test_room_789@chatroom';
        
        // 设置全局 room_msg 为关闭状态
        $configManager = new XbotConfigManager($this->wechatBot);
        $configManager->setConfig('room_msg', false);
        
        // 预先设置该群的 room_listen 为 false
        $this->wechatBot->setMeta('room_msg_enabled_specials', [$roomWxid => false]);
        
        // 构造消息上下文
        $context = $this->createMessageContext($roomWxid, '/config check_in_room 1');
        
        // 调用处理方法
        $this->handler->handle($context, function($ctx) { return $ctx; });
        
        // 验证 check_in_room 被设置
        $this->assertTrue($this->wechatBot->getMeta('check_in_specials')[$roomWxid] ?? false);
        
        // 验证原有的 room_listen 配置没有被覆盖
        $this->assertFalse($this->wechatBot->getMeta('room_msg_enabled_specials')[$roomWxid]);
    }

    /**
     * 测试：关闭 check_in_room 不应该影响 room_listen 配置
     */
    public function test_disabling_check_in_room_does_not_affect_room_listen()
    {
        $roomWxid = 'test_room_abc@chatroom';
        
        // 设置全局 room_msg 为关闭状态，并预设 room_listen 为 true
        $configManager = new XbotConfigManager($this->wechatBot);
        $configManager->setConfig('room_msg', false);
        $this->wechatBot->setMeta('room_msg_enabled_specials', [$roomWxid => true]);
        
        // 构造消息上下文关闭 check_in_room
        $context = $this->createMessageContext($roomWxid, '/config check_in_room 0');
        
        // 调用处理方法
        $this->handler->handle($context, function($ctx) { return $ctx; });
        
        // 验证 check_in_room 被关闭
        $this->assertFalse($this->wechatBot->getMeta('check_in_specials')[$roomWxid] ?? true);
        
        // 验证 room_listen 配置保持不变
        $this->assertTrue($this->wechatBot->getMeta('room_msg_enabled_specials')[$roomWxid]);
    }

    /**
     * 创建消息上下文
     */
    private function createMessageContext(string $roomWxid, string $messageText): XbotMessageContext
    {
        $requestData = [
            'type' => 1,
            'room_wxid' => $roomWxid,
            'from_wxid' => $this->wechatBot->wxid,
            'msg' => $messageText,
            'msgid' => 'test_msg_' . time(),
        ];

        return new XbotMessageContext(
            $this->wechatBot,
            $requestData,
            'MT_RECV_TEXT_MSG',
            $this->wechatBot->client_id
        );
    }
}