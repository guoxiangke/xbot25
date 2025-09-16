<?php

namespace App\Services\Processors;

use App\Models\WechatClient;
use App\Models\WechatBot;
use App\Services\Clients\XbotClient;
use Illuminate\Support\Facades\Log;

/**
 * 请求处理器
 * 处理复杂的请求验证和数据准备逻辑
 */
class RequestProcessor
{
    /**
     * 验证并准备请求数据
     */
    public function validateAndPrepare(array $basicData, string $winToken): array
    {
        // 验证Windows机器
        $wechatClient = WechatClient::where('token', $winToken)->first();
        if (!$wechatClient) {
            throw new \Exception('找不到windows机器');
        }

        $msgType = $basicData['msgType'];
        $clientId = $basicData['clientId'];
        $requestAllData = $basicData['requestAllData'];
        $requestRawData = $requestAllData['data'] ?? [];

        // 检查忽略的消息类型
        $this->checkIgnoredMessageTypes($msgType);

        // 提取机器人wxid
        $xbotWxid = $this->extractXbotWxid($msgType, $requestRawData);
        
        // 获取WechatBot实例
        $wechatBot = $this->getWechatBot($wechatClient, $clientId, $xbotWxid);
        
        // 创建Xbot客户端
        $xbot = $this->createXbotClient($wechatClient, $wechatBot, $clientId);
        
        // 检查是否为群消息
        $isRoom = $this->isRoomMessage($requestRawData);
        $roomWxid = $isRoom ? $requestRawData['room_wxid'] : null;

        return [
            'msgType' => $msgType,
            'clientId' => $clientId,
            'requestAllData' => $requestAllData,
            'requestRawData' => $requestRawData,
            'wechatClient' => $wechatClient,
            'wechatBot' => $wechatBot,
            'xbot' => $xbot,
            'winToken' => $winToken,
            'xbotWxid' => $xbotWxid,
            'isRoom' => $isRoom,
            'roomWxid' => $roomWxid,
        ];
    }

    /**
     * 提取机器人wxid
     * 优先使用 client_id 查找，只有特殊情况才通过 wxid 查找
     */
    private function extractXbotWxid(string $msgType, array $requestRawData): ?string
    {
        // 大部分消息类型都优先使用 client_id 查找（更可靠）
        // 只有特殊消息类型才需要通过 wxid 查找
        $specialWxidLookupTypes = [
            // 暂时为空，除非发现必须使用 wxid 查找的特殊情况
            // 例如某些系统消息或特殊通知消息
        ];

        // 如果不是特殊类型，优先使用 client_id 查找
        if (!in_array($msgType, $specialWxidLookupTypes)) {
            return null; // 返回 null 强制使用 client_id 查找
        }

        // 以下是备用的 wxid 提取逻辑（仅用于特殊消息类型）
        $fromWxid = $requestRawData['from_wxid'] ?? null;
        $toWxid = $requestRawData['to_wxid'] ?? null;
        $roomWxid = $requestRawData['room_wxid'] ?? null;

        // 群消息：优先使用 client_id 查找
        if (!empty($roomWxid)) {
            return null;
        }

        // 私聊消息：机器人自己的消息（系统消息）
        if ($fromWxid === $toWxid) {
            return $fromWxid; // 两个相同，都是机器人
        }

        // 其他情况也优先使用 client_id 查找
        return null;
    }

    /**
     * 获取WechatBot实例
     */
    private function getWechatBot(WechatClient $wechatClient, int $clientId, ?string $xbotWxid): ?WechatBot
    {
        if ($xbotWxid) {
            // 通过wxid查找
            return WechatBot::where('wxid', $xbotWxid)->first();
        } else {
            // 通过client信息查找
            return WechatBot::where('wechat_client_id', $wechatClient->id)
                ->where('client_id', $clientId)
                ->first();
        }
    }

    /**
     * 创建Xbot客户端实例
     */
    private function createXbotClient(WechatClient $wechatClient, ?WechatBot $wechatBot, int $clientId): XbotClient
    {
        $apiBaseUrl = $wechatClient->endpoint ?? 'http://localhost:8001';
        $botWxid = $wechatBot?->wxid;
        $fileStoragePath = $wechatClient->file_path ?? 'C:\Users\Administrator\Documents\WeChat Files';

        return new XbotClient($apiBaseUrl, $botWxid, $clientId, $fileStoragePath);
    }

    /**
     * 检查是否为群消息
     */
    private function isRoomMessage(array $requestRawData): bool
    {
        return !empty($requestRawData['room_wxid']);
    }

    /**
     * 检查忽略的消息类型
     */
    private function checkIgnoredMessageTypes(string $msgType): void
    {
        $ignoreMessageTypes = [
            'MT_INJECT_WECHAT' => '新开了一个客户端！但没有回调给laravel',
            'MT_DEBUG_LOG' => '调试信息',
            'MT_RECV_MINIAPP_MSG' => '小程序信息',
            'MT_WX_WND_CHANGE_MSG' => '窗口变化',
            'MT_UNREAD_MSG_COUNT_CHANGE_MSG' => '未读消息数量变化',
            'MT_RECV_REVOKE_MSG' => '撤回消息',
            'MT_DECRYPT_IMG_MSG' => '请求图片解密',
            'MT_DECRYPT_IMG_MSG_SUCCESS' => '图片解密成功',
            'MT_DECRYPT_IMG_MSG_TIMEOUT' => '图片解密超时',
            'MT_TALKER_CHANGE_MSG' => '切换了当前聊天对象',

            // 暂时忽略的消息类型
            'MT_ZOMBIE_CHECK_MSG' => '僵尸粉检测',
            'MT_SEARCH_CONTACT_MSG' => '搜索联系人',
        ];

        if (isset($ignoreMessageTypes[$msgType])) {
            Log::info("忽略的消息类型: {$msgType}", [
                'description' => $ignoreMessageTypes[$msgType]
            ]);
            throw new \Exception("忽略的消息类型: {$msgType}");
        }
    }
}