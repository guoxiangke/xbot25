<?php

namespace App\Services\XbotServices\State;

use App\Models\WechatClient;
use Illuminate\Support\Facades\Cache;

/**
 * 二维码状态处理器
 * 负责处理二维码获取和QR池管理
 */
class QrCodeStateHandler
{
    private $wechatClient;
    private $currentWindows;
    private $clientId;
    private $cache;
    private $qrPoolCacheKey;

    public function __construct(
        WechatClient $wechatClient,
        string $currentWindows,
        int $clientId
    ) {
        $this->wechatClient = $wechatClient;
        $this->currentWindows = $currentWindows;
        $this->clientId = $clientId;
        $this->cache = Cache::store('file');
        $this->qrPoolCacheKey = "xbots.{$currentWindows}.qrPool";
    }

    /**
     * 处理二维码消息
     */
    public function handle(array $requestRawData)
    {
        // 首次初始化时发来的 二维码，data为空，需要响应为空即可
        if(!$requestRawData) {
            return '首次初始化时发来的 二维码，data为空?';
        }

        $this->processQrCode($requestRawData);
        return '获取到二维码url后 正在维护二维码登录池';
    }

    /**
     * 处理二维码逻辑
     */
    private function processQrCode(array $requestRawData): void
    {
        // 前端刷新获取二维码总是使用第一个QR，登陆成功，则弹出对于clientId的QR
        $qr = [
            'qr' => $requestRawData['code'],
            'client_id' => $this->clientId,
        ];
        $qrPool = $this->cache->get($this->qrPoolCacheKey, []);

        // 把池子中所有 client_id 相同的 QR 弹出
        foreach ($qrPool as $key => $value) {
            if($value['client_id'] == $this->clientId){
                unset($qrPool[$key]);
            }
        }
        array_unshift($qrPool, $qr);
        $this->cache->put($this->qrPoolCacheKey, $qrPool);
    }

    /**
     * 从QR池中移除指定客户端的二维码
     */
    public function removeQrFromPool(): void
    {
        $qrPool = $this->cache->get($this->qrPoolCacheKey, []);
        $qrPool = array_filter($qrPool, fn($value) => $value['client_id'] != $this->clientId);
        $this->cache->put($this->qrPoolCacheKey, $qrPool);
    }
}