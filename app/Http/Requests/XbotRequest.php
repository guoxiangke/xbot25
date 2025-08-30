<?php

namespace App\Http\Requests;

use App\Models\WechatClient;
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
            "MT_DATA_WXID_MSG" => '从网络获取信息',
            "MT_RECV_REVOKE_MSG" => 'xx 撤回了一条消息',
            'MT_DECRYPT_IMG_MSG' => '请求图片解密',
            "MT_DECRYPT_IMG_MSG_SUCCESS" => '图片解密成功',
            "MT_DECRYPT_IMG_MSG_TIMEOUT" => '图片解密超时',

            "MT_TALKER_CHANGE_MSG" => '切换了当前聊天对象',
        ];

        if(in_array($msgType, array_keys($ignoreMessageTypes))) {
            Log::info("忽略的消息类型:{$msgType}");
            throw new \Exception("忽略的消息类型:{$msgType}");
        }

        // 特殊处理客户端连接消息 - 不需要完整验证
        if($msgType == 'MT_CLIENT_CONTECTED') {
            return [
                'wechatClient' => $wechatClient,
                'currentWindows' => $currentWindows,
                'msgType' => $msgType,
                'clientId' => $clientId,
                'xbotWxid' => null,
                'requestAllData' => $requestAllData,
            ];
        }

        // 提取wxid from data field
        $requestData = $requestAllData['data'] ?? null;
        $xbotWxid = is_array($requestData) ? ($requestData['wxid'] ?? $requestData['to_wxid'] ?? null) : null;

        return [
            'wechatClient' => $wechatClient,
            'currentWindows' => $currentWindows,
            'msgType' => $msgType,
            'clientId' => $clientId,
            'xbotWxid' => $xbotWxid,
            'requestAllData' => $requestAllData,
        ];
    }
}