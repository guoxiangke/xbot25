<?php

namespace App\Services\Clients;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WeBridge 插件 HTTP 客户端
 *
 * 用于驱动另一台 iOS 微信发送消息，接口文档见《插件HTTP接口（v0.8.76）》
 * 所有端点均为 POST，接收方统一使用 receiver（wxid / 好友备注名 / 群ID / filehelper）
 */
class WebridgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
    ) {}

    public static function make(): self
    {
        return new self(
            rtrim(config('services.wechat_ios.base_url'), '/'),
            config('services.wechat_ios.app_id'),
        );
    }

    /**
     * 文本消息
     */
    public function sendText(string $receiver, string $content): Response
    {
        return $this->post('/send_message', [
            'receiver' => $receiver,
            'content' => $content,
        ]);
    }

    /**
     * 链接卡片
     */
    public function sendLink(
        string $receiver,
        string $url,
        string $title,
        string $description = '',
        string $thumbUrl = ''
    ): Response {
        return $this->post('/send_link_card', [
            'receiver' => $receiver,
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'thumb_url' => $thumbUrl,
        ]);
    }

    /**
     * 音乐卡片
     */
    public function sendMusic(
        string $receiver,
        string $dataUrl,
        string $title,
        string $description = '',
        string $albumUrl = ''
    ): Response {
        return $this->post('/send_music_card', [
            'app_id' => $this->appId,
            'receiver' => $receiver,
            'title' => $title,
            'desc' => $description,
            'song_album_url' => $albumUrl,
            'data_url' => $dataUrl,
        ]);
    }

    /**
     * 图片，image_path 支持远程 URL
     */
    public function sendImage(string $receiver, string $imageUrl): Response
    {
        return $this->post('/send_image', [
            'receiver' => $receiver,
            'image_path' => $imageUrl,
        ]);
    }

    private function post(string $path, array $payload): Response
    {
        return $this->request()->post($path, $payload);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->timeout(30);
    }
}
