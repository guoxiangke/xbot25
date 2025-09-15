<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\XbotWebhookRequest;
use App\Http\Resources\XbotResponseResource;
use App\Services\Dispatchers\MessageDispatcher;

/**
 * Xbot Webhook 控制器
 * 专注于HTTP请求处理，业务逻辑委托给MessageDispatcher
 */
class XbotWebhookController extends Controller
{
    private MessageDispatcher $messageDispatcher;

    public function __construct(MessageDispatcher $messageDispatcher)
    {
        $this->messageDispatcher = $messageDispatcher;
    }

    /**
     * 处理Xbot webhook请求
     */
    public function __invoke(XbotWebhookRequest $request, string $winToken)
    {
        try {
            $validatedData = $request->getValidatedData($winToken);
            $result = $this->messageDispatcher->dispatch($validatedData);

            return (new XbotResponseResource($result))->toResponse($request);
            
        } catch (\Exception $e) {
            return (new XbotResponseResource($e->getMessage()))->toResponse($request);
        }
    }
}