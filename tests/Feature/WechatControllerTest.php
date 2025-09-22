<?php

use App\Models\User;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // 创建测试用户
    $this->user = User::factory()->create();
    
    // 创建测试客户端
    $this->wechatClient = WechatClient::factory()->create([
        'token' => 'test-token',
        'endpoint' => 'http://localhost:8001',
        'file_path' => 'C:\\Test\\'
    ]);
    
    // 创建在线的测试机器人
    $this->wechatBot = WechatBot::factory()->create([
        'user_id' => $this->user->id,
        'wechat_client_id' => $this->wechatClient->id,
        'wxid' => 'test_bot_wxid',
        'client_id' => 123,
        'login_at' => now(),
        'is_live_at' => now()
    ]);
    
    // 设置联系人数据
    $this->wechatBot->setMeta('contacts', [
        'friend_wxid_1' => [
            'wxid' => 'friend_wxid_1',
            'nickname' => '好友1',
            'remark' => '备注1',
            'type' => 1 // 好友类型
        ],
        'friend_wxid_2' => [
            'wxid' => 'friend_wxid_2', 
            'nickname' => '好友2',
            'remark' => '备注2',
            'type' => 1
        ],
        'group_wxid@chatroom' => [
            'wxid' => 'group_wxid@chatroom',
            'nickname' => '测试群',
            'remark' => '',
            'type' => 2 // 群聊类型
        ]
    ]);
    
    // Mock XbotClient HTTP 请求
    Http::fake([
        'localhost:8001/*' => Http::response(['success' => true], 200)
    ]);
});

describe('WechatController Authentication', function () {
    
    test('send endpoint requires authentication', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test message']
        ]);
        
        $response->assertStatus(401);
    });
    
    test('add endpoint requires authentication', function () {
        $response = $this->postJson('/api/wechat/add', [
            'telephone' => '13800138000',
            'message' => 'Hello'
        ]);
        
        $response->assertStatus(401);
    });
    
    test('friends endpoint requires authentication', function () {
        $response = $this->getJson('/api/wechat/friends');
        
        $response->assertStatus(401);
    });
});

describe('WechatController Send Message', function () {
    
    beforeEach(function () {
        Sanctum::actingAs($this->user);
    });
    
    test('send text message successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'Hello friend']
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '已提交设备发送'
        ]);
        
        // 验证HTTP请求被发送到XbotClient
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['type'] === 'MT_SEND_TEXTMSG'
                && $data['data']['to_wxid'] === 'friend_wxid_1'
                && $data['data']['content'] === 'Hello friend';
        });
    });
    
    test('send at message successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'at',
            'to' => 'group_wxid@chatroom',
            'data' => [
                'content' => '{$@}大家好{$@}',
                'at' => ['member1', 'member2']
            ]
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '已提交设备发送'
        ]);
        
        // 验证至少发送了一个HTTP请求
        Http::assertSentCount(1);
    });
    
    test('send link message successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'link',
            'to' => 'friend_wxid_1',
            'data' => [
                'url' => 'https://example.com',
                'title' => 'Example Site',
                'description' => 'This is an example website',
                'image' => 'https://example.com/image.jpg'
            ]
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '已提交设备发送'
        ]);
        
        Http::assertSentCount(1);
    });
    
    test('send contact card successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'card',
            'to' => 'friend_wxid_1',
            'data' => [
                'wxid' => 'shared_contact_wxid'
            ]
        ]);
        
        $response->assertOk();
        
        Http::assertSentCount(1);
    });
    
    test('send image message successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'image',
            'to' => 'friend_wxid_1',
            'data' => [
                'url' => 'https://example.com/image.jpg'
            ]
        ]);
        
        $response->assertOk();
        
        Http::assertSentCount(1);
    });
    
    test('send message with addition successfully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'First message'],
            'addition' => [
                'type' => 'link',
                'data' => [
                    'url' => 'https://example.com',
                    'title' => 'Additional Link'
                ]
            ]
        ]);
        
        $response->assertOk();
        
        // 验证发送了两条消息：文本 + 链接
        Http::assertSentCount(2);
    });
    
    test('fails when user not bound to device', function () {
        // 创建一个没有绑定设备的用户
        $unboundUser = User::factory()->create();
        Sanctum::actingAs($unboundUser);
        
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test']
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => false,
            'message' => '设备不在线,或改用户未绑定设备',
            'code' => 400
        ]);
    });
    
    test('fails when device is offline', function () {
        // 设置设备为离线状态
        $this->wechatBot->update([
            'client_id' => null,
            'is_live_at' => null
        ]);
        
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test']
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => false,
            'message' => '设备不在线,或改用户未绑定设备',
            'code' => 400
        ]);
    });
});

