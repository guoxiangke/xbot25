<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

use App\Models\WechatBot;
use App\Models\WechatClient;

use App\Services\Xbot;
use App\Jobs\XbotMessageHandleQueue;

use App;

class XbotController extends Controller
{
    private $msgType;
    // Windows客户端登陆的微信bot的wxid，部分消息没有wxid
    private $xbotWxid = null;
    // 每台windows机器维护一个二维码池 Cache
    private $qrPoolCacheKey = null;
    private ?Xbot $xbot = null;
    private ?WechatBot $wechatBot = null;
    private $requestRawData = null;

    // 一个windows上可以登录多个微信，第x号微信客户端ID
    private $clientId = null;
    private $currentWindows = null;
    private  $wechatClient;

    private $cache;

    private $isSelf = false; // 自己响应自己的信息，防止陷入死循环


    /**
     * @param bool $debug 用于输出debug信息
     */
    public function __construct()
    {
        $this->cache = Cache::store('file');
    }

    public function __invoke(Request $request, string $winToken){
        {
            $currentWindows = $this->currentWindows = $winToken;
            // 用于缓存微信登录QR二维码，以windows机器为单位维护
            $this->qrPoolCacheKey = "xbots.{$currentWindows}.qrPool";

            $wechatClient = $this->wechatClient = WechatClient::where('token', $currentWindows)->first();
            if(!$wechatClient) {
                $errorMsg = '找不到windows机器';
                return $this->reply($errorMsg);
            }

            // msgType类型处理
            $msgType = $request['type']??false;
            $requestAllData = $request->all();
            if(!$msgType) {
                $errorMsg = '参数错误: no msg.type';
                Log::warning(__LINE__, compact('currentWindows', 'errorMsg', 'requestAllData'));
                return $this->reply($errorMsg);
            }

            // 忽略的消息类型
            $ignoreMessageTypes = [
                'MT_INJECT_WECHAT', //新开了一个客户端！ 但没有回调给laravel
                // 'MT_DATA_OWNER_MSG', // 获取到bot信息 $xbot->getSelfInfo();
                "MT_DEBUG_LOG" =>'调试信息',
                'MT_RECV_MINIAPP_MSG' => '小程序信息',
                "MT_WX_WND_CHANGE_MSG"=>'',
                "MT_UNREAD_MSG_COUNT_CHANGE_MSG" => '未读消息',
                "MT_DATA_WXID_MSG" => '从网络获取信息',
                "MT_TALKER_CHANGE_MSG" => '客户端点击头像切换对话',
                "MT_RECV_REVOKE_MSG" => 'xx 撤回了一条消息',
                "MT_DECRYPT_IMG_MSG_TIMEOUT" => '图片解密超时',
                "MT_ROOM_MEMBER_DISPLAY_UPDATE_NOTIFY_MSG" => 'member_list changed',
                "MT_ROOM_DEL_MEMBER_NOTIFY_MSG" => '',

                "MT_RECV_PICTURE_MSG" =>'暂时忽略',
                "MT_RECV_FILE_MSG" => "暂时忽略",
                "MT_RECV_OTHER_APP_MSG" => '包含 引用回复的文本！<msgsource><refermsg>"wx_sub_type":57,"wx_type":49<type>57</type>',
                "MT_RECV_EMOJI_MSG" => 'emoji',
                "MT_RECV_LINK_MSG" => '公众号图文',

            ];
            if(in_array($msgType, array_keys($ignoreMessageTypes)))  return $this->reply("忽略的消息类型:{$msgType}");
            $this->msgType = $msgType;
        }

        // 第x号微信客户端ID
        $clientId = $this->clientId = $request['client_id']??false;
        if(!$clientId)  return $this->reply('参数错误: no client_id');

        $requestRawData = $this->requestRawData = $request['data']??null;


        // 客户端登陆的bot的wxid，部分消息没有wxid
        // from_wxid 发送者
        // to_wxid 接收者
        $xbotWxid = $this->xbotWxid = $requestRawData['wxid']??$requestRawData['to_wxid']??null;

        // 先 new 一个可能不带 wxid 的
        $xbot = $this->xbot = new Xbot($wechatClient->endpoint, $xbotWxid, $clientId);
        // 新增加一个客户端，主动调用获取QR，压入缓存，以供web登陆
        if($msgType == 'MT_CLIENT_CONTECTED'){
            sleep(1); // 有时候不load二维码，可能太快了！
//            $xbot->loadQRCode();
            return $this->reply();
        }

        // 获取到二维码url后
        // {"client_id":13,"type":"MT_RECV_QRCODE_MSG","data":null}
        // {"client_id":13,"type":"MT_RECV_QRCODE_MSG","data":{"code":"http://weixin.qq.com/x/IcF1obA4ikY_aPFCxh8P"}}
        if($msgType == 'MT_RECV_QRCODE_MSG'){
            // 首次初始化时发来的 二维码，data为空，需要响应为空即可
            if(!$requestRawData) return $this->reply('首次初始化时发来的 二维码，data为空?');
            // Cache silkPath 路径 for WecatClient->silk_path
            $windowsTempPath = dirname($requestRawData['file']);// "C:\Users\Administrator\AppData\Local\Temp"
            $this->cache->set("xbot.silkPath.$winToken", $windowsTempPath);

            $this->processQrCode();
            return $this->reply('获取到二维码url后 正在维护二维码登录池');
        }
        if($msgType == 'MT_USER_LOGIN'){
            // 里面必须需要 WechatBot $wechatBot
            $this->wechatBot = WechatBot::where('wxid', $requestRawData['wxid'])->first();
            $this->processLogin();
            return $this->reply("processed $msgType");
        }

        $wechatBot = WechatBot::where('wxid', $xbotWxid)->first();
        // 由于部分消息没有wxid，故通过windows和端口 确保定一定有 wechatBot
        if(!$wechatBot){
            $wechatBot = WechatBot::where('wechat_client_id', $wechatClient->id)
               ->where('client_id', $clientId)
               ->first();
        }else{
            // MT_RECV_TEXT_MSG ：自己给自己发的信息
            // MT_DATA_OWNER_MSG
            Log::debug(__LINE__, [$xbotWxid, $requestAllData]);
        }
        if($wechatBot){
            $this->wechatBot = $wechatBot;
            $xbotWxid = $this->xbotWxid = $wechatBot->wxid;
        }else{
            Log::error(__LINE__, [$requestAllData]);
        }

        // 当主动关闭一个客户端后，新增一个客户端  {"type":"MT_CLIENT_DISCONTECTED","client_id":4}
        if($msgType == 'MT_CLIENT_DISCONTECTED'){
            $xbot->createNewClient();// post MT_INJECT_WECHAT
            $this->processLogout();
            $msg = '当主动关闭一个客户端后，新增一个客户端, 同时数据库下线WechatBot';
            return $this->reply($msg);
        }
        // 需要确保有 $wechatBot
        if($msgType == 'MT_USER_LOGOUT'){
            $this->processLogout();
            return $this->reply();
        }

        // 定时通过 MT_DATA_OWNER_MSG 获取到bot信息 $xbot->getSelfInfo();
        // 目的是为了确定 wechatBot 是否online
        if($msgType == 'MT_DATA_OWNER_MSG') {
            // 保存到cache中，并更新 last_active_time
            //$wechatBot->checkLive(); //update is_live_at
            $wechatBot->update([
                'is_live_at' => now(),
                'client_id' => $clientId, // 也可以不更新
            ]);

            $msg = "MT_DATA_OWNER_MSG $this->xbotWxid 在线！";
            return $this->reply($msg);
        }
        // 走到这里，现在确定有了 wxid
        // $xbot = new Xbot($wechatClient->endpoint, $xbotWxid, $clientId);

        // 初始化 联系人数据
        if( in_array($this->msgType, [
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG',
            'MT_DATA_PUBLICS_MSG',
            ]) ){
            $wechatBot->handleContactsInit($requestRawData);
            return $this->reply();
        }


        // 处理没有 Undefined array key "from_wxid"
        if (!isset($requestRawData['from_wxid'], $requestRawData['to_wxid'])){
            $errorMsg = '参数错误: no from_wxid or to_wxid';
            Log::error(__LINE__, [$errorMsg, $requestAllData]);
            return $this->reply($errorMsg);
        }

        if($requestRawData['from_wxid'] == $requestRawData['to_wxid']){
            $isSelf = $this->isSelf = true;
            // 处理自己给自己的消息，作为系统指令处理，如 /help
            if($msgType == 'MT_RECV_TEXT_MSG') {
                switch (true) {
                    case Str::startsWith($requestRawData['msg'], '/help'):
                        $this->xbot->sendTextMessage($xbotWxid, "Hi，我是一个AI机器人，暂支持以下指令：\n/help - 显示帮助信息\n/whoami - 显示当前登录信息\n/switch handleRoomMsg 群消息处理开关");
                        return $this->reply();
                    case Str::startsWith($requestRawData['msg'], '/whoami'):
                        $time = optional($wechatBot->login_at)->diffForHumans();
                        $text = "登陆时长：$time\n设备端口: {$wechatBot->client_id}@{$winToken}\n北京时间: {$wechatBot->login_at}";
                        $this->xbot->sendTextMessage($xbotWxid, $text);
                        return $this->reply();
                    // 忽略群消息
                    case Str::startsWith($requestRawData['msg'], '/switch handleRoomMsg'):
                        $isHandleRoomMsg = $wechatBot->getMeta('handleRoomMsg', false);
                        $wechatBot->setMeta('handleRoomMsg', !$isHandleRoomMsg);
                        $isHandleRoomMsg = $isHandleRoomMsg?'已禁用':'已启用';
                        $msg = "/handleRoomMsg $isHandleRoomMsg";
                        $this->xbot->sendTextMessage($xbotWxid, $msg);
                        return $this->reply();
                    // default: 显示指令菜单 不能搞默认指令，死循环！
                }
            }
            return $this->reply('自己给自己的消息');
        }

        // 忽略群消息
        $isRoom = $requestRawData['room_wxid']??false;
        if($isRoom){
            $isHandleRoomMsg = $wechatBot->getMeta('handleRoomMsg', false);
            if(!$isHandleRoomMsg) {
                return $this->reply();
            }
        }
        // 为什么要用队列处理 接收到的消息？不需要重放，对消息的处理时间都不长？
        if(isset($requestRawData['msgid'])) {
            XbotMessageHandleQueue::dispatch($wechatBot, $requestRawData);
        } else {
            Log::error(__LINE__, $requestAllData);
        }

        return $this->reply('PONG');
    }

