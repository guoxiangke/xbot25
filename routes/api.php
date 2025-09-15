<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\WechatBot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


// hack
Route::post('/xbot/login', function (Request $request) {
    Log::warning('XBOT_LOGIN',[$request->all()]);
    //{"secret":"x x x x",
    //"mac_addr":["e4:5d:e8:cc:xx:b2","00:15:54:xx:f0:c6"],
    //"host_name":"DESKTOP-8Dxx2GA"}
    return [
        "err_code" => 0,
        "license" => config('services.xbot.license'),
        "version" => "1.0.7",
        "expired_in"=> 2499184
    ];
});
Route::post('/xbot/heartbeat', function (Request $request) {
    // Log::warning('XBOT_HEARTBEAT',[$request->all()]);
    // {"secret":"x x x x"}
    return [
        "err_code"=> 0,
        "expired_in"=> 2499184
    ];
});
Route::post('/xbot/license/info', function (Request $request) {
    Log::warning('XBOT_LICENSE_INFO',[$request->all()]);
    return [
        "err_code"=> 0,
        "license" => config('services.xbot.license'),
    ];
});

use App\Http\Controllers\Api\XbotWebhookController;
use App\Http\Controllers\Api\WechatApiController;
use App\Http\Controllers\Api\ChatwootWebhookController;

Route::any('/xbot/{winToken}', XbotWebhookController::class);

// {"type":"text", "to":"bluesky_still", "data": {"content": "API主动发送 文本/链接/名片/图片/视频 消息到好友/群"}}
// {"type":"at", "to" :"23896218687@chatroom", "data": {"at":["wxid_xxxxxx","wxid_xxxxxxx"],"content": "{$@}消息到好友/群{$@}"}}
Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('/wechat/send', [WechatApiController::class, 'send']);
    Route::post('/wechat/add', [WechatApiController::class, 'add']);
    Route::get('/wechat/friends', [WechatApiController::class, 'getFriends']);
});

// Chatwoot webhook endpoint
Route::post('/chatwoot/{wechatBot}', [ChatwootWebhookController::class, 'handle']);
