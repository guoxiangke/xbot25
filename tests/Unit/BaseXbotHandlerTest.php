<?php

use App\Pipelines\Xbot\BaseXbotHandler;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;
use Tests\Builders\MessageDataBuilder;

describe('BaseXbotHandler', function () {
    
    test('sendTextMessage works correctly', function () {
        Http::fake();
        
        $bot = XbotTestHelpers::createWechatBot();
        $context = XbotTestHelpers::createMessageContext(
            $bot,
            MessageDataBuilder::textMessage()->from('test_user')->to($bot->wxid)->build()
        );
        
        // 创建一个测试用的Handler
        $handler = new class extends BaseXbotHandler {
            public function testSendMessage($context, $message) {
                $this->sendTextMessage($context, $message);
            }
            
            public function handle($context, $next) {
                return $next($context);
            }
        };
        
        $handler->testSendMessage($context, 'Hello World');
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['msg'] === 'Hello World';
        });
    });
});