<?php

namespace App\Http\Controllers;

use App;
use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Pipelines\Xbot\VoiceMessageHandler;
use App\Pipelines\Xbot\BuiltinCommandHandler;
use App\Pipelines\Xbot\EmojiMessageHandler;
use App\Pipelines\Xbot\FileMessageHandler;
use App\Pipelines\Xbot\ImageMessageHandler;
use App\Pipelines\Xbot\LinkMessageHandler;
use App\Pipelines\Xbot\NotificationHandler;
use App\Pipelines\Xbot\OtherAppMessageHandler;
use App\Pipelines\Xbot\SelfMessageHandler;
use App\Pipelines\Xbot\SystemMessageHandler;
use App\Pipelines\Xbot\TextMessageHandler;
use App\Pipelines\Xbot\VideoMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Jobs\XbotContactHandleQueue;
use App\Services\Xbot;
use App\Services\Chatwoot;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
    private ?WechatClient $wechatClient;

    private $cache;

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
            if(!$wechatClient)  return $this->reply('找不到windows机器');

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

//            // Cache silkPath 路径 for WecatClient->silk_path
//            $windowsTempPath = dirname($requestRawData['file']);// "C:\Users\Administrator\AppData\Local\Temp"
//            $this->cache->set("xbot.silkPath.$winToken", $windowsTempPath);

            $this->processQrCode();
            // TODO 发送二维码到管理群里
//            $wechatBotAdmin = WechatBot::find(7);// a个人微信AI应用定制解决方案
//            $wechatBotAdmin->xbot()->sendText($who, '2.请点击链接打开，使用申请体验的微信来扫码登陆！二维码将1分钟内将失效，登陆成功请等待初始化完毕后体验智能AI回复，更多功能请付费体验！ https://api.qrserver.com/v1/create-qr-code/?data='.$data['code']);
            return $this->reply('获取到二维码url后 正在维护二维码登录池');
        }
        if($msgType == 'MT_USER_LOGIN'){
            // 里面必须需要 WechatBot $wechatBot
            $this->wechatBot = WechatBot::where('wxid', $requestRawData['wxid'])->first();
            $this->processLogin(); // 里面第一个需要创建一个 $wechatBot
            return $this->reply("processed $msgType");
        }

        $wechatBot = WechatBot::where('wxid', $xbotWxid)->first();
        // 由于部分消息没有wxid，故通过windows和端口 确保定一定有 wechatBot
        if(!$wechatBot){
            $wechatBot = WechatBot::where('wechat_client_id', $wechatClient->id)
               ->where('client_id', $clientId)
               ->first();
        }
        if($wechatBot){
            $this->wechatBot = $wechatBot;
            $this->xbotWxid = $wechatBot->wxid;
        }else{
            Log::error(__LINE__, [$requestAllData]);
        }

        // 当主动关闭一个客户端后，新增一个客户端  {"type":"MT_CLIENT_DISCONTECTED","client_id":4}
        if($msgType == 'MT_CLIENT_DISCONTECTED'){
            $xbot->createNewClient();// post MT_INJECT_WECHAT
            $this->processLogout();
            return $this->reply('当主动关闭一个客户端后，新增一个客户端, 同时数据库下线WechatBot');
        }
        // 需要确保有 $wechatBot
        if($msgType == 'MT_USER_LOGOUT'){
            $this->processLogout();
            return $this->reply();
        }

        // 定时通过 MT_DATA_OWNER_MSG 获取到bot信息 $xbot->getSelfInfo();
        // 目的是为了确定 wechatBot 是否 online
        // 返回的data 是单个 contact 的信息
        if($msgType == 'MT_DATA_OWNER_MSG') {
            // 保存到cache中，并更新 last_active_time
            $wechatBot->update([
                'is_live_at' => now(),
                'client_id' => $clientId, // 也可以不更新
            ]);
            return $this->reply();
        }
        // 走到这里，现在确定有了 wxid
        // $xbot = new Xbot($wechatClient->endpoint, $xbotWxid, $clientId);

        // 忽略1小时以上的信息 60*60
        if(isset($requestRawData['timestamp']) && $requestRawData['timestamp']>0 &&  now()->timestamp - $requestRawData['timestamp'] > 1*60*60 ) {
            return response()->json(null);
        }

        // 初始化 联系人数据
// TODO
//        'MT_DATA_WXID_MSG', // 获取单个好友的信息 $xbot->getFriendInfo($wxid);
//        'MT_DATA_CHATROOM_MEMBERS_MSG'
        if( in_array($msgType, [
            'MT_DATA_FRIENDS_MSG',
            'MT_DATA_CHATROOMS_MSG',
            'MT_DATA_PUBLICS_MSG',
            'MT_ROOM_CREATE_NOTIFY_MSG',// 新加入群后，获取到的通讯录
            ]) ){
            // 返回 以 wxid 为 key 的联系人数组
            $this->wechatBot->handleContacts($requestRawData);
            $chatwootEnabled = $this->wechatBot->getMeta('chatwoot_enabled', 1);
            if($chatwootEnabled) {
                $contacts = $requestRawData;
                $labels = [
                    'MT_DATA_FRIENDS_MSG' => '微信好友',
                    'MT_DATA_CHATROOMS_MSG' => '微信群',
                    'MT_DATA_PUBLICS_MSG' => '微信订阅号',
                    'MT_ROOM_CREATE_NOTIFY_MSG' => '微信群',
                ];
                $label = $labels[$msgType];
                foreach ($contacts as $contact) {
                    XbotContactHandleQueue::dispatch($wechatBot, $contact, $label);
                }
            }
            return $this->reply();
        }

        // 处理没有 Undefined array key "from_wxid"
