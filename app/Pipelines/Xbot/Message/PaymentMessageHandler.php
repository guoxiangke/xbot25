<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * 微信支付消息处理器
 * 处理 MT_RECV_WCPAY_MSG 类型的微信支付/转账消息
 * 参考 XbotCallbackController.php 第276-315行的逻辑
 */
class PaymentMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_WCPAY_MSG')) {
            return $next($context);
        }

        $data = $context->requestRawData;
        $rawMsg = $data['raw_msg'] ?? '';

        // 解析XML获取支付信息
        $paymentInfo = $this->parsePaymentXml($rawMsg);
        
        if (!$paymentInfo) {
            $this->logError('Failed to parse payment XML', ['raw_msg' => $rawMsg]);
            return $next($context);
        }

        // 保存原始消息类型
        $context->setMetadata('origin_msg_type', $context->msgType);

        // 判断是否自动收款
        $isAutoWcpay = $context->wechatBot->getMeta('isAutoWcpay', false);
        
        if ($isAutoWcpay) {
            $this->handleAutoPayment($context, $paymentInfo);
        } else {
            // 不自动收款，转换为文本消息继续处理
            $this->convertToTextMessage($context, $paymentInfo);
            return $next($context);
        }

        $context->markAsProcessed(static::class);
        return $context;
    }

    /**
     * 解析支付XML
     * 参考 XbotCallbackController.php 第279-281行的逻辑
     */
    private function parsePaymentXml(string $rawMsg): ?array
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            // 简单的XML解析逻辑
            if (str_contains($rawMsg, '<appmsg>')) {
                // 提取转账ID
                $transferId = null;
                if (preg_match('/<transferid>(.*?)<\/transferid>/', $rawMsg, $matches)) {
                    $transferId = trim($matches[1]);
                }

                // 提取金额描述
                $feedesc = null;
                if (preg_match('/<feedesc>(.*?)<\/feedesc>/', $rawMsg, $matches)) {
                    $feedesc = trim($matches[1]);
                }

                // 提取付款描述
                $payMemo = null;
                if (preg_match('/<pay_memo>(.*?)<\/pay_memo>/', $rawMsg, $matches)) {
                    $payMemo = trim($matches[1]);
                }

                if ($transferId && $feedesc) {
                    return [
                        'transferid' => $transferId,
                        'feedesc' => $feedesc,
                        'pay_memo' => $payMemo,
                        'amount' => $this->parseAmount($feedesc)
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logError('Error parsing payment XML: ' . $e->getMessage(), [
                'raw_msg_preview' => substr($rawMsg, 0, 200)
            ]);
        }

        return null;
    }

    /**
     * 解析金额
     * 参考 XbotCallbackController.php 第281行的逻辑
     */
    private function parseAmount(string $feedesc): int
    {
        // 格式："￥0.10" => 提取 0.10 * 100 = 10分
        if (preg_match('/￥([\d.]+)/', $feedesc, $matches)) {
            $amount = (float)$matches[1] * 100;
            return (int)$amount;
        }
        return 0;
    }

    /**
     * 处理自动收款
     * 参考 XbotCallbackController.php 第283-287行的逻辑
     */
    private function handleAutoPayment(XbotMessageContext $context, array $paymentInfo): void
    {
        $transferId = $paymentInfo['transferid'];
        $amount = $paymentInfo['amount'];

        // 测试退款逻辑：只退回1分钱
        if ($amount == 1) {
            $context->wechatBot->xbot()->refund($transferId);
            $this->log('Auto refund processed', ['transferid' => $transferId, 'amount' => $amount]);
            return;
        }

        // 自动收款
        $context->wechatBot->xbot()->autoAcceptTranster($transferId);
        
        $this->log('Auto payment accepted', [
            'transferid' => $transferId,
            'amount' => $amount,
            'feedesc' => $paymentInfo['feedesc']
        ]);
    }

    /**
     * 转换为文本消息
     * 参考 XbotCallbackController.php 第299行的逻辑
     */
    private function convertToTextMessage(XbotMessageContext $context, array $paymentInfo): void
    {
        $isSelf = $context->isFromBot;
        $feedesc = $paymentInfo['feedesc'];
        $payMemo = $paymentInfo['pay_memo'] ?? '';

        $content = $isSelf ? '[已收款]' : '[收到转账]';
        $content .= ':' . $feedesc;
        
        if (!empty($payMemo)) {
            $content .= ':附言:' . $payMemo;
        }

        // 修改为文本消息类型
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $content;
        
        $this->log('Payment message converted to text', [
            'content' => $content,
            'is_self' => $isSelf
        ]);
    }
}