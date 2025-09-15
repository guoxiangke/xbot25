<?php

namespace App\Http\Requests;

use App\Services\Processors\RequestProcessor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Xbot Webhook 请求验证
 * 专注于数据验证，业务逻辑委托给RequestProcessor
 */
class XbotWebhookRequest extends FormRequest
{
    private RequestProcessor $requestProcessor;

    public function __construct(RequestProcessor $requestProcessor)
    {
        $this->requestProcessor = $requestProcessor;
        parent::__construct();
    }

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

    /**
     * 获取验证后的数据
     * 委托给RequestProcessor处理复杂业务逻辑
     */
    public function getValidatedData(string $winToken): array
    {
        $basicData = [
            'msgType' => $this->input('type'),
            'clientId' => $this->input('client_id'),
            'requestAllData' => $this->all(),
        ];
        return $this->requestProcessor->validateAndPrepare($basicData, $winToken);
    }
}