<?php

namespace App\Http\Requests;

use App\Models\WechatClient;
use App\Models\WechatBot;
use App\Services\Xbot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

/**
 * Xbot 请求验证
 * 负责验证和初始化请求参数
 */
class XbotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string',
            'client_id' => 'required|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => '参数错误: no msg.type',
            'client_id.required' => '参数错误: no client_id',
        ];
    }

    public function validateAndPrepare(string $winToken): array
    {
        $currentWindows = $winToken;

        // 验证Windows机器
        $wechatClient = WechatClient::where('token', $currentWindows)->first();
        if(!$wechatClient) {
            throw new \Exception('找不到windows机器');
        }

        $msgType = $this->input('type');
        $clientId = $this->input('client_id');
        $requestAllData = $this->all();

        // 检查忽略的消息类型
        $ignoreMessageTypes = [
            'MT_INJECT_WECHAT' => '新开了一个客户端！ 但没有回调给laravel',
            "MT_DEBUG_LOG" =>'调试信息',
            'MT_RECV_MINIAPP_MSG' => '小程序信息',
            "MT_WX_WND_CHANGE_MSG"=>'',
            "MT_UNREAD_MSG_COUNT_CHANGE_MSG" => '未读消息',
            //"MT_DATA_WXID_MSG" => '从网络获取信息',
            "MT_RECV_REVOKE_MSG" => 'xx 撤回了一条消息',
            'MT_DECRYPT_IMG_MSG' => '请求图片解密',
            "MT_DECRYPT_IMG_MSG_SUCCESS" => '图片解密成功',
            "MT_DECRYPT_IMG_MSG_TIMEOUT" => '图片解密超时',

            "MT_TALKER_CHANGE_MSG" => '切换了当前聊天对象',

            // 暂时忽略的消息类型 暂不处理 @see $this->fillMissingWxidFields($msgType, $requestRawData, $wechatBot);
            "MT_ROOM_ADD_MEMBER_NOTIFY_MSG" => '',
            "MT_ROOM_CREATE_NOTIFY_MSG" => '',
            "MT_ROOM_DEL_MEMBER_NOTIFY_MSG" => '',
            "MT_CONTACT_ADD_NOITFY_MSG" => '',
            "MT_CONTACT_DEL_NOTIFY_MSG" => '',
            "MT_ZOMBIE_CHECK_MSG" => '',
            "MT_SEARCH_CONTACT_MSG" => '',

        ];

        if(in_array($msgType, array_keys($ignoreMessageTypes))) {
            Log::info("忽略的消息类型:{$msgType}");
            throw new \Exception("忽略的消息类型:{$msgType}");
        }

        // 特殊处理客户端连接消息 - 不需要完整验证
        if($msgType == 'MT_CLIENT_CONTECTED') {
            $xbotWxid = null; // 强制使用client_id查找
        }

        // 提取wxid from data field
        $requestData = $requestAllData['data'] ?? null;

        // 提取bot的wxid
        // 这些消息类型需要强制使用client_id查找，而不是通过wxid查找
        $forceClientIdLookupTypes = [
            'MT_DATA_WXID_MSG',      // MT_DATA_WXID_MSG中data.wxid是目标联系人的wxid，不是bot的wxid
            'MT_TRANS_VOICE_MSG',    // 语音转文字消息：使用client_id查找bot
            'MT_RECV_SYSTEM_MSG',    // 系统消息：from_wxid通常不是bot的wxid（可能是操作者或群wxid）
            'MT_RECV_OTHER_APP_MSG', // 其他应用消息：使用client_id查找，避免wxid匹配失败
        ];

        if (in_array($msgType, $forceClientIdLookupTypes)) {
            $xbotWxid = null; // 强制使用client_id查找
        } elseif (is_array($requestData) && !empty($requestData['room_wxid']) && $requestData['room_wxid'] !== '') {
            // 群消息：from_wxid可能是群成员，不是bot，应该使用client_id查找
            $xbotWxid = null;
        } else {
            // 普通消息：使用to_wxid（接收方是bot）或from_wxid（发送方是bot）
            $xbotWxid = is_array($requestData) ? ($requestData['to_wxid'] ?? $requestData['from_wxid'] ?? $requestData['wxid'] ?? null) : null;
        }

        // 获取 WechatBot 实例
        $wechatBot = $this->getWechatBot($xbotWxid, $wechatClient->id, $clientId);

        // 创建 Xbot 实例
        $xbot = new Xbot($wechatClient->endpoint, $xbotWxid, $clientId);

        return [
            'wechatClient' => $wechatClient,
            'wechatBot' => $wechatBot,
            'xbot' => $xbot,
            'msgType' => $msgType,
            'clientId' => $clientId,
            'xbotWxid' => $xbotWxid,
            'requestAllData' => $requestAllData,
            'winToken' => $winToken,
        ];
    }

    private function getWechatBot(?string $xbotWxid, int $wechatClientId, int $clientId): ?WechatBot
    {
        return $xbotWxid
            ? WechatBot::where('wxid', $xbotWxid)->first()
            : WechatBot::where('wechat_client_id', $wechatClientId)
                      ->where('client_id', $clientId)
                      ->first();
    }
}
