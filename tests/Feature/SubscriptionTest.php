<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Models\XbotSubscription;

test('subscription creation and recovery', function () {
    // 创建必要的测试数据
    $wechatClient = WechatClient::create([
        'token' => 'test_token',
        'endpoint' => 'http://localhost:8001',
        'file_path' => 'C:\test',
        'file_url' => 'http://localhost:8004',
        'voice_url' => 'http://localhost:8003',
        'silk_path' => 'C:\temp',
    ]);

    $wechatBot = WechatBot::create([
        'wxid' => 'test_bot_wxid',
        'wechat_client_id' => $wechatClient->id,
        'client_id' => 1,
    ]);

    $testWxid = 'test_user_wxid';
    $testKeyword = 'test_keyword';

    // 测试创建订阅
    $subscription = XbotSubscription::createOrRestore(
        $wechatBot->id,
        $testWxid,
        $testKeyword,
        '0 7 * * *'
    );

    expect($subscription)->not->toBeNull();
    expect($subscription->wechat_bot_id)->toBe($wechatBot->id);
    expect($subscription->wxid)->toBe($testWxid);
    expect($subscription->keyword)->toBe($testKeyword);
    expect($subscription->cron)->toBe('0 7 * * *');
    expect($subscription->wasRecentlyCreated)->toBeTrue();

    // 测试软删除
    $subscription->delete();
    expect($subscription->trashed())->toBeTrue();

    // 测试恢复订阅
    $restoredSubscription = XbotSubscription::createOrRestore(
        $wechatBot->id,
        $testWxid,
        $testKeyword,
        '0 8 * * *'  // 不同的时间，但应该使用原来的记录
    );

    expect($restoredSubscription->id)->toBe($subscription->id);
    expect($restoredSubscription->trashed())->toBeFalse();
    expect($restoredSubscription->wasRecentlyCreated)->toBeFalse();
});

test('subscription relationships', function () {
    // 创建必要的测试数据
    $wechatClient = WechatClient::create([
        'token' => 'test_token',
        'endpoint' => 'http://localhost:8001',
        'file_path' => 'C:\test',
        'file_url' => 'http://localhost:8004',
        'voice_url' => 'http://localhost:8003',
        'silk_path' => 'C:\temp',
    ]);

    $wechatBot = WechatBot::create([
        'wxid' => 'test_bot_wxid',
        'wechat_client_id' => $wechatClient->id,
        'client_id' => 1,
    ]);

    $subscription = XbotSubscription::create([
        'wechat_bot_id' => $wechatBot->id,
        'wxid' => 'test_user_wxid',
        'keyword' => 'test_keyword',
        'cron' => '0 7 * * *',
    ]);

    // 测试关联关系
    expect($subscription->wechatBot->id)->toBe($wechatBot->id);
    expect($wechatBot->subscriptions->contains($subscription))->toBeTrue();
});

test('find subscription methods', function () {
    // 创建必要的测试数据
    $wechatClient = WechatClient::create([
        'token' => 'test_token',
        'endpoint' => 'http://localhost:8001',
        'file_path' => 'C:\test',
        'file_url' => 'http://localhost:8004',
        'voice_url' => 'http://localhost:8003',
        'silk_path' => 'C:\temp',
    ]);

    $wechatBot = WechatBot::create([
        'wxid' => 'test_bot_wxid',
        'wechat_client_id' => $wechatClient->id,
        'client_id' => 1,
    ]);

    $testWxid = 'test_user_wxid';
    $testKeyword = 'test_keyword';

    // 创建订阅
    $subscription = XbotSubscription::create([
        'wechat_bot_id' => $wechatBot->id,
        'wxid' => $testWxid,
        'keyword' => $testKeyword,
        'cron' => '0 7 * * *',
    ]);

    // 测试查找方法
    $found = XbotSubscription::findByBotAndWxid($wechatBot->id, $testWxid, $testKeyword);
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($subscription->id);

    // 软删除后测试
    $subscription->delete();

    $foundDeleted = XbotSubscription::findByBotAndWxid($wechatBot->id, $testWxid, $testKeyword);
    expect($foundDeleted)->toBeNull();

    $foundWithTrashed = XbotSubscription::findByBotAndWxidWithTrashed($wechatBot->id, $testWxid, $testKeyword);
    expect($foundWithTrashed)->not->toBeNull();
    expect($foundWithTrashed->id)->toBe($subscription->id);
});
