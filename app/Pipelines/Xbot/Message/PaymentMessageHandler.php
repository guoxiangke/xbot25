<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * 微信支付消息处理器
 * 处理 MT_RECV_WCPAY_MSG 类型的微信支付/转账消息
 * 默认自动收款，如果金额为1分钱则自动退款
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

        // 保存原始消息类型（用于Chatwoot）
        $context->requestRawData['origin_msg_type'] = $context->msgType;

        // 检查配置是否开启自动收款（默认开启）
        $configManager = new ConfigManager($context->wechatBot);
        $autoPaymentEnabled = $configManager->get('payment_auto');

        if ($autoPaymentEnabled) {
            // 自动收款处理
            $this->handleAutoPayment($context, $paymentInfo);
        } else {
            $this->log(__FUNCTION__, ['message' => 'Auto payment is disabled',
                'transferid' => $paymentInfo['transferid'],
                'feedesc' => $paymentInfo['feedesc']
            ]);
        }
        
        // 转换为文本消息继续处理，发送到Chatwoot
        $this->convertToTextMessage($context, $paymentInfo);
        
        // 不标记为已处理，让消息继续流入TextMessageHandler和ChatwootHandler
        return $next($context);
    }

    /**
     * 解析支付XML
     * 参考 XbotCallbackController.php 第279-281行的逻辑
     * 使用simplexml_load_string函数与JSON解码解析XML
     */
    private function parsePaymentXml(string $rawMsg): ?array
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            // 使用simplexml_load_string解析XML，与helpers.php中的xStringToArray功能一致
            $xmlObject = simplexml_load_string($rawMsg, null, LIBXML_NOCDATA);
            if ($xmlObject === false) {
                $this->logError('Failed to parse XML with simplexml_load_string', [
                    'raw_msg_preview' => substr($rawMsg, 0, 200)
                ]);
                return null;
            }

            // 转换为数组
            $xml = json_decode(json_encode($xmlObject), true);
            
            // 检查是否有支付信息结构
            if (!isset($xml['appmsg']['wcpayinfo'])) {
                $this->logError('No wcpayinfo found in XML structure', [
                    'xml_structure' => array_keys($xml['appmsg'] ?? [])
                ]);
                return null;
            }

            $wcpayinfo = $xml['appmsg']['wcpayinfo'];
            
            // 提取支付信息
            $transferId = $wcpayinfo['transferid'] ?? null;
            $feedesc = $wcpayinfo['feedesc'] ?? null;
            $payMemo = $wcpayinfo['pay_memo'] ?? '';
            $paySubtype = $wcpayinfo['paysubtype'] ?? null;

            if (!$transferId || !$feedesc) {
                $this->logError('Missing required payment info', [
                    'transferid' => $transferId,
                    'feedesc' => $feedesc,
                    'wcpayinfo_keys' => array_keys($wcpayinfo)
                ]);
                return null;
            }

            return [
                'transferid' => $transferId,
                'feedesc' => $feedesc,
                'pay_memo' => $payMemo,
                'amount' => $this->parseAmount($feedesc),
                'paysubtype' => $paySubtype
            ];
            
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
            $this->log(__FUNCTION__, ['message' => 'Auto refund processed', 'transferid' => $transferId, 'amount' => $amount]);
            return;
        }

        // 自动收款
        $context->wechatBot->xbot()->autoAcceptTranster($transferId);
        
        $this->log(__FUNCTION__, ['message' => 'Auto payment accepted',
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
        $paySubtype = $paymentInfo['paysubtype'] ?? null;
        $amount = $paymentInfo['amount'];

        // 根据paysubtype和消息发送者判断消息类型
        // paysubtype=1: 收到转账（联系人发起）
        // paysubtype=4: 已收款/已退款（机器人回应）
        
        if ($paySubtype == 4) {
            // 机器人的回应消息：判断是收款还是退款
            if ($amount == 1) {
                $content = '[已退款]';
            } else {
                $content = '[已收款]';
            }
            $context->isFromBot = true; // 机器人发送的消息
        } else {
            // 联系人发起的转账消息
            $content = '[收到转账]';
            $context->isFromBot = false; // 联系人发送的消息
        }
        
        $content .= ' ' . $feedesc; // 使用空格而不是冒号
        
        if (!empty($payMemo)) {
            $content .= "\n[附言]: " . $payMemo; // 使用空格分隔
        }

        // 修改为文本消息类型
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $content;
        
        
        $this->log(__FUNCTION__, ['message' => 'Payment message converted to text',
            'content' => $content,
            'is_self' => $isSelf
        ]);
    }
}