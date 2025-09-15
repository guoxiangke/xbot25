<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WechatBot;
use Illuminate\Http\Request;

/**
 * WeChat API 控制器
 * 提供微信相关的API接口
 */
class WechatApiController extends Controller
{
    public function send(Request $request)
    {
        $bindUserId = auth()->id();
        if (!$bindUserId) {
            return [
                'success' => false,
                'message' => '用户绑定数据认证失败！请检查/重新生成token'
            ];
        }

        $wechatBot = WechatBot::where('user_id', $bindUserId)
            ->whereNotNull('client_id')
            ->whereNotNull('is_live_at')
            ->first();

        if (!$wechatBot) {
            return [
                'success' => false,
                'message' => '设备不在线,或改用户未绑定设备'
            ];
        }

        $xbot = $wechatBot->xbot();
        
        if ($request['type'] === 'text') {
            $xbot->sendTextMessage($request['to'], $request['data']['content']);
        } elseif ($request['type'] === 'at') {
            $xbot->sendAtMessage(
                $request['to'], 
                $request['data']['content'], 
                $request['data']['at']
            );
        }

        if (isset($request['addition'])) {
            if ($request['addition']['type'] === 'text') {
                $xbot->sendTextMessage($request['to'], $request['addition']['data']['content']);
            }
        }

        return [
            'success' => true,
            'message' => '已提交设备发送',
        ];
    }

    public function add(Request $request)
    {
        $wechatBot = WechatBot::where('user_id', auth()->id())
            ->whereNotNull('client_id')
            ->whereNotNull('login_at')
            ->first();

        if (!$wechatBot) {
            return [
                'success' => false,
                'message' => '设备不在线'
            ];
        }

        $xbot = $wechatBot->xbot();
        return $xbot->addFriendBySearch($request['telephone'], $request['message'] ?? "Hi");
    }

    public function getFriends()
    {
        $wechatBot = WechatBot::where('user_id', auth()->id())->first();
        
        if (!$wechatBot) {
            return [
                'success' => false,
                'message' => '用户未绑定设备',
                'data' => []
            ];
        }

        $contacts = $wechatBot->getMeta('contacts', []);
        
        // 过滤出好友联系人（假设type=1是好友）
        $friends = array_filter($contacts, function($contact) {
            return ($contact['type'] ?? 0) == 1;
        });

        return [
            'success' => true,
            'message' => 'success',
            'data' => array_values($friends) // 重新索引数组
        ];
    }
}