<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 位置消息处理器
 * 处理 MT_RECV_LOCATION_MSG 类型的位置分享消息
 * 参考 XbotCallbackController.php 第318-339行的逻辑
 */
class LocationMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_LOCATION_MSG')) {
            return $next($context);
        }

        $data = $context->requestRawData;
        $rawMsg = $data['raw_msg'] ?? '';

        // 解析位置信息
        $locationInfo = $this->parseLocationXml($rawMsg);
        
        if (!$locationInfo) {
            $this->logError('Failed to parse location XML', ['raw_msg' => $rawMsg]);
            return $next($context);
        }

        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;

        // 转换为文本消息格式
        $this->convertToTextMessage($context, $locationInfo);

        $this->log('Location message processed', [
            'location_info' => $locationInfo,
            'from' => $context->fromWxid
        ]);

        return $next($context);
    }

    /**
     * 解析位置XML
     * 参考 XbotCallbackController.php 第319行的逻辑
     */
    private function parseLocationXml(string $rawMsg): ?array
    {
        if (empty($rawMsg)) {
            return null;
        }

        try {
            // 简单的XML属性解析
            $locationInfo = [];
            
            // 提取位置名称
            if (preg_match('/<label>(.*?)<\/label>/', $rawMsg, $matches)) {
                $locationInfo['label'] = trim($matches[1]);
            }

            // 提取纬度
            if (preg_match('/<lat>(.*?)<\/lat>/', $rawMsg, $matches)) {
                $locationInfo['lat'] = trim($matches[1]);
            }

            // 提取经度
            if (preg_match('/<lng>(.*?)<\/lng>/', $rawMsg, $matches)) {
                $locationInfo['lng'] = trim($matches[1]);
            }

            // 提取POI名称
            if (preg_match('/<poiname>(.*?)<\/poiname>/', $rawMsg, $matches)) {
                $locationInfo['poiname'] = trim($matches[1]);
            }

            if (!empty($locationInfo)) {
                return $locationInfo;
            }

            // 尝试解析属性格式
            if (preg_match_all('/(\w+)="([^"]*)"/', $rawMsg, $matches, PREG_SET_ORDER)) {
                $attrs = [];
                foreach ($matches as $match) {
                    $attrs[$match[1]] = $match[2];
                }
                if (!empty($attrs)) {
                    return $attrs;
                }
            }

        } catch (\Exception $e) {
            $this->logError('Error parsing location XML: ' . $e->getMessage(), [
                'raw_msg_preview' => substr($rawMsg, 0, 200)
            ]);
        }

        return null;
    }

    /**
     * 转换为文本消息
     * 参考 XbotCallbackController.php 第319行的逻辑
     */
    private function convertToTextMessage(XbotMessageContext $context, array $locationInfo): void
    {
        $content = '[位置消息]:';
        
        // 构建位置信息字符串
        $parts = [];
        if (isset($locationInfo['label'])) {
            $parts[] = $locationInfo['label'];
        }
        if (isset($locationInfo['poiname'])) {
            $parts[] = $locationInfo['poiname'];
        }
        if (isset($locationInfo['lat']) && isset($locationInfo['lng'])) {
            $parts[] = "坐标: {$locationInfo['lat']},{$locationInfo['lng']}";
        }

        if (!empty($parts)) {
            $content .= implode(' - ', $parts);
        } else {
            $content .= '位置分享';
        }

        // 修改为文本消息类型
        $context->msgType = 'MT_RECV_TEXT_MSG';
        $context->requestRawData['msg'] = $content;
    }
}