<?php

namespace App\Services\XbotServices\State;

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\Xbot;
use App\Services\XbotServices\State\QrCodeStateHandler;
use Illuminate\Support\Facades\Log;

/**
 * 登录状态处理器
 * 负责处理用户登录逻辑
 */
class LoginStateHandler
{
    private $wechatClient;
    private $currentWindows;
    private $clientId;
    private $xbotWxid;
    private $xbot;
    private $qrCodeHandler;

    public function __construct(
        WechatClient $wechatClient,
        string $currentWindows,
        int $clientId,
        ?string $xbotWxid = null,
        ?Xbot $xbot = null
    ) {
        $this->wechatClient = $wechatClient;
        $this->currentWindows = $currentWindows;
        $this->clientId = $clientId;
        $this->xbotWxid = $xbotWxid;
        $this->xbot = $xbot;
        $this->qrCodeHandler = new QrCodeStateHandler($wechatClient, $currentWindows, $clientId);
    }

    /**
     * 处理用户登录
     */
    public function handle(array $requestRawData): string
    {
        $wechatBot = WechatBot::where('wxid', $requestRawData['wxid'])->first();
        $this->processLogin($requestRawData, $wechatBot);
        return "processed MT_USER_LOGIN";
    }

    /**
     * 处理登录逻辑
     */
    private function processLogin(array $requestRawData, ?WechatBot $wechatBot): void
    {
        // 登陆成功，则弹出对于clientId的所有 QR
        $this->qrCodeHandler->removeQrFromPool();

        if(!$wechatBot) {
            Log::warning(__CLASS__, [__LINE__, '未找到对应的WechatBot', $this->xbotWxid]);
            $wechatBot = WechatBot::create([
                'wxid' => $this->xbotWxid,
                'wechat_client_id' => $this->wechatClient->id,
                'name' => $requestRawData['nickname'],
                'client_id' => $this->clientId,
                'login_at' => now(),
                'is_live_at' => now(),
            ]);
        } else {
            // 更新登录时间
            $wechatBot->update([
                'name' => $requestRawData['nickname'],
                'login_at' => now(),
                'is_live_at' => now(),
                'client_id' => $this->clientId,
            ]);
            // 更新联系人数据，把机器人也作为一个联系人
            $wechatBot->handleContacts([$requestRawData]);
            Log::info(__CLASS__, [__LINE__, '更新WechatBot登录时间', $wechatBot->toArray()]);
        }

        $wechatBot->setMeta('xbot', $requestRawData);
        $this->xbot->sendTextMessage($this->xbotWxid, "恭喜！登陆成功，正在初始化...");

        // init Contacts
        $this->xbot->getFriendsList();
        $this->xbot->getChatroomsList();
        $this->xbot->getPublicAccountsList();
    }
}
