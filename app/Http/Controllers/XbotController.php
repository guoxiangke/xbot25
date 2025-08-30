<?php

namespace App\Http\Controllers;

use App;
use App\Services\Xbot\XbotService;
use App\Services\Xbot\XbotRequestValidator;
use App\Services\Xbot\XbotBotManager;
use App\Services\Xbot\XbotMessageProcessor;
use Illuminate\Http\Request;

class XbotController extends Controller
{
    private $requestValidator;
    private $botManager;
    private $messageProcessor;

    public function __construct(
        XbotRequestValidator $requestValidator,
        XbotBotManager $botManager,
        XbotMessageProcessor $messageProcessor
    ) {
        $this->requestValidator = $requestValidator;
        $this->botManager = $botManager;
        $this->messageProcessor = $messageProcessor;
    }

    public function __invoke(Request $request, string $winToken)
    {
        try {
            // 验证和准备请求参数
            $validatedData = $this->requestValidator->validateAndPrepare($request, $winToken);
            extract($validatedData);

            // 获取 WechatBot 实例
            $wechatBot = $this->botManager->getWechatBot($xbotWxid, $wechatClient, $clientId, $requestAllData);

            // 创建 XbotService 实例
            $xbot = new XbotService($wechatClient->endpoint, $xbotWxid, $clientId);

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
}
