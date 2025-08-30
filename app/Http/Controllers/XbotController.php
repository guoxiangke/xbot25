<?php

namespace App\Http\Controllers;

use App\Models\WechatBot;
use App\Services\Xbot;
use App\Http\Requests\XbotRequest;
use App\Services\XbotServices\MessageProcessor;

class XbotController extends Controller
{
    private $messageProcessor;

    public function __construct(
        MessageProcessor $messageProcessor
    ) {
        $this->messageProcessor = $messageProcessor;
    }

    public function __invoke(XbotRequest $request, string $winToken)
    {
        try {
            // 验证和准备请求参数
            $validatedData = $request->validateAndPrepare($winToken);
            extract($validatedData);

            // 获取 WechatBot 实例
            $wechatBot = $this->getWechatBot($xbotWxid, $wechatClient->id, $clientId);

            // 创建 Xbot 实例
            $xbot = new Xbot($wechatClient->endpoint, $xbotWxid, $clientId);

            // 处理消息
            $result = $this->messageProcessor->processMessage(
                $wechatBot,
                $requestAllData['data'] ?? [],
                $msgType,
                $wechatClient,
                $currentWindows,
                $xbotWxid,
                $xbot,
                $clientId
            );

            return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 200, [], JSON_UNESCAPED_UNICODE);
        }
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
