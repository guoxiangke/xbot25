<?php

namespace Tests\Feature;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeMessageConfigTest extends TestCase
{
    use RefreshDatabase;

    protected WechatBot $wechatBot;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建测试数据
        $wechatClient = WechatClient::factory()->create();
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $wechatClient->id,
            'wxid' => 'test_bot_wxid',
            'client_id' => 123456
        ]);
    }

    public function test_welcome_msg_command_in_private_chat()
    {
        // 模拟私聊中的 welcome_msg 设置
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set welcome_msg "@nickname 你好，欢迎你！"',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是私聊（非群聊）
        $this->assertFalse($context->isRoom);
        
        // 模拟用户发送的消息（非机器人）
        $context->isFromBot = false;
        
        $handler = new SelfMessageHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context; // 模拟next()调用
        });
        
        // 验证配置是否正确设置
        $configManager = new ConfigManager($this->wechatBot);
        $welcomeMsg = $configManager->getStringConfig('welcome_msg');
        
        $this->assertEquals('@nickname 你好，欢迎你！', $welcomeMsg);
        
        // 设置 friend_welcome 开关为启用
        $this->wechatBot->setMeta('friend_welcome_enabled', true);
        $this->assertTrue($configManager->isWelcomeMessageEnabled());
    }

    public function test_welcome_msg_command_with_spaces_and_special_chars()
    {
        // 测试包含空格和特殊字符的欢迎消息
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set welcome_msg "@nickname 你好，欢迎加入我们的大家庭！🎉"',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        $context->isFromBot = false;
        
        $handler = new SelfMessageHandler();
        $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证包含特殊字符的消息正确保存
        $configManager = new ConfigManager($this->wechatBot);
        $welcomeMsg = $configManager->getStringConfig('welcome_msg');
        
        $this->assertEquals('@nickname 你好，欢迎加入我们的大家庭！🎉', $welcomeMsg);
    }

    public function test_config_command_detection()
    {
        $handler = new SelfMessageHandler();
        
        // 使用反射来测试私有方法
        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('isConfigCommand');
        $method->setAccessible(true);
        
        // 测试各种配置命令格式
        $this->assertTrue($method->invoke($handler, '/set welcome_msg "test"'));
        $this->assertTrue($method->invoke($handler, '/config welcome_msg test'));
        $this->assertTrue($method->invoke($handler, '/get chatwoot'));
        $this->assertTrue($method->invoke($handler, '/sync contacts'));
        $this->assertTrue($method->invoke($handler, '/check online'));
        
        // 测试非配置命令
        $this->assertFalse($method->invoke($handler, 'hello world'));
        $this->assertFalse($method->invoke($handler, '/help'));
        $this->assertFalse($method->invoke($handler, '/set')); // 参数不足
    }
}