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
//Route::post('/xbot/heartbeat', function (Request $request) {
//    Log::warning('XBOT_HEARTBEAT',[$request->all()]);
//    return [];
//    // {"secret":"x x x x"}
//    return [
//        "err_code"=> 0,
//        "expired_in"=> 2499184
//    ];
//});
Route::post('/xbot/license/info', function (Request $request) {
    Log::warning('XBOT_LICENSE_INFO',[$request->all()]);
    return [
        "err_code"=> 0,
        "license" => config('services.xbot.license'),
    ];
});

use App\Http\Controllers\XbotController;
Route::any('/xbot/{winToken}', XbotController::class);

 // Inbox webhook of chatwoot
 Route::post('/chatwoot/{wechatBot}', function (Request $request, WechatBot $wechatBot) {
     $messageType = $request['message_type']; //只处理 outgoing ，即发送的消息，=》xbot处理发送。ignore incoming
     $event = $request['event']; //只处理message_created，不处理conversation_updated
     $contentType = $request['content_type']; //text

     // 检查source_id - 如果是xbot_agent发送的消息则忽略，避免循环
     // 但第一次通过UI发送的，没有 source_id， 会发2次，第一次没有，第二次有
     $sourceId = $request['source_id'] ?? '';
     if ($sourceId === 'xbot_agent') return ; // 这是Xbot agent发送的消息，忽略以避免循环

     // incoming 表示「访客 / 用户 / 客户」发进来的消息
     // outgoing 表示「坐席 / 机器人 / 系统」发出去的消息。
     // outgoing 是chatwoot主动发出的消息
     // TODO incoming 是chatwoot收到的消息
     if($event == 'message_created' && $messageType == 'outgoing' && $contentType == 'text'){
         Log::error('debug chatwoot webhook',[$sourceId]);
         $content = $request['content'];
//         $to_wxid = $request['conversation']['meta']['sender']['identifier'];//"identifier" => $wxid,
         $toWxid = $request['conversation']['meta']['sender']['custom_attributes']['wxid'];//"identifier" => $wxid,

         // 这是UI人工发送的消息，需要转发到Xbot
         $wechatBot->xbot()->sendTextMessage($toWxid, $content);
         // cache 最近发送的消息，避免循环
         Cache::set("chatwoot_outgoing_{$wechatBot->id}_{$toWxid}", $content, 30);
     }
     //
     return true;
 });
