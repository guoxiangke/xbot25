<?php

use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

describe('TestXbot Integration', function () {
    
    test('test xbot can send message', function () {
        Http::fake();
        
        $bot = XbotTestHelpers::createWechatBot();
        $xbot = $bot->xbot();
        
        $xbot->sendTextMessage('test_target', 'test message');
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return XbotTestHelpers::extractMessageContent($data) === 'test message' && 
                   $data['data']['to_wxid'] === 'test_target';
        });
    });
});