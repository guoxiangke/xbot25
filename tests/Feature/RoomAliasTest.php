<?php

namespace Tests\Feature;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use App\Pipelines\Xbot\Message\RoomAliasHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoomAliasTest extends TestCase
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
        
        // Mock HTTP 请求 - 统一使用邀请请求方式
        Http::fake([
            '*' => Http::response(['success' => true], 200),
        ]);
    }

    public function test_set_room_alias_command_in_group_chat()
    {
        // 模拟在群聊中设置别名
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set room_alias 1234',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => $this->testRoomWxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是群聊
        $this->assertTrue($context->isRoom);
        $this->assertEquals($this->testRoomWxid, $context->roomWxid);
        
        // 模拟机器人发送的配置消息（管理员通过机器人发送）
        $context->isFromBot = true;
        
        $handler = new SelfMessageHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context; // 模拟next()调用
        });
        
        // 验证配置是否正确设置
        $configManager = new ConfigManager($this->wechatBot);
        $roomAlias = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
        
        $this->assertEquals('1234', $roomAlias);
        
        // 验证HTTP请求被发送（回复消息）
        Http::assertSentCount(1);
    }

    public function test_set_room_alias_fails_in_private_chat()
    {
        // 模拟在私聊中设置别名（应该失败）
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set room_alias 1234',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是私聊
        $this->assertFalse($context->isRoom);
        
        $context->isFromBot = true;
        
        $handler = new SelfMessageHandler();
        $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证配置没有被设置
        $configManager = new ConfigManager($this->wechatBot);
        $roomAlias = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
        
        $this->assertNull($roomAlias);
        
        // 验证错误消息被发送 - 检查正确的API调用格式
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '群邀请别名只能在群聊中设置');
        });
    }

    public function test_room_alias_validates_format()
    {
        // 测试无效格式的别名
        $invalidAliases = ['12@3', 'abc!', '中文', 'test space', ''];
        
        foreach ($invalidAliases as $alias) {
            $context = new XbotMessageContext(
                wechatBot: $this->wechatBot,
                requestRawData: [
                    'msg' => "/set room_alias {$alias}",
                    'from_wxid' => 'user123',
                    'to_wxid' => $this->wechatBot->wxid,
                    'room_wxid' => $this->testRoomWxid,
                    'msgid' => '123456789'
                ],
                msgType: 'MT_RECV_TEXT_MSG',
                clientId: 123456
            );
            
            $context->isFromBot = true;
            
            $handler = new SelfMessageHandler();
            $handler->handle($context, function($context) {
                return $context;
            });
            
            // 验证配置没有被设置
            $configManager = new ConfigManager($this->wechatBot);
            $roomAlias = $configManager->getGroupConfig('room_alias', $this->testRoomWxid);
            
            $this->assertNull($roomAlias, "Alias '{$alias}' should be rejected");
        }
    }

    public function test_room_alias_handler_matches_and_invites()
    {
        // 先设置群别名
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
        
        // 确保这是私聊
        $this->assertFalse($context->isRoom);
        $context->isFromBot = false;
        
        $handler = new RoomAliasHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证消息被标记为已处理
        $this->assertTrue($context->isProcessed());
        
        // 验证群邀请请求API被调用 - 统一使用邀请请求方式
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $requestData = $data['data'] ?? [];
            return $type === 'MT_INVITE_TO_ROOM_REQ_MSG' &&
                   isset($requestData['room_wxid']) && $requestData['room_wxid'] === $this->testRoomWxid &&
                   isset($requestData['member_list']) && in_array('user123', $requestData['member_list']);
        });
        
        // 验证欢迎消息被发送（默认模板）
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '@测试用户，您好，欢迎加入【测试群聊】群👏');
        });
    }

    public function test_room_alias_handler_ignores_non_matching_messages()
    {
        // 设置群别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 测试不匹配的消息类型
        $nonMatchingMessages = [
            'hello world',      // 包含空格
            '123!',             // 包含特殊字符
            '',                 // 空消息
            '5678',             // 不匹配的别名
        ];
        
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
            
            $context->isFromBot = true;
            
            $handler = new RoomAliasHandler();
            $result = $handler->handle($context, function($context) {
                return $context;
            });
            
            // 验证消息没有被处理
            $this->assertFalse($context->isProcessed(), "Message '{$msg}' should not be processed");
        }
    }

    public function test_room_alias_prevents_duplicate_aliases()
    {
        // 在第一个群设置别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 创建第二个群
        $secondRoomWxid = '98765432109876543210@chatroom';
        $contacts = $this->wechatBot->getMeta('contacts', []);
        $contacts[$secondRoomWxid] = [
            'wxid' => $secondRoomWxid,
            'nickname' => '第二个群',
            'remark' => '',
        ];
        $this->wechatBot->setMeta('contacts', $contacts);
        
        // 尝试在第二个群设置相同别名
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set room_alias 1234',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => $secondRoomWxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        $context->isFromBot = true;
        
        $handler = new SelfMessageHandler();
        $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证第二个群的别名没有被设置
        $secondRoomAlias = $configManager->getGroupConfig('room_alias', $secondRoomWxid);
        $this->assertNull($secondRoomAlias);
        
        // 验证错误消息被发送
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '别名') && 
                   str_contains($content, '已被其他群使用');
        });
    }

    public function test_room_alias_handler_uses_custom_welcome_message()
    {
        // 先设置群别名
        $configManager = new ConfigManager($this->wechatBot);
        $configManager->setGroupConfig('room_alias', '1234', $this->testRoomWxid);
        
        // 设置自定义欢迎消息
        $customWelcomeMsg = "你好，@nickname 欢迎加入【xx】群，这里很棒哦！";
        // 模拟通过数组方式存储
        $roomWelcomeMsgs = [$this->testRoomWxid => $customWelcomeMsg];
        $configManager->setGroupConfig('room_welcome_msgs', $roomWelcomeMsgs, $this->testRoomWxid);
        
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
        $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证消息被标记为已处理
        $this->assertTrue($context->isProcessed());
        
        // 验证群邀请请求API被调用
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $requestData = $data['data'] ?? [];
            return $type === 'MT_INVITE_TO_ROOM_REQ_MSG' &&
                   isset($requestData['room_wxid']) && $requestData['room_wxid'] === $this->testRoomWxid &&
                   isset($requestData['member_list']) && in_array('user123', $requestData['member_list']);
        });
        
        // 验证自定义欢迎消息被发送（变量已替换）
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '你好，@测试用户 欢迎加入【测试群聊】群，这里很棒哦！');
        });
    }

    public function test_set_welcome_msg_in_group_chat_sets_room_welcome()
    {
        // 模拟在群聊中设置欢迎消息
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set welcome_msg "你好，@nickname 欢迎加入【xx】群，请多指教！"',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => $this->testRoomWxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是群聊
        $this->assertTrue($context->isRoom);
        $this->assertEquals($this->testRoomWxid, $context->roomWxid);
        
        // 模拟机器人发送的配置消息（管理员通过机器人发送）
        $context->isFromBot = true;
        
        $handler = new SelfMessageHandler();
        
        // 测试处理逻辑
        $result = $handler->handle($context, function($context) {
            return $context; // 模拟next()调用
        });
        
        // 验证配置是否正确设置
        $configManager = new ConfigManager($this->wechatBot);
        $roomWelcomeMsg = $configManager->getGroupConfig('room_welcome_msgs', $this->testRoomWxid);
        
        $this->assertEquals('你好，@nickname 欢迎加入【xx】群，请多指教！', $roomWelcomeMsg);
        
        // 验证HTTP请求被发送（回复消息）
        Http::assertSentCount(1);
        
        // 验证成功消息被发送
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '群新成员欢迎消息设置成功') && 
                   str_contains($content, '@nickname');
        });
    }

    public function test_set_welcome_msg_in_private_chat_sets_friend_welcome()
    {
        // 模拟在私聊中设置欢迎消息（应该设置好友欢迎消息）
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: [
                'msg' => '/set welcome_msg "你好@nickname，欢迎成为我的好友！"',
                'from_wxid' => 'user123',
                'to_wxid' => $this->wechatBot->wxid,
                'msgid' => '123456789'
            ],
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 123456
        );
        
        // 确保这是私聊
        $this->assertFalse($context->isRoom);
        
        $context->isFromBot = true;
        
        $handler = new SelfMessageHandler();
        $handler->handle($context, function($context) {
            return $context;
        });
        
        // 验证好友欢迎消息被设置（系统级配置）
        $configManager = new ConfigManager($this->wechatBot);
        $friendWelcomeMsg = $configManager->getStringConfig('welcome_msg');
        
        $this->assertEquals('你好@nickname，欢迎成为我的好友！', $friendWelcomeMsg);
        
        // 验证群级别配置没有被设置
        $roomWelcomeMsg = $configManager->getGroupConfig('room_welcome_msgs', $this->testRoomWxid);
        $this->assertNull($roomWelcomeMsg);
        
        // 验证成功消息被发送
        Http::assertSent(function ($request) {
            $data = $request->data();
            $type = $data['type'] ?? '';
            $content = $data['data']['content'] ?? '';
            return $type === 'MT_SEND_TEXTMSG' && 
                   str_contains($content, '好友欢迎消息设置成功');
        });
    }

}