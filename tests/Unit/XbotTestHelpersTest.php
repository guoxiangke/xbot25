<?php

use Tests\Support\XbotTestHelpers;

describe('XbotTestHelpers', function () {
    
    test('can create wechat client', function () {
        $client = XbotTestHelpers::createWechatClient();
        
        expect($client)->not->toBeNull();
        expect($client->token)->toStartWith('test-token-');
        expect($client->endpoint)->toBe('http://localhost:8001');
    });
    
    test('can create wechat bot', function () {
        $bot = XbotTestHelpers::createWechatBot();
        
        expect($bot)->not->toBeNull();
        expect($bot->wxid)->toStartWith('test-bot-');
        
        // 测试meta功能
        $bot->setMeta('test_key', 'test_value');
        expect($bot->getMeta('test_key'))->toBe('test_value');
    });
    
    test('can create message context', function () {
        $bot = XbotTestHelpers::createWechatBot();
        $context = XbotTestHelpers::createMessageContext($bot);
        
        expect($context)->not->toBeNull();
        expect($context->wechatBot)->toBe($bot);
        expect($context->msgType)->toBe('MT_RECV_TEXT_MSG');
    });
});