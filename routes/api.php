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
use App\Http\Controllers\WechatApiController;

Route::any('/xbot/{winToken}', XbotController::class);

// {"type":"text", "to":"bluesky_still", "data": {"content": "API主动发送 文本/链接/名片/图片/视频 消息到好友/群"}}
// {"type":"at", "to" :"23896218687@chatroom", "data": {"at":["wxid_xxxxxx","wxid_xxxxxxx"],"content": "{$@}消息到好友/群{$@}"}}
Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('/wechat/send', [WechatApiController::class, 'send']);
    Route::post('/wechat/add', [WechatApiController::class, 'add']);
    Route::get('/wechat/friends', [WechatApiController::class, 'getFriends']);
});

 // Inbox webhook of chatwoot
 Route::post('/chatwoot/{wechatBot}', function (Request $request, WechatBot $wechatBot) {

     $messageType = $request['message_type'];
     $event = $request['event'];
     $sourceId = $request['source_id'] ?? '';

     // 忽略xbot_agent发送的消息，避免循环
     if ($sourceId === 'xbot_agent') return;

     // 只处理outgoing消息的created和updated事件
     if ($messageType !== 'outgoing' || !in_array($event, ['message_created', 'message_updated'])) {
         return;
     }
     Log::error('debug chatwoot webhook', [$request->all()]);


     $toWxid = $request['conversation']['meta']['sender']['custom_attributes']['wxid'] ?? '';
     if (empty($toWxid)) return;

     // 处理文本内容（如果有）
     $content = $request['content'] ?? '';
     if (!empty($content)) {
         $wechatBot->xbot()->sendTextMessage($toWxid, $content);
         Cache::set("chatwoot_outgoing_{$wechatBot->id}_{$toWxid}", $content, 30);
     }
     
     // 处理附件
     $attachments = $request['attachments'] ?? [];
     foreach ($attachments as $attachment) {
         $fileType = $attachment['file_type'];
         $fileUrl = $attachment['data_url'];
         
         if ($fileType === 'image') {
             $wechatBot->xbot()->sendImageByUrl($toWxid, $fileUrl);
             
             // 缓存图片附件信息，用于避免重复发送到Chatwoot
             Cache::set("chatwoot_outgoing_attachment_{$wechatBot->id}_{$toWxid}_image", true, 30);
             
             Log::info('Chatwoot image sent to WeChat', [
                 'to_wxid' => $toWxid,
                 'file_url' => $fileUrl,
                 'attachment_id' => $attachment['id']
             ]);
         } elseif (in_array($fileType, ['audio', 'file', 'video'])) {
             $wechatBot->xbot()->sendFileByUrl($toWxid, $fileUrl);
             
             // 缓存文件附件信息，用于避免重复发送到Chatwoot
             Cache::set("chatwoot_outgoing_attachment_{$wechatBot->id}_{$toWxid}_{$fileType}", true, 30);
             
             Log::info('Chatwoot file sent to WeChat', [
                 'to_wxid' => $toWxid,
                 'file_type' => $fileType,
                 'file_url' => $fileUrl,
                 'attachment_id' => $attachment['id'],
                 'file_size' => $attachment['file_size'] ?? 0
             ]);
         }
     }

     return true;
 });
