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

use App\Http\Controllers\XbotController;
use App\Http\Controllers\WechatController;
use App\Http\Controllers\ChatwootController;

Route::any('/xbot/{winToken}', XbotController::class);

/**
 * WeChat API 端点
 * 需要 auth:sanctum 身份验证
 * 
 * 支持的消息类型示例：
 * 
 * 1. 文本消息:
 * {"type":"text", "to":"friend_wxid", "data": {"content": "Hello World"}}
 * 
 * 2. @消息 (群聊):
 * {"type":"at", "to":"group_id@chatroom", "data": {"at":["wxid1","wxid2"], "content": "{$@}大家好{$@}"}}
 * 
 * 3. 链接消息:
 * {"type":"link", "to":"friend_wxid", "data": {"url":"https://example.com", "title":"标题", "description":"描述", "image":"https://example.com/image.jpg"}}
 * 
 * 4. 名片消息:
 * {"type":"card", "to":"friend_wxid", "data": {"wxid":"shared_contact_wxid"}}
 * 
 * 5. 图片消息:
 * {"type":"image", "to":"friend_wxid", "data": {"url":"https://example.com/image.jpg"}}
 * 
 * 6. 附加消息 (可选):
 * {"type":"text", "to":"friend_wxid", "data": {"content": "主消息"}, "addition": {"type":"link", "data": {"url":"https://example.com", "title":"附加链接"}}}
 */
Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('/wechat/send', [WechatController::class, 'send']);
    Route::post('/wechat/add', [WechatController::class, 'add']);
    Route::get('/wechat/friends', [WechatController::class, 'getFriends']);
});

// Chatwoot webhook endpoint
Route::post('/chatwoot/{wechatBot}', [ChatwootController::class, 'handle']);
