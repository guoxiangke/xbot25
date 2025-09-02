<?php

namespace App\Http\Controllers;

use App\Models\WechatBot;
use Illuminate\Http\Request;

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
            $xbot->sendText($request['to'], $request['data']['content']);
        } elseif ($request['type'] === 'at') {
            $xbot->sendAtText(
                $request['to'], 
                $request['data']['content'], 
                $request['data']['at']
            );
        }

        if (isset($request['addition'])) {
            if ($request['addition']['type'] === 'text') {
                $xbot->sendText($request['to'], $request['addition']['data']['content']);
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
                'message' => '未找到机器人实例'
            ];
        }

        $contacts = $wechatBot->getMeta('contacts', []);
        
        $friends = array_filter($contacts, function($contact) {
            return isset($contact['type']) && $contact['type'] == 1;
        });

        $page = request()->get('page', 1);
        $perPage = request()->get('per_page', 15);
        $total = count($friends);
        $friends = array_slice($friends, ($page - 1) * $perPage, $perPage);

        return [
            'data' => array_values($friends),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage),
        ];
    }
}