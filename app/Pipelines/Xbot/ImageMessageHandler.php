<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 图片消息处理器
 * 处理 MT_RECV_PICTURE_MSG 类型的图片消息，解密后转换为文本消息传递给 TextMessageHandler
 */
class ImageMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_PICTURE_MSG')) {
            return $next($context);
        }

        $rawMsg = $context->requestRawData['raw_msg'] ?? '';
        $srcFile = $context->requestRawData['image'] ?? '';

        // 解密图片并生成Web访问URL
        $imageUrl = $this->decryptAndGenerateImageUrl($srcFile, $rawMsg, $context);

        // 解析XML获取图片信息用于格式化
        $xmlData = $this->parseImageXml($rawMsg);
        
        // 格式化图片消息为文本格式
        $formattedMessage = $this->formatImageMessage($imageUrl, $xmlData);

        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改 context 中的消息类型为文本消息
        $context->msgType = 'MT_RECV_TEXT_MSG';

        // 替换消息内容
        $context->requestRawData['msg'] = $formattedMessage;

        $this->log('Image message decrypted and converted to text', [
            'src_file' => $srcFile,
            'image_url' => $imageUrl,
            'formatted_message' => $formattedMessage,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 解密图片并生成Web访问URL
     * 参考 XbotCallbackController.php 的图片处理逻辑
     */
    private function decryptAndGenerateImageUrl(string $srcFile, string $rawMsg, XbotMessageContext $context): string
    {
        if (empty($srcFile)) {
            return '图片文件路径为空';
        }

        try {
            // 获取微信客户端配置
            $wechatClient = $this->getWechatClientFromContext($context);
            if (!$wechatClient) {
                return '无法获取微信客户端配置';
            }

            // 解析XML获取图片信息
            $xmlData = $this->parseImageXml($rawMsg);
            $md5 = $xmlData['img']['@attributes']['md5'] ?? $context->msgId;
            $size = $xmlData['img']['@attributes']['hdlength'] ?? $xmlData['img']['@attributes']['length'] ?? 0;

            // 生成目标路径
            $date = date('Y-m');
            $path = "\\{$context->wechatBot->wxid}\\FileStorage\\Image\\{$date}";
            $destFile = $wechatClient->file_path . $path . "\\{$md5}.png";

            // 解密图片
            $context->wechatBot->xbot()->decryptImageFile($srcFile, $destFile, $size);

            // 生成Web访问URL
            $webPath = str_replace('\\', '/', $path);
            $imageUrl = $wechatClient->file_url . $webPath . "/{$md5}.png";

            return $imageUrl;

        } catch (\Exception $e) {
            $this->logError('Error decrypting image: ' . $e->getMessage(), [
                'src_file' => $srcFile,
                'raw_msg' => $rawMsg
            ]);
            return '图片解密失败';
        }
    }

    /**
     * 解析图片XML消息
     * 参考 XbotCallbackController.php 的XML解析逻辑
     */
    private function parseImageXml(string $rawMsg): array
    {
        if (empty($rawMsg)) {
            return [];
        }

        try {
            // 简单的XML解析，提取img标签及其属性
            $xmlData = [];

            // 提取img标签的属性
            if (preg_match('/<img\s+([^>]+)>/s', $rawMsg, $imgMatches)) {
                $imgTag = $imgMatches[1];

                // 提取各种属性
                $attributes = [];
                if (preg_match_all('/(\w+)\s*=\s*["\']([^"\']*)["\']/', $imgTag, $attrMatches)) {
                    foreach ($attrMatches[1] as $index => $attrName) {
                        $attributes[$attrName] = $attrMatches[2][$index];
                    }
                }

                $xmlData['img']['@attributes'] = $attributes;
            }

            return $xmlData;

        } catch (\Exception $e) {
            $this->logError('Error parsing image XML: ' . $e->getMessage(), [
                'raw_msg' => $rawMsg
            ]);
            return [];
        }
    }

    /**
     * 格式化图片消息
     */
    private function formatImageMessage(string $imageUrl, array $xmlData): string
    {
        $attributes = $xmlData['img']['@attributes'] ?? [];
        $width = $attributes['hdwidth'] ?? $attributes['width'] ?? '';
        $height = $attributes['hdheight'] ?? $attributes['height'] ?? '';
        $size = $attributes['hdlength'] ?? $attributes['length'] ?? '';
        
        $sizeInfo = '';
        
        // 添加尺寸信息
        if (!empty($width) && !empty($height)) {
            $sizeInfo = " ({$width}x{$height})";
        }
        
        // 添加文件大小信息，统一显示为MB
        if (!empty($size) && is_numeric($size)) {
            $sizeMb = round($size / (1024 * 1024), 2);
            $sizeInfo .= " {$sizeMb}M";
        }

        return "[图片消息]👉[点击查看]({$imageUrl})👈{$sizeInfo}";
    }

    /**
     * 从Context中获取WechatClient
     */
    private function getWechatClientFromContext(XbotMessageContext $context)
    {
        try {
            return $context->wechatBot->wechatClient;
        } catch (\Exception $e) {
            $this->logError('Error getting WechatClient: ' . $e->getMessage());
            return null;
        }
    }
}
