<?php

use App\Models\XbotSubscription;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('XbotSubscription Feature Tests', function () {
    
    describe('Subscription Management', function () {
        
        test('should create subscription using factory', function () {
            $wechatClient = WechatClient::factory()->create();
            $wechatBot = WechatBot::factory()->create([
                'wechat_client_id' => $wechatClient->id
            ]);
            
            // Create subscription using factory
            $subscription = XbotSubscription::factory()
                ->withKeyword('621')
                ->dailyMorning()
                ->forGroup()
                ->create([
                    'wechat_bot_id' => $wechatBot->id
                ]);
            
            expect($subscription)->toBeInstanceOf(XbotSubscription::class);
            expect($subscription->keyword)->toBe('621');
            expect($subscription->cron)->toBe('0 7 * * *');
            expect($subscription->wxid)->toMatch('/@chatroom$/');
            expect($subscription->wechat_bot_id)->toBe($wechatBot->id);
        });
        
        test('should create subscription for personal contact', function () {
            $wechatBot = WechatBot::factory()->create();
            
            $subscription = XbotSubscription::factory()
                ->forContact()
                ->dailyEvening()
                ->withKeyword('新闻')
                ->create([
                    'wechat_bot_id' => $wechatBot->id
                ]);
            
            expect($subscription->wxid)->toMatch('/^wxid_/');
            expect($subscription->cron)->toBe('0 19 * * *');
            expect($subscription->keyword)->toBe('新闻');
        });
        
        test('should create multiple subscriptions using factory', function () {
            $wechatBot = WechatBot::factory()->create();
            
            $subscriptions = XbotSubscription::factory()
                ->count(5)
                ->forGroup()
                ->create([
                    'wechat_bot_id' => $wechatBot->id
                ]);
            
            expect($subscriptions)->toHaveCount(5);
            foreach ($subscriptions as $subscription) {
                expect($subscription->wxid)->toMatch('/@chatroom$/');
                expect($subscription->wechat_bot_id)->toBe($wechatBot->id);
            }
        });
    });
    
    describe('Subscription Business Logic', function () {
        
        test('should find subscription by bot and wxid', function () {
            $wechatBot = WechatBot::factory()->create();
            $targetWxid = 'test_room@chatroom';
            $keyword = '621';
            
            // Create subscription using factory
            XbotSubscription::factory()->create([
                'wechat_bot_id' => $wechatBot->id,
                'wxid' => $targetWxid,
                'keyword' => $keyword
            ]);
            
            $found = XbotSubscription::findByBotAndWxid($wechatBot->id, $targetWxid, $keyword);
            
            expect($found)->not->toBeNull();
            expect($found->wxid)->toBe($targetWxid);
            expect($found->keyword)->toBe($keyword);
        });
        
        test('should create or restore subscription using factory setup', function () {
            $wechatBot = WechatBot::factory()->create();
            $targetWxid = 'restore_test@chatroom';
            $keyword = 'test_keyword';
            
            // First creation
            $subscription1 = XbotSubscription::createOrRestore(
                $wechatBot->id,
                $targetWxid,
                $keyword,
                '0 8 * * *'
            );
            
            expect($subscription1)->not->toBeNull();
            expect($subscription1->cron)->toBe('0 8 * * *');
            
            // Soft delete
            $subscription1->delete();
            expect($subscription1->trashed())->toBeTrue();
            
            // Restore
            $subscription2 = XbotSubscription::createOrRestore(
                $wechatBot->id,
                $targetWxid,
                $keyword,
                '0 9 * * *'  // Different cron, but should restore existing
            );
            
            expect($subscription2->id)->toBe($subscription1->id);
            expect($subscription2->trashed())->toBeFalse();
        });
        
        test('should handle soft deletes correctly', function () {
            $subscription = XbotSubscription::factory()->create();
            
            // Verify it exists
            expect(XbotSubscription::find($subscription->id))->not->toBeNull();
            
            // Soft delete
            $subscription->delete();
            
            // Should not be found in regular queries
            expect(XbotSubscription::find($subscription->id))->toBeNull();
            
            // Should be found with trashed
            $trashedSubscription = XbotSubscription::withTrashed()->find($subscription->id);
            expect($trashedSubscription)->not->toBeNull();
            expect($trashedSubscription->trashed())->toBeTrue();
        });
    });
    
    describe('Subscription Relationships', function () {
        
        test('should have correct relationship with wechat bot', function () {
            $wechatBot = WechatBot::factory()->create();
            $subscription = XbotSubscription::factory()->create([
                'wechat_bot_id' => $wechatBot->id
            ]);
            
            // Test relationship
            $relatedBot = $subscription->wechatBot;
            expect($relatedBot)->not->toBeNull();
            expect($relatedBot->id)->toBe($wechatBot->id);
            expect($relatedBot->wxid)->toBe($wechatBot->wxid);
        });
        
        test('should handle factory with relationship', function () {
            // Create subscription with related bot
            $subscription = XbotSubscription::factory()
                ->for(WechatBot::factory(), 'wechatBot')
                ->create();
            
            expect($subscription->wechatBot)->not->toBeNull();
            expect($subscription->wechat_bot_id)->toBe($subscription->wechatBot->id);
        });
    });
    
    describe('Factory State Combinations', function () {
        
        test('should support chained factory states', function () {
            $subscriptions = XbotSubscription::factory()
                ->count(3)
                ->forGroup()
                ->dailyMorning()
                ->withKeyword('test')
                ->create();
            
            foreach ($subscriptions as $subscription) {
                expect($subscription->wxid)->toMatch('/@chatroom$/');
                expect($subscription->cron)->toBe('0 7 * * *');
                expect($subscription->keyword)->toBe('test');
            }
        });
        
        test('should create different subscription types', function () {
            $bot = WechatBot::factory()->create();
            
            $groupSub = XbotSubscription::factory()
                ->forGroup()
                ->dailyMorning()
                ->create(['wechat_bot_id' => $bot->id]);
            
            $contactSub = XbotSubscription::factory()
                ->forContact()
                ->dailyEvening()
                ->create(['wechat_bot_id' => $bot->id]);
            
            expect($groupSub->wxid)->toMatch('/@chatroom$/');
            expect($groupSub->cron)->toBe('0 7 * * *');
            
            expect($contactSub->wxid)->toMatch('/^wxid_/');
            expect($contactSub->cron)->toBe('0 19 * * *');
        });
    });
});