<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Simple Route Test', function () {
    
    test('should access xbot route correctly', function () {
        $wechatClient = WechatClient::factory()->create([
            'token' => 'test-token-123'
        ]);
        
        $wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $wechatClient->id,
        ]);
        
        $messageData = [
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => $wechatBot->client_id,
            'from_wxid' => 'user_123',
            'to_wxid' => $wechatBot->wxid,
            'msg' => '测试消息',
            'time' => time()
        ];
        
        $response = $this->postJson("/api/xbot/{$wechatClient->token}", $messageData);
        
        // Log the response for debugging
        dump($response->status(), $response->content());
        
        if ($response->status() !== 200) {
            dump('Request data:', $messageData);
        }
        
        $response->assertOk();
    });
    
});