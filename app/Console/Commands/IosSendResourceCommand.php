<?php

namespace App\Console\Commands;

use App\Models\WechatBot;
use App\Services\Clients\WebridgeClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * 通过另一台 iOS 微信（WeBridge 插件）发送资源
 *
 * 资源结构与项目内关键词/订阅发送完全一致：
 * type 决定发送方式，statistics 用于构造跳转统计链接，addition 递归发送
 *
 * 用法：php artisan ios 620 bluesky_still
 */
class IosSendResourceCommand extends Command
{
    protected $signature = 'ios
                            {keyword : 资源关键词，如 620 / 789 / 900}
                            {receiver : 接收者，wxid、好友备注名、群ID 或 filehelper}
                            {--bot=ios : 统计链接中的 bot 标识}';

    protected $description = '通过 iOS 微信发送资源（文字/链接/音乐/图片），自动递归 addition 并附加统计参数';

    public function handle(WebridgeClient $client): int
    {
        $keyword = $this->argument('keyword');
        $resource = $this->fetchResource($keyword);

        if (! $resource) {
            return self::FAILURE;
        }

        return $this->sendResource($client, $resource, $this->argument('receiver'))
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * 拉取关键词资源
     */
    private function fetchResource(string $keyword): ?array
    {
        $response = Http::timeout(15)->get(config('services.xbot.resource_endpoint').$keyword);

        if (! $response->successful()) {
            $this->error("获取资源失败[{$keyword}]：HTTP {$response->status()}");

            return null;
        }

        $resource = $response->json();

        if (! is_array($resource) || isset($resource['error'])) {
            $this->error("资源不存在[{$keyword}]：".($resource['error'] ?? '返回格式异常'));

            return null;
        }

        return $resource;
    }

    /**
     * 发送资源本体，并递归发送 addition
     */
    private function sendResource(WebridgeClient $client, array $resource, string $receiver): bool
    {
        $success = $this->sendOne($client, $resource, $receiver);

        if (isset($resource['addition']) && is_array($resource['addition'])) {
            $success = $this->sendResource($client, $resource['addition'], $receiver) && $success;
        }

        return $success;
    }

    /**
     * 按 type 发送单条内容
     */
    private function sendOne(WebridgeClient $client, array $resource, string $receiver): bool
    {
        if (! isset($resource['data'])) {
            return true;
        }

        $data = $resource['data'];
        $type = $resource['type'] ?? 'text';
        $bot = $this->option('bot');

        switch ($type) {
            case 'text':
                $content = WechatBot::filterSensitiveWords($data['content'] ?? '');
                $response = $client->sendText($receiver, $content);
                $label = "文字：{$content}";
                break;

            case 'image':
                $response = $client->sendImage($receiver, $data['url'] ?? '');
                $label = '图片：'.($data['url'] ?? '');
                break;

            case 'link':
                $url = $data['url'] ?? '';
                // 与项目内发送逻辑一致：仅对带统计信息的 .mp4 链接做重定向
                if (isset($resource['statistics']) && str_ends_with($url, '.mp4')) {
                    $url = WechatBot::buildTrackedUrl($resource['statistics'], $url, $receiver, $bot);
                }
                $title = WechatBot::filterSensitiveWords($data['title'] ?? '');
                $response = $client->sendLink(
                    $receiver,
                    $url,
                    $title,
                    WechatBot::filterDescription($data['description'] ?? ''),
                    $data['image'] ?? ''
                );
                $label = "链接：{$title}";
                break;

            case 'music':
                $url = $data['url'] ?? '';
                if (isset($resource['statistics'])) {
                    $url = WechatBot::buildTrackedUrl($resource['statistics'], $url, $receiver, $bot);
                }
                $title = WechatBot::filterSensitiveWords($data['title'] ?? '');
                $response = $client->sendMusic(
                    $receiver,
                    $url,
                    $title,
                    WechatBot::filterDescription($data['description'] ?? ''),
                    $data['image'] ?? ''
                );
                $label = "音乐：{$title}";
                break;

            default:
                $this->warn("未知资源类型，已跳过：{$type}");

                return true;
        }

        return $this->report($response, $label);
    }

    /**
     * WeBridge 以响应体的 ok 字段判断成败
     */
    private function report(Response $response, string $label): bool
    {
        if ($response->successful() && $response->json('ok') === true) {
            $this->info("已发送 {$label}");

            return true;
        }

        $this->error("发送失败 {$label}：HTTP {$response->status()} ".$response->body());

        return false;
    }
}
