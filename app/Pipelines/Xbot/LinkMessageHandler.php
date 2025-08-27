<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 链接消息处理器
 * 处理 MT_RECV_LINK_MSG 类型的链接消息，转换为文本消息传递给 TextMessageHandler
 */
class LinkMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_LINK_MSG')) {
            return $next($context);
        }

        $fromWxid = $context->requestRawData['from_wxid'] ?? '';
        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        // 判断是否来自公众号（公众号wxid以gh_开头）
        $isGh = str_starts_with($fromWxid, 'gh_');

        // 从 XML 中提取链接信息
        $linkData = $this->extractLinkDataFromXml($rawMsg);
        $url = $linkData['url'] ?? '';

        if ($url) {
            $title = $linkData['title'] ?? '';
            $desc = $linkData['des'] ?? '';
            $sourceName = $linkData['sourcedisplayname'] ?? '';
            
            // 根据是否来自公众号格式化消息
            if ($isGh || str_starts_with($url, 'http://mp.weixin.qq.com/s?')) {
                $formattedMessage = "[公众号消息]👉[点击查看]({$url})👈\r\n来源：{$sourceName}\r\n标题：{$title}";
            } else {
                $formattedMessage = "[链接消息]👉[点击查看]({$url})👈\r\n标题：{$title}\r\n描述：{$desc}";
            }

            // 保存原始消息类型
            $context->requestRawData['origin_msg_type'] = $context->msgType;

            // 修改 context 中的消息类型为文本消息
            $context->msgType = 'MT_RECV_TEXT_MSG';

            // 替换消息内容
            $context->requestRawData['msg'] = $formattedMessage;

            $this->log('Link message converted to text', [
                'from_wxid' => $fromWxid,
                'is_gh' => $isGh,
                'original_url' => $url,
                'formatted_message' => $formattedMessage
            ]);
        } else {
            $this->logError('Failed to extract URL from link message', [
                'from_wxid' => $fromWxid,
                'raw_msg' => $rawMsg
            ]);
        }

        return $next($context);
    }

    /**
     * 从XML中提取链接数据
     * 包括 url, title, des 等信息
     */
    protected function extractLinkDataFromXml(string $rawMsg): array
    {
        if (empty($rawMsg)) {
            return [];
        }

        $linkData = [];

        // 提取appmsg中的各种信息
        $fields = [
            'url' => '/<url>(.*?)<\/url>/',
            'title' => '/<title>(.*?)<\/title>/',
            'des' => '/<des>(.*?)<\/des>/',
            'sourcedisplayname' => '/<sourcedisplayname>(.*?)<\/sourcedisplayname>/',
        ];

        foreach ($fields as $field => $pattern) {
            if (preg_match($pattern, $rawMsg, $matches)) {
                $value = trim($matches[1]);
                if (!empty($value)) {
                    $linkData[$field] = html_entity_decode($value);
                }
            }
        }

        return $linkData;
    }


    /**
     * 从XML中提取链接URL (保持向后兼容)
     */
    protected function extractUrlFromXml(string $rawMsg): ?string
    {
        $linkData = $this->extractLinkDataFromXml($rawMsg);
        return $linkData['url'] ?? null;
    }
}