//        {"data":{"avatar":null,"is_manager":1,"manager_wxid":"wxid_t36o5djpivk312","member_list":[{"avatar":"http://mmhead.c2c.wechat.com/mmhead/ver_1/s7c3LL0d4BF2duBJZPFYwg0tSiaKEoPxNWTxFCsyhdzIn90wxPFexJNpsJ3goJeDFc97obN5rqW2fc4dswPQJZ0w6Cia9nibo3M7EMFUBoSkrM/132","invite_by":"bluesky_still","nickname":"AI","wxid":"wxid_8mxsul3gb3fg12"}],"nickname":"微信机器人","room_wxid":"26299514940@chatroom","total_member":3},
//"type":"MT_ROOM_ADD_MEMBER_NOTIFY_MSG","client_id":1}
        if (!isset($requestRawData['from_wxid'], $requestRawData['to_wxid'])){
            $errorMsg = '参数错误: no from_wxid or to_wxid';
            Log::error(__LINE__, [$errorMsg, $requestAllData]);
            return $this->reply($errorMsg);
        }

        // 忽略群消息
        $isRoom = $requestRawData['room_wxid']??false;
        if($isRoom){
            $isHandleRoomMsg = $wechatBot->getMeta('room_msg_enabled', false);
            if(!$isHandleRoomMsg) {
                return $this->reply();
            }
        }
        // 为什么要用队列处理 接收到的消息？不需要重放，对消息的处理时间都不长？
        if(isset($requestRawData['msgid'])) {
            // 直接使用pipeline处理消息
            $this->processMessageWithPipeline($wechatBot, $requestRawData, $msgType);
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
            'qr' => $requestRawData['code'],
            'client_id' => $this->clientId,
        ];
        $qrPool = $this->cache->get($qrPoolCacheKey, []);
        // 一台机器，多个客户端，使用二维码池, 池子大小==client数量，接收到1个新的，就把旧的全部弹出去
        // 把池子中所有 client_id 相同的 QR 弹出
        foreach ($qrPool as $key => $value) {
            if($value['client_id'] == $this->clientId){
                unset($qrPool[$key]);
            }
        }
        array_unshift($qrPool, $qr);
        $this->cache->put($qrPoolCacheKey, $qrPool);
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
                        // TODO delete for test
                        'chatwoot_account_id' => 1,
                        'chatwoot_inbox_id' => 1,
                        'chatwoot_token' => 'euKZgVd34rfnY87CTPijieJU',
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

        // init Contacts
        $this->xbot->getFriendsList();
        $this->xbot->getChatroomsList();
        $this->xbot->getPublicAccountsList();
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

    /**
     * 使用pipeline处理消息
     */
    private function processMessageWithPipeline(WechatBot $wechatBot, array $requestRawData, string $msgType): void
    {
        $context = new XbotMessageContext($wechatBot, $requestRawData, $msgType);

        // 定义消息处理管道 - 按优先级排序
        $pipeline = [
            NotificationHandler::class,       // 通知消息

            BuiltinCommandHandler::class,     // 最高优先级：内置命令 用户输入的命令
            SelfMessageHandler::class,        // 自消息处理

            // 把各种消息类型处理后，都转换成纯文本的信息
            SystemMessageHandler::class,      // 系统消息 系统自动生成的通知 MT_RECV_SYSTEM_MSG
            // {"data":{"from_wxid":"26299514940@chatroom","is_pc":0,"msgid":"6454519846508614775","raw_msg":"你邀请\"AI天空蔚蓝\"加入了群聊  ","room_name":"微信机器人","room_wxid":"26299514940@chatroom","timestamp":1756137390,"to_wxid":"26299514940@chatroom","wx_type":10000},"type":"MT_RECV_SYSTEM_MSG","client_id":1}
            ImageMessageHandler::class,       // 图片消息
            FileMessageHandler::class,        // 文件消息
            VideoMessageHandler::class,       // 视频消息
            VoiceMessageHandler::class,       // 语音消息
            EmojiMessageHandler::class,       // 表情消息
            LinkMessageHandler::class,        // 链接消息
//            'MT_TRANS_VOICE_MSG',
            OtherAppMessageHandler::class,    // 其他应用消息
            // 转由最后一个 TextMessageHandler 来处理。
            TextMessageHandler::class,        // 文本消息（最后执行）
        ];

        app(Pipeline::class)
            ->send($context)
            ->through($pipeline)
            ->then(function ($context) {
                Log::debug('Message pipeline completed', [
                    'context' => $context->toArray()
                ]);
            });
    }
}
