<?php

use App\Models\XbotSubscription;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Factory Basic Test', function () {
    
    test('should create basic models using factories', function () {
        // 创建 WechatClient
        $wechatClient = WechatClient::factory()->create();
        expect($wechatClient)->toBeInstanceOf(WechatClient::class);
        
        // 创建 WechatBot
        $wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $wechatClient->id
        ]);
        expect($wechatBot)->toBeInstanceOf(WechatBot::class);
        
        // 创建 XbotSubscription
        $subscription = XbotSubscription::factory()->create([
            'wechat_bot_id' => $wechatBot->id
        ]);
        expect($subscription)->toBeInstanceOf(XbotSubscription::class);
        expect($subscription->wechat_bot_id)->toBe($wechatBot->id);
    });
    
    test('should use factory states', function () {
        $wechatClient = WechatClient::factory()->create();
        $wechatBot = WechatBot::factory()->create(['wechat_client_id' => $wechatClient->id]);
        
        // 测试群组订阅状态
        $groupSubscription = XbotSubscription::factory()
            ->forGroup()
            ->create(['wechat_bot_id' => $wechatBot->id]);
        
        expect($groupSubscription->wxid)->toContain('@chatroom');
        
        // 测试联系人订阅状态
        $contactSubscription = XbotSubscription::factory()
            ->forContact()
            ->create(['wechat_bot_id' => $wechatBot->id]);
        
        expect($contactSubscription->wxid)->not->toContain('@chatroom');
    });
});