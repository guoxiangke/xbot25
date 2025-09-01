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
        // 默认使用client_id查找（更可靠），只有特殊情况才使用wxid查找
        $useWxidLookupTypes = [
            // 暂时没有发现需要特殊使用wxid查找的消息类型
            // 如果将来发现某些消息类型必须用wxid查找，可以添加到这里
        ];

        // 群消息强制使用client_id查找（from_wxid是群成员，不是bot）
        $isRoom = is_array($requestData) && !empty($requestData['room_wxid']) && $requestData['room_wxid'] !== '';
        
        if (in_array($msgType, $useWxidLookupTypes) && !$isRoom) {
            // 特殊消息类型：使用wxid查找
            $xbotWxid = is_array($requestData) ? ($requestData['to_wxid'] ?? $requestData['from_wxid'] ?? $requestData['wxid'] ?? null) : null;
        } else {
            // 默认情况：使用client_id查找（包括所有接收消息、群消息、系统消息等）
            $xbotWxid = null;
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
