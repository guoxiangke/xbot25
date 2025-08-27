<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 系统消息处理器
 * 处理 MT_RECV_SYSTEM_MSG 类型的系统消息，转换为文本消息传递给 TextMessageHandler
 */
class SystemMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_SYSTEM_MSG')) {
            return $next($context);
        }

        $rawMsg = $context->requestRawData['raw_msg'] ?? '';
        
        // 格式化系统消息为文本格式
        // 参考 XbotCallbackController.php 第255-258行的逻辑：$content = $data['raw_msg'];
        $systemMessage = $this->formatSystemMessage($rawMsg);
        $formattedMessage = "[系统消息] {$systemMessage}";
        
        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改 context 中的消息类型为文本消息
        $context->msgType = 'MT_RECV_TEXT_MSG';
        
        // 替换消息内容
        $context->requestRawData['msg'] = $formattedMessage;

        $this->log('System message converted to text', [
            'raw_msg' => $rawMsg,
            'system_message' => $systemMessage,
            'formatted_message' => $formattedMessage,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 格式化系统消息
     * 参考 XbotCallbackController.php 第255-258行的逻辑
     */
    private function formatSystemMessage(string $rawMsg): string
    {
        if (empty($rawMsg)) {
            return '系统消息';
        }

        try {
            // 清理系统消息，移除多余的引号和格式
            $cleanedMessage = trim($rawMsg);
            
            // 移除开头和结尾的引号
            if (str_starts_with($cleanedMessage, '"') && str_ends_with($cleanedMessage, '"')) {
                $cleanedMessage = substr($cleanedMessage, 1, -1);
            }
            
            // 如果消息为空，使用默认文本
            return !empty($cleanedMessage) ? $cleanedMessage : '系统消息';
            
        } catch (\Exception $e) {
            $this->logError('Error formatting system message: ' . $e->getMessage(), [
                'raw_msg' => $rawMsg
            ]);
            return '系统消息';
        }
    }
}
