<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Models\WechatContact;
use App\Models\WechatContent;
use App\Models\WechatBotContact;
use App\Models\WechatMessage;
use App\Models\WechatMessageFile;
use App\Models\WechatMessageVoice;
use App\Models\XbotSubscription;
use App\Chatwoot\Chatwoot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Services\Xbot;
use App\Services\Icr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\SilkConvertQueue;

class XbotCallbackController extends Controller
{

    public function __invoke(Request $request, $token){

        //*********************************************************

        $content = ''; //写入 WechatMessage 的 content 

        $config = $wechatBot->getMeta('xbot.config', [
            'isAutoWcpay' => false, // MT_RECV_WCPAY_MSG
            'isAutoAgree' => false, // 自动同意好友请求
            'isWelcome' => false,
            'weclomeMsg' => 'hi',
            'isListenRoom' => false,
            'isListenRoomAll' => false,
            'isAutoReply' => false, // 关键词自动回复
            'isResourceOn' => false,
            'isChatwootOn' => false,
        ]);
        if(!isset($config['isResourceOn'])){
            $config['isResourceOn'] = false;
        }
        if(!isset($config['isChatwootOn'])){
            $config['isChatwootOn'] = false;
        }

        // AutoReply  响应 预留 关键词 + 群配置
        $islistenMsg = true; //默认是记录消息，但是在群里，需要判断
        $isAutoReply = $config['isAutoReply']??false;

        // 获取或更新单个联系人信息
        if($type == 'MT_DATA_WXID_MSG') return $wechatBot->syncContact($data);

        // 0 正常状态(不是僵尸粉)
        // 1 检测为僵尸粉(对方把我拉黑了)
        // 2 检测为僵尸粉(对方把我从他的好友列表中删除了)
        // 3 检测为僵尸粉(原因未知,如遇到3请反馈给我)
        if($type == 'MT_ZOMBIE_CHECK_MSG'){
            switch ($data['status']) {
                case 0:
                    // 0 正常状态(不是僵尸粉) 勿打扰提醒
                    break;
                case 1:
                    // $wechatBot->xbot()->sendText($data['wxid'], "1 检测为僵尸粉(对方把你拉黑了) ");
                case 2:
                case 3:
                    $wechatBot->xbot()->sendContactCard('filehelper',$data['wxid']);
                    break;
                default:
                    // code...
                    break;
            }
            return response()->json(null);
        }
        // MT_ROOM_ADD_MEMBER_NOTIFY_MSG 新人入群
        // MT_ROOM_CREATE_NOTIFY_MSG 被拉入群
        // MT_DATA_CHATROOM_MEMBERS_MSG 主动获取 群成员信息，入库 不需要了，只有wxid，没有其他信息，使用再次getRooms()再次入库
        if($type == 'MT_RECV_SYSTEM_MSG'){
            $rawMsg = $data['raw_msg'];
            // 'MT_RECV_SYSTEM_MSG', // 群名修改
            // "raw_msg":"\"天空蔚蓝\"修改群名为“#xbot001”"
            // "raw_msg":"\"天空蔚蓝\" changed the group name to \"收听互助\"" "wx_type":10000}
            // "room_name":"#xbot"
            if(Str::contains($rawMsg, '修改群名为')){
                //“#xbot001” => #xbot001
                $re = '/[“][\s\S]*[”]/';
                preg_match($re, $rawMsg, $matches);
                $string = $matches[0];
                $string = Str::replace('“', '', $string);
                $newRoomName = Str::replace('”', '', $string);

                //->更新数据库中名字
                WechatContact::where('wxid',$data['room_wxid'])->update(['nickname' => $newRoomName]);
                //TODO 只有群主可以改，其他改，要改回去 xbot的接口

                // 更新群名，不更改备注群名
                // 修改群名为“好友检测”
                $wechatBot->xbot()->getRooms();
            }
            if(Str::contains($rawMsg, '收到红包')){
                // 提醒 收到🧧红包！TODO 设置一个红包提醒群
                $wechatBot->xbot()->sendText('filehelper', $rawMsg);
            }
            // xxx 开启了朋友验证，你还不是他（她）朋友。请先发送朋友验证请求，对方验证通过后，才能聊天。<a href=\"weixin://findfriend/verifycontact\">发送朋友验证</a>
            // xxx 把你无情的删了！
            if(Str::contains($rawMsg, '请先发送朋友验证请求')){
                $remark = 'A00-僵死友' . substr($msgid,12,4);
                $wechatBot->xbot()->sendText('filehelper', strip_tags($rawMsg)."\n备注已改为：\n".$remark);
                $wechatBot->xbot()->remark($fromWxid, $remark);
                // TODO 删除联系人和及其订阅
            }
        }
        if($type == 'MT_ROOM_ADD_MEMBER_NOTIFY_MSG' || $type == 'MT_ROOM_CREATE_NOTIFY_MSG'){
            //提醒
            $roomConfigIn = false; //todo
            $roomWxid = $data['room_wxid'];
            $isListenMemberChange = $isListenMemberChangeRooms[$roomWxid]??false;
            if($isListenMemberChange || $data['is_manager']??false){
                $members = $data['member_list'];
                $memberString = '';
                $atList = [];
                foreach ($members as $member) {
                    $memberString .= "@{$member['nickname']} ";
                    $atList[] = $member['nickname'];
                }
                // $msg = $roomWelcomeMessages[$roomWxid]??"欢迎{$memberString}加入本群👏";
                // $wechatBot->xbot()->sendText($roomWxid, $msg);
            }
            // 创建群后，再次手动掉getRooms()以执行273行 来初始化群数据
            $wechatBot->xbot()->getRooms();
            return response()->json(null);
        }
        // # bot/群成员 被踢出群
        // 群成员 被踢出群/退群
        if($type == 'MT_ROOM_DEL_MEMBER_NOTIFY_MSG'){
            // 如果是bot
            $isBotRemovedFromGroup = false;
            foreach ($data['member_list'] as $member) {
                if($member['wxid'] == $wechatBot->wxid){
                    $isBotRemovedFromGroup = true;
                }else{ //其他人 退群/被移出群
                    // 1.找到这个 陌生人id
                    $gBotContact = WechatBotContact::withTrashed()
                        ->where('wechat_bot_id', $wechatBot->id)
                        ->firstWhere('wxid', $member['wxid']);
                    // $content = "{$member['nickname']}被出群了";
                    // 2.群消息不变，他发的都删！
                    if(!$gBotContact){
                        Log::error(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $member['wxid'], '找不到的联系人被移除/退出了群']);
                        continue;
                    }
                    // WechatMessage::query()
                    //     ->where('from', $gBotContact->id)
                    //     ->delete();
                    Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $gBotContact->remark, $gBotContact->id, '群成员变动，不再删除消息，下面开始提醒']);
                    // $gBotContact->delete();
                    //提醒
                    $replyTo = $data['room_wxid'];
                    $isListenMemberChange = $isListenMemberChangeRooms[$replyTo]??false;
                    if($isListenMemberChange || $data['is_manager']??false){
                        $members = $data['member_list'];
                        $memberString = '';
                        foreach ($members as $member) {
                            $memberString .= $member['nickname']. ' ';
                        }
                        $msg = "{$memberString}退出了本群";
                        // TODO 后台设置 是否提醒@群主？
                        // $wechatBot->xbot()->sendText($data['room_wxid'], $msg);
                    }
                }
            }
            //2. 删除 wechat_bot_contacts
            //1. 删除 messages
            if($isBotRemovedFromGroup) {
                $groupWxid = $data['room_wxid'];
                $gBotContact = WechatBotContact::withTrashed()
                    ->where('wechat_bot_id', $wechatBot->id)
                    ->firstWhere('wxid', $groupWxid);
                    // ->where('type', 2) 群，一定是2
                    // firstWhere /get 一定有一个
                WechatMessage::query()
                    ->where('conversation', $gBotContact->id)
                    ->delete();
                Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $gBotContact->nickname, $gBotContact->id, 'bot被出群，已删除群记录']);
                $gBotContact->delete();
            }
        }


        //************************************************
        $xbot = $wechatBot->xbot($clientId);
        //************************************************
        // MT_RECV_SYSTEM_MSG
        // 同意好友：你已添加了天空蔚蓝，现在可以开始聊天了。"
        // 群名修改：changed the group name to

        // 微信支付
        // 一次转账自动首款后，会产生2条消息：[收到转账]和[已收款]
        // 不支持群收款！
        $switchOn = $config['isAutoWcpay'];
        if($switchOn && $type == 'MT_RECV_WCPAY_MSG'){
            // "feedesc":"￥0.10",
            // substr('￥0.10',3) + 1 = 1.1 x 100 = 110分
            $transferid = $xml['appmsg']['wcpayinfo']['transferid'];
            $feedesc = $xml['appmsg']['wcpayinfo']['feedesc'];
            $amount = substr($feedesc, 3) * 100;
            //TODO 只退回1 分钱 ,退款测试
            if($amount == 1) {
                //自动退款，如果数字不对
                $xbot->refund($transferid);
                return response()->json(null);
            }
            // 保存到message里 begin
                $xbot->autoAcceptTranster($transferid);
                // pay_memo 付款描述
                $pay_memo = $xml['appmsg']['wcpayinfo']['pay_memo']?:'';

                $wxid = $isSelf?$toWxid:$fromWxid;
                $conversation = WechatBotContact::query()
                    ->where('wechat_bot_id', $wechatBot->id)
                    ->where('wxid', $wxid)
                    ->first();

                $content = $isSelf?'[已收款]':'[收到转账]' . ':' . $feedesc . ':附言:' . $pay_memo;
                // get amount from content.
                    // $feedesc =  explode('-', content)[1];
                    // $amount = substr($feedesc, 3) * 100;
                $data = [
                    'type' => array_search($type, WechatMessage::TYPES), // 6:wcpay
                    'wechat_bot_id' => $wechatBot->id,
                    'from' => $isSelf?NULL:$conversation->id, // 消息发送者:Null为bot发送的
                    'conversation' => $conversation->id,
                    'content' => $content,
                    'msgid' => $msgid,
                ];
                Log::debug('MT_RECV_WCPAY_MSG', ['微信转账', $transferid, $amount, $data]);
                $message = WechatMessage::create($data); //发送webhook回调
            // 保存到message里 end
            return response()->json(null);
        }

        // 收到位置消息
        if($type == 'MT_RECV_LOCATION_MSG'){
            $content = '[位置消息]:'. implode(':', $xml['location']['@attributes']);

            $wxid = $isSelf?$toWxid:$fromWxid;
            $conversation = WechatBotContact::query()
                ->where('wechat_bot_id', $wechatBot->id)
                ->where('wxid', $wxid)
                ->first();

            $data = [
                'type' => array_search($type, WechatMessage::TYPES), // 7:location
                'wechat_bot_id' => $wechatBot->id,
                'from' => $isSelf?NULL:$conversation->id, // 消息发送者:Null为bot发送的
                'conversation' => $conversation->id,
                'content' => $content,
                'msgid' => $msgid,
            ];
            Log::debug('MT_RECV_LOCATION_MSG', ['收到位置消息', $xml['location']['@attributes']]);
            $message = WechatMessage::create($data); //发送webhook回调
            // 保存到message里 end
            return response()->json(null);
        }


        // ✅ 搜索用户信息后的callback，主动+好友
        // 同意好友请求后，好像也有这个 MT_SEARCH_CONTACT_MSG
        if ($type == 'MT_SEARCH_CONTACT_MSG') {
            if(isset($data['v1']) && isset($data['v2'])){
                $remark = "朋友介绍"; //todo remark settings in FE
                $xbot->addFriendBySearchCallback($data['v1'], $data['v2'], $remark);
                Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $type, '主动+好友', $data['search']]);
            }else{
                // 先更新好友吧！
                $xbot->getFriends(); //修bug
                $xbot->getRooms(); //更新群
                Log::error(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $type, '更新群成员入库', $data]);
            }
            return response()->json(null);
        }

        // ✅ 收到好友请求后自动同意加好友
        $switchOn = $config['isAutoAgree'];
        if($switchOn && $type == 'MT_RECV_FRIEND_MSG'){
            $attributes = $xml['@attributes'];
            // $scene = 3: 14: 从群里添加 6:拉黑用户再次请求;
            $xbot->agreenFriend($attributes['scene'], $attributes['encryptusername'], $attributes['ticket']);
            Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $type, "收到{$attributes['fromnickname']}的好友请求:{$attributes['content']}"]);
            return response()->json(null);
        }

        // ✅ 手动同意好友请求 发送 欢迎信息
        if($type == 'MT_CONTACT_ADD_NOITFY_MSG'){
            $xbot->getFriend($cliendWxid);
            $switchOn = $config['isWelcome'];
            $switchOn && $xbot->sendText($cliendWxid, $config['weclomeMsg']);
            // 写入数据库
            $wechatBotContact = WechatBotContact::query()
                ->withTrashed()
                ->where('wxid', $cliendWxid)
                ->where('wechat_bot_id', $wechatBot->id)
                ->first();
            if($wechatBotContact) {
                $wechatBotContact->restore();
                $wechatBotContact->type = 1;
                $wechatBotContact->save();
            }else{
                // 首次不再创建用户： 因为 首次添加好友时，微信提供的信息不全，只有一个 wxid
                // @see WechatBot->syncContacts()

                //是否存在contact用户
                $data['type'] = WechatContact::TYPES['friend']; //1=friend
                $data['nickname'] = $data['nickname']??$cliendWxid; //默认值为null的情况
                $data['avatar'] = $data['avatar']??WechatBotContact::DEFAULT_AVATAR; //默认值为null的情况
                // $data['remark'] = $data['remark']??$data['nickname']; //默认值为null的情况
                Log::error('EDBUG', $data);
                ($contact = WechatContact::firstWhere('wxid', $cliendWxid))
                    ? $contact->update($data) // 更新资料
                    : $contact = WechatContact::create($data);
                WechatBotContact::create([
                    'wechat_bot_id' => $wechatBot->id,
                    'wechat_contact_id' => $contact->id,
                    'wxid' => $contact->wxid,
                    'remark' => $data['remark']??$data['nickname'],
                    'seat_user_id' => $wechatBot->user_id, //默认坐席为bot管理员
                ]);
            }
        }

        // bot手机微信主动删除好友
        if($switchOn && $type == 'MT_CONTACT_DEL_NOTIFY_MSG'){
            WechatBotContact::query()
                ->where('wxid', $cliendWxid)
                ->where('wechat_bot_id', $wechatBot->id)
                ->first()
                ->delete();
            Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, $type, '主动删除好友']);
        }

        if($type == 'MT_RECV_TEXT_MSG'){ //接收到 个人/群 文本消息
            $content = $data['msg'];
            $replyTo = $fromWxid;//消息发送者
            if($isRoom) $replyTo = $data['room_wxid'];
            if($fromWxid == $wechatBot->wxid) $replyTo = $toWxid; //自己给别人聊天时，发关键词 响应信息
            // 彩蛋:谁在线，在线时长！
            if($content=='whoami'){
                $time = optional($wechatBot->login_at)->diffForHumans();
                $text = "已登陆 $time\n时间: {$wechatBot->login_at}\n设备: {$clientId}号端口@Windows{$wechatBot->wechat_client_id}\n用户: {$wechatBot->user->name}";
                $xbot->sendText($replyTo, $text);
                // 针对文本 命令的 响应，标记 已响应，后续 关键词不再触发（return in observe）。
                // 10s内响应，后续hook如果没有处理，就丢弃，不处理了！
                // 如果其他资源 已经响应 关键词命令了，不再推送给第三方webhook了
                Cache::put($cacheKeyIsRelpied, true, 10);
            }
            if($isAutoReply && !$isSelf) {
                $keywords = $wechatBot->autoReplies()->pluck('keyword','wechat_content_id');
                foreach ($keywords as $wechatContentId => $keyword) {
                    // TODO preg; @see https://laravel.com/docs/8.x/helpers#method-str-is
                    if(Str::is(trim($keyword), $content)){
                        Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid, '关键词回复', $keyword]);
                        $wechatBot->send([$replyTo], WechatContent::find($wechatContentId));
                        Cache::put($cacheKeyIsRelpied, true, 10);
                    }
                }
            }
            // 资源：预留 关键词
                //  600 + 601～699   # LY 中文：拥抱每一天 getLy();
                //  7000 7001～7999  # Album 自建资源 Album 关键词触发 getAlbum();
                // #100  #100～#999  # LTS getLts();
        }
        if($type == 'MT_RECV_OTHER_APP_MSG') {
            if($data['wx_type'] == 49){
                //更改TYPE 以便执行消息写入
                $type = 'MT_RECV_TEXT_MSG';
                $content = '其他消息，请到手机查看！';
                // 收到音频消息
                if(isset($data['wx_sub_type'])){
                    switch ($data['wx_sub_type']) {
                        case  3:
                            $title = $xml['appmsg']['title']??'';
                            $content = "音乐消息｜{$title}";//: {$xml['appmsg']['url']}
                            break;
                        case  19: //聊天记录
                            $content = "{$xml['appmsg']['title']} : {$xml['appmsg']['des']}";
                            break;
                        case  36: //百度网盘
                            $content = "{$xml['appmsg']['sourcedisplayname']} ｜ {$xml['appmsg']['title']} : {$xml['appmsg']['des']} : {$xml['appmsg']['url']} ";
                            break;
                        case  51:
                            $content = "视频号｜{$xml['appmsg']['finderFeed']['nickname']} : {$xml['appmsg']['finderFeed']['desc']}";
                            break;
                        case  57:
                            $content = "引用回复｜{$xml['appmsg']['title']}";
                            break;
                        default:
                            $content = "其他未处理消息，请到手机查看！";
                            // $content .= $xml['appmsg']['title']??'';
                            // $content .= $xml['appmsg']['des']??'';
                            // $content .= $xml['appmsg']['desc']??'';
                            // $content .= $xml['appmsg']['url']??'';
                            break;
                    }
                }
            }
        }
        // 把接收的消息写入 WechatMessage
        $recordWechatMessageTypes = [
            'MT_RECV_SYSTEM_MSG',

            'MT_RECV_TEXT_MSG',
            'MT_RECV_VOICE_MSG',
            'MT_RECV_EMOJI_MSG',
            'MT_RECV_PICTURE_MSG',
            'MT_RECV_FILE_MSG',
            'MT_RECV_VIDEO_MSG',
            'MT_RECV_LINK_MSG',
            'MT_TRANS_VOICE_MSG',
        ];
        if($islistenMsg && in_array($type,$recordWechatMessageTypes)) {
            $conversationWxid = $fromWxid;
            // 被动响应的信息+主动回复给filehelper的信息

            $fromId = null;
            if($fromWxid == $wechatBot->wxid){
                // $fromId = null;
                $conversationWxid = $toWxid;
            }else{
                if($isSelf) {
                    $fromId = null;
                }else{
                    $from = WechatBotContact::query()
                        ->where('wechat_bot_id', $wechatBot->id)
                        ->where('wxid', $fromWxid)
                        ->first();
                    if(!$from) {
                        if($isRoom){
                            // 接口初始化一下(本群的)所有群的所有群成员
                            // 收到执行，修复bug, 300行已解决
                            return $xbot->getRooms();
                        }else{
                            // 陌生人还没有入库
                            $xbot->getFriend($fromWxid);
                            Log::debug(__CLASS__, [__LINE__, $wechatBot->id, $fromWxid, $wechatClientName, $wechatBot->wxid, '期待有个fromId but no from!',$request->all()]);
                        }
                    }else{
                        $fromId = $from->id;
                    }
                }
            }
            //如果是群，别人发的信息
            if($isRoom){
                $conversationWxid = $data['room_wxid'];
            }
            $conversation = WechatBotContact::withTrashed()
                ->where('wxid', $conversationWxid)
                ->where('wechat_bot_id', $wechatBot->id)
                ->first();
            if(!$conversation) {
                // 下一步，搜索好友，加好友
                if(!$isRoom){
                    Log::debug(__CLASS__, [__LINE__, $wechatClientName, $wechatBot->wxid,  $conversationWxid, '给不是好友的人发的信息，即把他删了，对方又请求好友了，我没答应，此时还可以发信息|新群！']);
                    // 另一种情况：即同意添加好友！
                    $xbot->addFriendBySearch($conversationWxid);
                    return response()->json(null);
                }else{
                    //新人入群！
                    Log::error('没有入群！!!?', [$conversationWxid, $wechatBot->id]);
                    $wechatBot->xbot()->getRooms();
                    return response()->json(null);
                }
            }else{
                $conversation->restore();
            }
            WechatMessage::create([
                'type' => array_search($type, WechatMessage::TYPES), // 1文本
                'wechat_bot_id' => $wechatBot->id,
                'from' => $fromId, // 消息发送者:Null为bot发送的
                'conversation' => $conversation->id, //群/个人
                'content' => $content,
                'msgid' => $msgid,
            ]);
            if(!$isSelf) { //不自动响应自己的信息，死循环
                // 订阅+关键词 //TODO  是否开启个人订阅/群订阅
                // $isRoom
                if(Str::startsWith($content, '订阅')){
                    $keyword = Str::replace('订阅', '', $content);
                    $keyword = trim($keyword);
                    $res = $wechatBot->getResouce($keyword);
                    if(!$res) {
                        $autoReply = $wechatBot->autoReplies()->where('keyword', $keyword)->first();
                        if($autoReply){
                            $res = $autoReply->content;//$wechatContent
                        }
                    }
                    if($res){ // 订阅成功！
                        // FEBC-US 5点发送
                        $clock = $wechatBot->id==13?5:7;
                        $cron = "0 {$clock} * * *";
                        if(!$isRoom && $wechatBot->id==13){
                            // FEBC-US 不支持个人订阅
                            return $xbot->sendText($conversation->wxid, '暂不支持个人订阅，请入群获取或回复编号！');
                        }
                        $xbotSubscription = XbotSubscription::withTrashed()->firstOrCreate(
                            [
                                'wechat_bot_id' => $wechatBot->id,
                                'wechat_bot_contact_id' => $conversation->id,
                                'keyword' => $keyword,
                            ],
                            [
                                'cron' => $cron
                            ]
                        );
                        if($xbotSubscription->wasRecentlyCreated){
                            $xbot->sendText($conversation->wxid, "成功订阅，每早{$clock}点，不见不散！");
                        }else{
                            $xbotSubscription->restore();
                            $xbot->sendText($conversation->wxid, '已订阅成功！时间和之前一样');
                        }
                    }else{
                        $xbot->sendText($conversation->wxid, '关键词不存在任何资源，无法订阅');
                    }
                    return response()->json(null);
                }
                if(Str::startsWith($content, '取消订阅')){
                    $keyword = Str::replace('取消订阅', '', $content);
                    $keyword = trim($keyword);
                    $xbotSubscription = XbotSubscription::query()
                        ->where('wechat_bot_id', $wechatBot->id)
                        ->where('wechat_bot_contact_id', $conversation->id)
                        ->where('keyword', $keyword)
                        ->first();
                    if($xbotSubscription){
                        $xbot->sendText($conversation->wxid, '已取消订阅！');
                        $xbotSubscription->delete();
                    }else{
                        $xbot->sendText($conversation->wxid, '查无此订阅！');
                    }
                    return response()->json(null);
                }

                $roomJoinKeys = $wechatBot->getMeta('roomJoinKeys', []);
                if(Str::startsWith($content, '入群') && $roomJoinKeys){
                    $joinMenu = '回复对应加群暗号即可入群';
                    foreach ($roomJoinKeys as $value) {
                        $joinMenu .= PHP_EOL .'- '. $value;
                    }
                    $xbot->sendText($conversation->wxid, $joinMenu);
                    return response()->json(null);
                }
                foreach ($roomJoinKeys as $room_wxid => $value) {
                    if($value === $content) {
                        $xbot->addMememberToRoom($room_wxid, $conversation->wxid);
                        $xbot->addMememberToRoomBig($room_wxid, $conversation->wxid);
                        return response()->json(null);
                    }
                }

                if(!$isRoom && $content == '试用体验微信机器人'){
                    $client = WechatClient::find(8);
                    $client->new();
                    $wechatBot->xbot()->sendText($conversation->wxid, '1.已向腾讯请求获取二维码，请耐心等待, 2.请添加微信 ');

                    $whoNeedQr = Cache::get($whoNeedQrKey, []);
                    $whoNeedQr[] = $conversation->wxid;

                    Cache::put($whoNeedQrKey, $whoNeedQr, 30);
                    return response()->json(null);
                }

                $switchOn = $config['isResourceOn'];
                $isReplied = Cache::get($cacheKeyIsRelpied, false);
                if(!$isReplied && $switchOn) {
                    // if($wechatBot->id == 'ly' && !$isRoom) return [];
                    $res = $wechatBot->getResouce($content);
                    if(Str::contains($content,['youtube.','youtu.be'])){
                        //18403467252@chatroom Youtube精选
                        // TODO 根据群名字配置来发送，包含 youtube 的群才响应。
                        if(($isRoom && in_array($requestData['room_wxid'],[
                                                    "26570621741@chatroom",
                                                    "18403467252@chatroom",
                                                    "34974119368@chatroom",
                                                    "57526085509@chatroom",//LFC活力生命
                                                    "58088888496@chatroom",//活泼的生命
                                                    "57057092201@chatroom",//每天一章
                                                ])) || $toWxid=='keke302'){
                            Cache::put($cacheKeyIsRelpied, true, 10);
                            return $wechatBot->send([$conversation->wxid], $res);
                        }else{
                            // don't send
                            return response()->json(null);
                        }
                    }elseif($res){
                        Cache::put($cacheKeyIsRelpied, true, 10);
                        $wechatBot->send([$conversation->wxid], $res);
                        // 返回，不执行下面的chatwoot👇
                        return response()->json(null);
                    }
                }


                // begin send message to chatwoot
                // 只记录机器人收到的消息
                $recordWechatMessageTypes = [
                    'MT_RECV_TEXT_MSG',
                    'MT_RECV_VOICE_MSG',
                    'MT_RECV_EMOJI_MSG',
                    'MT_RECV_PICTURE_MSG',
                    'MT_RECV_FILE_MSG',
                    'MT_RECV_VIDEO_MSG',
                    // 'MT_RECV_SYSTEM_MSG', //群名修改 &&// 你已添加了天空蔚蓝，现在可以开始聊天了。
                    'MT_RECV_LINK_MSG',
                    'MT_TRANS_VOICE_MSG',
                ];
                $switchOn = $config['isChatwootOn'];
                if($switchOn&&in_array($type,$recordWechatMessageTypes)){// !$isRoom && 暂不记录群消息
                    if($fromWxid != $wechatBot->wxid){
                        $chatwoot = new Chatwoot($wechatBot);
                        $wxid = $isRoom?$conversationWxid:$fromWxid;//roomWxid
                        $contact = $chatwoot->getContactByWxid($wxid);
                        $isHost = false;
                        if(!$contact) {
                            $wechatBotContact = WechatBotContact::query()
                                ->where('wechat_bot_id', $wechatBot->id)
                                ->where('wxid', $wxid)
                                ->first();

                            $contact = $chatwoot->saveContact($wechatBotContact);
                            // Add label // $label="群聊"
                            $label = $wechatBotContact::TYPES_NAME[$wechatBotContact->type];
                            $chatwoot->setLabelByContact($contact, $label);

                            $isHost = true;// 第一次创建对话，不发消息给微信用户，只记录到chatwoot
                        }
                        // 如果是群，加上by xx
                        if($isRoom){
                            // TODO save 群陌生人
                            $wechatBotContact = WechatBotContact::query()
                                ->where('wechat_bot_id', $wechatBot->id)
                                ->where('wxid', $fromWxid)
                                ->first();
                                $content .= "\r\n by {$wechatBotContact->contact->nickname}";
                        }
                        $chatwoot->sendMessageToContact($contact, $content, $isHost);
                        Log::debug(__CLASS__, [__LINE__, 'POST_TO_CHATWOOT', $content, $isHost]);
                    }
                }
                // end send message to chatwoo

            }
        }
        Log::info(__CLASS__, [__LINE__, $wechatClientName, $type, $wechatBot->wxid, '已执行到最后一行']);
        return response()->json(null);
    }
}
