<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 表情消息处理器
 * 处理 MT_RECV_EMOJI_MSG 类型的表情消息，下载表情文件并转换为文本消息传递给 TextMessageHandler
 */
class EmojiMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_EMOJI_MSG')) {
            return $next($context);
        }

        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        // 解析表情XML数据
        $emojiData = $this->parseEmojiXml($rawMsg);

        // 获取表情CDN URL
        $emojiUrl = $this->getEmojiUrl($emojiData, $context);

        // 格式化表情消息为文本格式
        $formattedMessage = $this->formatEmojiMessage($emojiData, $emojiUrl);

        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改 context 中的消息类型为文本消息
        $context->msgType = 'MT_RECV_TEXT_MSG';

        // 替换消息内容
        $context->requestRawData['msg'] = $formattedMessage;

        $this->log('Emoji message processed and converted to text', [
            'emoji_md5' => $emojiData['md5'] ?? '',
            'emoji_size' => $emojiData['len'] ?? '',
            'emoji_dimensions' => ($emojiData['width'] ?? '') . 'x' . ($emojiData['height'] ?? ''),
            'emoji_url' => $emojiUrl,
            'formatted_message' => $formattedMessage,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 解析表情XML消息
     * 参考 ImageMessageHandler 的XML解析逻辑
     */
    private function parseEmojiXml(string $rawMsg): array
    {
        if (empty($rawMsg)) {
            return [];
        }

        $emojiData = [];

        // 提取emoji标签的属性
        if (preg_match('/<emoji\s+([^>]+)>/s', $rawMsg, $emojiMatches)) {
            $emojiTag = $emojiMatches[1];

            // 提取各种属性
            if (preg_match_all('/(\w+)\s*=\s*["\']([^"\']*)["\']/', $emojiTag, $attrMatches)) {
                foreach ($attrMatches[1] as $index => $attrName) {
                    $emojiData[$attrName] = html_entity_decode($attrMatches[2][$index]);
                }
            }
        }

        return $emojiData;
    }

    /**
     * 获取表情URL
     * 参考重构前的处理：直接使用CDN URL，不需要下载
     */
    private function getEmojiUrl(array $emojiData, XbotMessageContext $context): string
    {
        $cdnUrl = $emojiData['cdnurl'] ?? '';

        if (empty($cdnUrl)) {
            return '表情链接为空';
        }

        // 直接返回CDN URL，参考重构前的逻辑：$content = $xml['emoji']['@attributes']['cdnurl'];
        return $cdnUrl;
    }

    /**
     * 格式化表情消息
     */
    private function formatEmojiMessage(array $emojiData, string $emojiUrl): string
    {
        $width = $emojiData['width'] ?? '';
        $height = $emojiData['height'] ?? '';
        $size = $emojiData['len'] ?? '';

        $sizeInfo = '';
        if (!empty($width) && !empty($height)) {
            $sizeInfo = " ({$width}x{$height})";
        }

        if (!empty($size) && is_numeric($size)) {
            $sizeMb = round($size / (1024 * 1024), 2);
            $sizeInfo .= " {$sizeMb}M";
        }

        return "[表情消息]👉[点击查看]({$emojiUrl})👈{$sizeInfo}";
    }

}
