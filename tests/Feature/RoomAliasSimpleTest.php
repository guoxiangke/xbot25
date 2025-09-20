<?php

namespace Tests\Feature;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\Message\RoomAliasHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomAliasSimpleTest extends TestCase
{
    use RefreshDatabase;

    protected WechatBot $wechatBot;
    protected string $testRoomWxid = '12345678901234567890@chatroom';
    
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
        
        // 模拟联系人数据（包含群聊）
        $contacts = [
            $this->testRoomWxid => [
                'wxid' => $this->testRoomWxid,
                'nickname' => '测试群聊',
                'remark' => '',
            ],
            'user123' => [
                'wxid' => 'user123',
                'nickname' => '测试用户',
                'remark' => '',
            ],
        ];
        $this->wechatBot->setMeta('contacts', $contacts);
    }

    public function test_room_alias_handler_finds_room_by_alias()
    {
        // 设置群别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 验证别名被正确保存
        $savedAlias = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
        $this->assertEquals('1234', $savedAlias);
        
        // 模拟用户在私聊中发送别名
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '1234',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是私聊
        $this->assertFalse($context->isRoom);
        $context->isFromBot = false;
        
        $handler = new RoomAliasHandler();
        
        // 使用反射调用私有方法进行测试
        $reflection = new \ReflectionClass($handler);
        $findMethod = $reflection->getMethod('findRoomByAlias');
        $findMethod->setAccessible(true);
        
        $foundRoomWxid = $findMethod->invoke($handler, $this->wechatBot, '1234');
        
        // 验证找到了正确的群
        $this->assertEquals($this->testRoomWxid, $foundRoomWxid);
        
        // 测试不匹配的别名
        $notFoundRoom = $findMethod->invoke($handler, $this->wechatBot, '5678');
        $this->assertNull($notFoundRoom);
    }

    public function test_room_alias_handler_processes_matching_message()
    {
        // 设置群别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 模拟用户在私聊中发送别名
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '1234',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        $context->isFromBot = false;
        
        $handler = new RoomAliasHandler();
        
        // 模拟处理流程
        $processedContext = $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证消息被标记为已处理
        $this->assertTrue($processedContext->isProcessed());
    }

    public function test_room_alias_handler_ignores_non_matching_messages()
    {
        // 设置群别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 测试各种不应该匹配的消息
        $nonMatchingMessages = [
            'hello',           // 不匹配的文本
            '5678',           // 不匹配的别名
            'test message',   // 包含空格
            '123!',           // 包含特殊字符
        ];
        
        $handler = new RoomAliasHandler();
        
        foreach ($nonMatchingMessages as $msg) {
            $context = new XbotMessageContext(
                wechatBot: $this->wechatBot,
                requestRawData: [
                    'msg' => $msg,
                    'from_wxid' => 'user123',
                    'to_wxid' => $this->wechatBot->wxid,
                    'msgid' => '123456789'
                ],
                msgType: 'MT_RECV_TEXT_MSG',
                clientId: 123456
            );
            
            $context->isFromBot = false;
            
            $processedContext = $handler->handle($context, function($context) {
                return $context;
            });
            
            // 验证消息没有被处理（除了匹配的 "1234"）
            if ($msg === '1234') {
                $this->assertTrue($processedContext->isProcessed(), "Message '{$msg}' should be processed");
            } else {
                $this->assertFalse($processedContext->isProcessed(), "Message '{$msg}' should not be processed");
            }
        }
    }

    public function test_config_manager_group_config_operations()
    {
        $configManager = new ConfigManager($this->wechatBot);
        
        // 测试设置和获取群配置
        $configManager->setGroupConfig('room_alias', 'test123', $this->testRoomWxid);
        $alias = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
        
        $this->assertEquals('test123', $alias);
        
        // 测试不存在的配置
        $nonExistentAlias = $configManager->getGroupConfig('room_alias', 'nonexistent@chatroom');
        $this->assertNull($nonExistentAlias);
        
        // 测试获取所有群配置
        $allConfigs = $configManager->getAllGroupConfigs($this->testRoomWxid);
        $this->assertEquals('test123', $allConfigs['room_alias']);
    }

    public function test_get_room_alias_command_with_no_aliases()
    {
        // 模拟机器人自己发送 /get room_alias 命令
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/get room_alias',
                'from_wxid' => $this->wechatBot->wxid,  // 机器人发给自己
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是机器人发送的消息
        $this->assertTrue($context->isFromBot);
        
        $handler = new \App\Pipelines\Xbot\Message\SelfMessageHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context; // 模拟next()调用
        });
        
        // 验证消息被标记为已处理
        $this->assertTrue($context->isProcessed());
        
        // 这里我们只能通过日志或其他方式验证，因为sendTextMessage会调用HTTP
        // 在实际测试中，我们会Mock HTTP调用来验证发送的消息内容
    }

    public function test_get_room_alias_command_with_configured_aliases()
    {
        // 设置多个群的别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 添加第二个群
        $secondRoomWxid = '98765432109876543210@chatroom';
        $contacts = $this->wechatBot->getMeta('contacts', []);
        $contacts[$secondRoomWxid] = [
            'wxid' => $secondRoomWxid,
            'nickname' => '第二个测试群',
            'remark' => '',
        ];
        $this->wechatBot->setMeta('contacts', $contacts);
        $configManager->setGroupConfig('room_alias', '5678', $secondRoomWxid);
        
        // 模拟机器人自己发送 /get room_alias 命令
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/get room_alias',
                'from_wxid' => $this->wechatBot->wxid,
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        $context->isFromBot = true;
        
        $handler = new \App\Pipelines\Xbot\Message\SelfMessageHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证消息被标记为已处理
        $this->assertTrue($context->isProcessed());
        
        // 验证配置确实存在
        $alias1 = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
        $alias2 = $configManager->getGroupConfig('room_alias', $secondRoomWxid);
        
        $this->assertEquals('1234', $alias1);
        $this->assertEquals('5678', $alias2);
    }

    public function test_is_config_command_recognizes_get_room_alias()
    {
        $handler = new \App\Pipelines\Xbot\Message\SelfMessageHandler();
        
        // 使用反射来测试私有方法
        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('isConfigCommand');
        $method->setAccessible(true);
        
        // 测试 /get room_alias 命令识别
        $this->assertTrue($method->invoke($handler, '/get room_alias'));
        $this->assertTrue($method->invoke($handler, '/GET room_alias')); // 大小写测试
        
        // 测试其他配置命令仍然有效
        $this->assertTrue($method->invoke($handler, '/get chatwoot'));
        $this->assertTrue($method->invoke($handler, '/sync contacts'));
        
        // 测试非配置命令
        $this->assertFalse($method->invoke($handler, 'hello world'));
        $this->assertFalse($method->invoke($handler, '/help'));
    }
}