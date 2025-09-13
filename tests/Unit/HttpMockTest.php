<?php

use Illuminate\Support\Facades\Http;

describe('HTTP Mock Test', function () {
    
    test('http mock works correctly', function () {
        Http::fake([
            'localhost:8001/*' => Http::response(['success' => true], 200)
        ]);
        
        Http::post('http://localhost:8001/test', [
            'type' => 'MT_SEND_TEXT_MSG',
            'msg' => 'test message'
        ]);
        
        Http::assertSent(function ($request) {
            $data = $request->data();
            return $data['msg'] === 'test message';
        });
    });
});