describe('WechatController Add Friend', function () {
    
    beforeEach(function () {
        Sanctum::actingAs($this->user);
    });
    
    test('add friend successfully', function () {
        $response = $this->postJson('/api/wechat/add', [
            'telephone' => '13800138000',
            'message' => 'Hello, nice to meet you'
        ]);
        
        $response->assertOk();
        
        // 验证发送了HTTP请求（搜索联系人和添加好友）
        Http::assertSentCount(2);
    });
    
    test('add friend with default message', function () {
        $response = $this->postJson('/api/wechat/add', [
            'telephone' => '13800138000'
        ]);
        
        $response->assertOk();
        
        // 验证发送了HTTP请求（搜索联系人和添加好友）
        Http::assertSentCount(2);
    });
    
    test('fails when device is offline', function () {
        $this->wechatBot->update([
            'client_id' => null,
            'login_at' => null
        ]);
        
        $response = $this->postJson('/api/wechat/add', [
            'telephone' => '13800138000'
        ]);
        
        $response->assertOk();
        $response->assertJson([
            'success' => false,
            'message' => '设备不在线',
            'code' => 400
        ]);
    });
});

describe('WechatController Get Friends', function () {
    
    beforeEach(function () {
        Sanctum::actingAs($this->user);
    });
    
    test('get friends list successfully', function () {
        $response = $this->getJson('/api/wechat/friends');
        
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '获取好友列表成功'
        ]);
        
        $data = $response->json('data');
        
        // 验证只返回好友（type=1），不包含群聊
        expect($data)->toHaveCount(2);
        expect($data[0]['wxid'])->toBe('friend_wxid_1');
        expect($data[1]['wxid'])->toBe('friend_wxid_2');
        
        // 验证不包含群聊
        $groupExists = collect($data)->contains('wxid', 'group_wxid@chatroom');
        expect($groupExists)->toBeFalse();
    });
    
    test('get friends when no friends exist', function () {
        // 清空联系人数据
        $this->wechatBot->setMeta('contacts', []);
        
        $response = $this->getJson('/api/wechat/friends');
        
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '获取好友列表成功',
            'data' => []
        ]);
    });
    
    test('fails when user not bound to device', function () {
        $unboundUser = User::factory()->create();
        Sanctum::actingAs($unboundUser);
        
        $response = $this->getJson('/api/wechat/friends');
        
        $response->assertOk();
        $response->assertJson([
            'success' => false,
            'message' => '用户未绑定设备',
            'code' => 400
        ]);
    });
});

describe('WechatController Validation', function () {
    
    beforeEach(function () {
        Sanctum::actingAs($this->user);
    });
    
    test('validates required fields for text message', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1'
            // 缺少 data.content
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.content']);
    });
    
    test('validates message type', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'invalid_type',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test']
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
    });
    
    test('validates link message fields', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'link',
            'to' => 'friend_wxid_1',
            'data' => [
                'url' => 'invalid-url',
                'title' => str_repeat('a', 300) // 超过长度限制
            ]
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.url', 'data.title']);
    });
    
    test('validates at message fields', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'at',
            'to' => 'group_wxid@chatroom',
            'data' => [
                'content' => 'hello',
                'at' => 'invalid_format' // 应该是数组
            ]
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['data.at']);
    });
    
    test('validates add friend request', function () {
        $response = $this->postJson('/api/wechat/add', [
            'telephone' => 'invalid-phone',
            'message' => str_repeat('a', 300) // 超过长度限制
        ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['telephone', 'message']);
    });
});

describe('WechatController Error Handling', function () {
    
    beforeEach(function () {
        Sanctum::actingAs($this->user);
    });
    
    test('handles xbot client request failure', function () {
        // Mock XbotClient 请求失败
        Http::fake([
            'localhost:8001/*' => Http::response(['error' => 'Connection failed'], 500)
        ]);
        
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'text',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test']
        ]);
        
        // 当前实现不处理HTTP错误，仍然返回成功
        // 这是一个可以改进的地方
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => '已提交设备发送'
        ]);
    });
    
    test('handles invalid message type gracefully', function () {
        $response = $this->postJson('/api/wechat/send', [
            'type' => 'invalid_type',
            'to' => 'friend_wxid_1',
            'data' => ['content' => 'test']
        ]);
        
        // 验证逻辑会拒绝无效的消息类型
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['type']);
        
        // 验证没有HTTP请求被发送
        Http::assertNothingSent();
    });
});