<?php

use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Simplest Factory Test', function () {
    
    test('should create WechatClient only', function () {
        // 只创建 WechatClient，不涉及其他模型
        $wechatClient = WechatClient::factory()->create();
        expect($wechatClient)->toBeInstanceOf(WechatClient::class);
        expect($wechatClient->id)->toBeGreaterThan(0);
    });
    
});