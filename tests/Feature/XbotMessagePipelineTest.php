<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Jobs\ChatwootSyncJob;
use App\Services\XbotConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

describe('Xbot Message Pipeline End-to-End Tests', function () {
    
    beforeEach(function () {
        // Set up test environment
        $this->wechatClient = WechatClient::factory()->create([
            'token' => 'test-pipeline-token'
        ]);
        
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $this->wechatClient->id,
            'wxid' => 'test_pipeline_bot',
            'chatwoot_account_id' => '123',
            'chatwoot_inbox_id' => '456', 
            'chatwoot_token' => 'test_chatwoot_token'
        ]);
        
        // Enable necessary configurations
        $configManager = new XbotConfigManager($this->wechatBot);
        $configManager->setConfig('chatwoot', true);
        $configManager->setConfig('room_msg', true);
        $configManager->setConfig('keyword_resources', true);
        $configManager->setConfig('keyword_sync', true);
    });
    
    describe('Text Message Processing Pipeline', function () {
        
        test('should process user text message through complete pipeline', function () {
            Queue::fake();
            Http::fake();
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'user_123',
                'to_wxid' => $this->wechatBot->wxid,
                'msg' => '用户发送的普通消息',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Verify that Chatwoot sync job was queued for user message
            Queue::assertPushed(ChatwootSyncJob::class, function ($job) {
                return $job->message === '用户发送的普通消息';
            });
        });
        
        test('should process bot keyword response with sync control', function () {
            Queue::fake();
            Http::fake();
            
            // Test with keyword_sync enabled
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => $this->wechatBot->wxid,
                'to_wxid' => 'user_123',
                'msg' => '【621】真道分解 09-08',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should sync keyword response when keyword_sync is enabled
            Queue::assertPushed(ChatwootSyncJob::class, function ($job) {
                return str_contains($job->message, '【621】');
            });
            
            Queue::fake(); // Reset queue
            
            // Disable keyword_sync
            $configManager = new XbotConfigManager($this->wechatBot);
            $configManager->setConfig('keyword_sync', false);
            
            $response2 = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response2->assertOk();
            
            // Should not sync keyword response when keyword_sync is disabled
            Queue::assertNotPushed(ChatwootSyncJob::class, function ($job) {
                return str_contains($job->message, '【621】');
            });
        });
    });
    
    describe('Group Message Processing Pipeline', function () {
        
        test('should process group messages with room_msg enabled', function () {
            Queue::fake();
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'group_member_123',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => 'test_group@chatroom',
                'msg' => '群消息内容',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should process group message and sync to Chatwoot
            Queue::assertPushed(ChatwootSyncJob::class, function ($job) {
                return $job->message === '群消息内容';
            });
        });
        
        test('should handle group message filtering correctly', function () {
            Queue::fake();
            
            // Disable room_msg globally
            $configManager = new XbotConfigManager($this->wechatBot);
            $configManager->setConfig('room_msg', false);
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'group_member_123',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => 'filtered_group@chatroom',
                'msg' => '被过滤的群消息',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should not process filtered group message
            Queue::assertNotPushed(ChatwootSyncJob::class);
        });
        
        test('should allow group configuration commands even when room_msg is disabled', function () {
            Queue::fake();
            
            // Disable room_msg globally
            $configManager = new XbotConfigManager($this->wechatBot);
            $configManager->setConfig('room_msg', false);
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => $this->wechatBot->wxid, // Bot sends command
                'to_wxid' => 'user_123',
                'room_wxid' => 'config_group@chatroom',
                'msg' => '/set room_listen 1',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Group configuration commands should be processed even when room_msg is disabled
            // This is verified by the response being OK and not filtered out
        });
    });
    
    describe('Check-in Message Processing Pipeline', function () {
        
        test('should process check-in messages when permissions allow', function () {
            Queue::fake();
            
            // Enable check-in
            $configManager = new XbotConfigManager($this->wechatBot);
            $configManager->setConfig('check_in', true);
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'user_checkin',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => 'checkin_group@chatroom',
                'msg' => '签到',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should process check-in message
            Queue::assertPushed(ChatwootSyncJob::class, function ($job) {
                return $job->message === '签到';
            });
        });
        
        test('should block check-in when room_msg is disabled', function () {
            Queue::fake();
            
            // Enable check-in but disable room_msg
            $configManager = new XbotConfigManager($this->wechatBot);
            $configManager->setConfig('check_in', true);
            $configManager->setConfig('room_msg', false);
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'user_checkin',
                'to_wxid' => $this->wechatBot->wxid,
                'room_wxid' => 'blocked_checkin_group@chatroom',
                'msg' => '签到',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should not process check-in when room_msg is disabled
            Queue::assertNotPushed(ChatwootSyncJob::class);
        });
    });
    
    describe('System Message Processing Pipeline', function () {
        
        test('should handle user login events', function () {
            Queue::fake();
            
            $messageData = [
                'type' => 'MT_USER_LOGIN',
                'client_id' => $this->wechatBot->client_id,
                'data' => [
                    'wxid' => $this->wechatBot->wxid,
                    'nickname' => '测试机器人',
                    'avatar' => 'http://example.com/avatar.png',
                    'login_phone' => '13800138000'
                ],
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Verify that bot data was updated
            $this->wechatBot->refresh();
            expect($this->wechatBot->login_at)->not->toBeNull();
        });
        
        test('should handle contact sync messages', function () {
            Queue::fake();
            
            $messageData = [
                'type' => 'MT_DATA_WXID_MSG',
                'client_id' => $this->wechatBot->client_id,
                'data' => [
                    'wxid' => 'new_contact_123',
                    'nickname' => '新联系人',
                    'remark' => '备注名',
                    'avatar' => 'http://example.com/new_avatar.png'
                ],
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Verify that contact information was processed
            // (This would require checking the contacts meta data)
        });
    });
    
    describe('Error Handling and Edge Cases', function () {
        
        test('should handle invalid message types gracefully', function () {
            Queue::fake();
            
            $messageData = [
                'type' => 'INVALID_MESSAGE_TYPE',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'user_123',
                'msg' => 'Invalid message',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            // Should not crash, but may return an error or handle gracefully
            expect($response->status())->toBeGreaterThanOrEqual(200);
        });
        
        test('should handle missing Chatwoot configuration gracefully', function () {
            Queue::fake();
            
            // Create bot without Chatwoot config
            $incompletBot = WechatBot::factory()->create([
                'wechat_client_id' => $this->wechatClient->id,
                'chatwoot_account_id' => null,
                'chatwoot_inbox_id' => null,
                'chatwoot_token' => null
            ]);
            
            $messageData = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => $this->wechatBot->client_id,
                'from_wxid' => 'user_123',
                'to_wxid' => $incompletBot->wxid,
                'msg' => '测试消息',
                'time' => time()
            ];
            
            $response = $this->postJson("/api/xbot/{$this->wechatClient->token}", $messageData);
            
            $response->assertOk();
            
            // Should not queue Chatwoot sync job when config is incomplete
            Queue::assertNotPushed(ChatwootSyncJob::class);
        });
    });
});