    private function processQrCode()
    {
        $requestRawData = $this->requestRawData;
        $qrPoolCacheKey = $this->qrPoolCacheKey;
        // 前端刷新获取二维码总是使用第一个QR，登陆成功，则弹出对于clientId的QR
        $qr = [
            'qr' => $requestRawData['code'],//{"data":{"code":"http://weixin.qq.com/x/IcF1obA4ikY_aPFCxh8P"
            'client_id' => $this->clientId,
        ];
        $qrPool = Cache::get($qrPoolCacheKey, []);
        // 一台机器，多个客户端，使用二维码池, 池子大小==client数量，接收到1个新的，就把旧的全部弹出去
        // 把池子中所有 client_id 相同的 QR 弹出
        foreach ($qrPool as $key => $value) {
            if($value['client_id'] == $this->clientId){
                unset($qrPool[$key]);
            }
        }
        array_unshift($qrPool, $qr);
        Cache::put($qrPoolCacheKey, $qrPool);
        Log::debug('获取到登陆二维码，已压入qrPool', compact('qr','qrPoolCacheKey'));
        //如果登陆中?
    }

    private function processLogin()
    {
        $requestRawData = $this->requestRawData;
        $clientId = $this->clientId;
        $currentWindows = $this->currentWindows;

        // 登陆成功，则弹出对于clientId的所有 QR
        $qrPoolCacheKey = $this->qrPoolCacheKey;
        $qrPool = Cache::get($qrPoolCacheKey, []);
        $qrPool = array_filter($qrPool, fn($value) => $value['client_id'] != $clientId);//unset($qrPool[$key]);
        Cache::set($qrPoolCacheKey, $qrPool);

        {
            $wechatBot = $this->wechatBot;
            if(!$wechatBot) {
                Log::warning(__CLASS__, [__LINE__, '未找到对应的WechatBot', $this->xbotWxid]);
                $wechatBot = WechatBot::create(
                    [
                        'wxid' => $this->xbotWxid,
                        'wechat_client_id' => WechatClient::where('token', $currentWindows)->value('id'),
                        'name' => $requestRawData['nickname'],
                        'client_id' => $clientId,
                        'login_at' => now(),
                        'is_live_at' => now(),
                    ]
                );
            } else {
                // 更新登录时间
                $wechatBot->update([
                    'name' => $requestRawData['nickname'],
                    'login_at' => now(),
                    'is_live_at' => now(),
                    'client_id' => $clientId,
                ]);
                Log::info(__CLASS__, [__LINE__, '更新WechatBot登录时间', $wechatBot->toArray()]);
            }
            $wechatBot->setMeta('xbot', $requestRawData);
            $this->xbot->sendTextMessage($this->xbotWxid, "恭喜！登陆成功，正在初始化...");
        }

        $wechatBot->initContacts();
    }

    private function processLogout()
    {
        $wechatBot = $this->wechatBot;
        $qrPool = Cache::get($this->qrPoolCacheKey, []);
        // 登出后，维护 $qrPool
        $qrPool = array_filter($qrPool, fn($item) => $item['client_id'] != $this->clientId);
        Cache::put($this->qrPoolCacheKey, $qrPool);
        Log::info('登出', $wechatBot->toArray());
        $wechatBot->update([
            'is_live_at' => null,
            'login_at' => null,
            'client_id' => null
        ]);
    }

    private function reply($msg = null){
        return response()->json($msg, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
