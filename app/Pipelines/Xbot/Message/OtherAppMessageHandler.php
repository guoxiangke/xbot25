<?php

namespace App\Pipelines\Xbot\Message;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 其他应用消息处理器
 * 处理 MT_RECV_OTHER_APP_MSG 类型的其他应用消息，转换为文本消息传递给 TextMessageHandler
 */
class OtherAppMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_OTHER_APP_MSG')) {
            return $next($context);
        }

        $wxType = $context->requestRawData['wx_type'] ?? '';
        $wxSubType = $context->requestRawData['wx_sub_type'] ?? '';
        $rawMsg = $context->requestRawData['raw_msg'] ?? '';

        // 只处理 wx_type = 49 的消息
        if ($wxType != 49) {
            $this->log('Other app message skipped (wx_type not 49)', [
                'wx_type' => $wxType,
                'wx_sub_type' => $wxSubType,
                'from' => $context->requestRawData['from_wxid'] ?? ''
            ]);
            return $next($context);
        }

        // 解析XML消息
        $xmlData = $this->parseAppMessageXml($rawMsg);

        // 根据 wx_sub_type 处理不同类型的应用消息
        $formattedMessage = $this->formatAppMessage($wxSubType, $xmlData, $context);

        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;

        // 修改 context 中的消息类型为文本消息
        $context->msgType = 'MT_RECV_TEXT_MSG';

        // 替换消息内容
        $context->requestRawData['msg'] = $formattedMessage;

        $this->log('Other app message converted to text', [
            'wx_type' => $wxType,
            'wx_sub_type' => $wxSubType,
            'formatted_message' => $formattedMessage,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 解析应用消息XML
     */
    private function parseAppMessageXml(string $rawMsg): array
    {
        if (empty($rawMsg)) {
            return [];
        }

        $xmlData = [];

        // 提取appmsg标签中的各种信息
        $fields = [
            'title' => '/<title>(.*?)<\/title>/',
            'des' => '/<des>(.*?)<\/des>/',
            'url' => '/<url>(.*?)<\/url>/',
            'dataurl' => '/<dataurl>(.*?)<\/dataurl>/',
            'sourcedisplayname' => '/<sourcedisplayname>(.*?)<\/sourcedisplayname>/',
        ];

        foreach ($fields as $field => $pattern) {
            if (preg_match($pattern, $rawMsg, $matches)) {
                $xmlData[$field] = html_entity_decode($matches[1]);
            }
        }

        // 提取finderFeed中的信息（视频号相关）
        if (preg_match('/<nickname>(.*?)<\/nickname>/', $rawMsg, $matches)) {
            $xmlData['finderFeed']['nickname'] = html_entity_decode($matches[1]);
        }
        if (preg_match('/<desc>(.*?)<\/desc>/', $rawMsg, $matches)) {
            $xmlData['finderFeed']['desc'] = html_entity_decode($matches[1]);
        }

        // 提取refermsg中的引用消息信息（用于引用回复）
        if (preg_match('/<refermsg>(.*?)<\/refermsg>/s', $rawMsg, $matches)) {
            $refermsgXml = $matches[1];

            // 解析引用消息的内容
            if (preg_match('/<content>(.*?)<\/content>/s', $refermsgXml, $contentMatches)) {
                $referContent = html_entity_decode($contentMatches[1]);

                // 如果引用的内容是XML格式，进一步解析
                if (str_contains($referContent, '<title>')) {
                    if (preg_match('/<title>(.*?)<\/title>/', $referContent, $titleMatches)) {
                        $xmlData['refermsg']['content'] = html_entity_decode($titleMatches[1]);
                    }
                } else {
                    $xmlData['refermsg']['content'] = $referContent;
                }
            }

            // 解析引用消息的发送者
            if (preg_match('/<displayname>(.*?)<\/displayname>/', $refermsgXml, $nameMatches)) {
                $xmlData['refermsg']['displayname'] = html_entity_decode($nameMatches[1]);
            }
        }

        return $xmlData;
    }

    /**
     * 根据子类型格式化应用消息
     */
    private function formatAppMessage(int $wxSubType, array $xmlData, XbotMessageContext $context): string
    {
        $title = $xmlData['title'] ?? '';
        $des = $xmlData['des'] ?? '';
        $url = $xmlData['url'] ?? '';
        $sourcedisplayname = $xmlData['sourcedisplayname'] ?? '';

        switch ($wxSubType) {
            case 3: // 音频消息
                $dataurl = $xmlData['dataurl'] ?? '';
                if (!empty($dataurl)) {
                    return "[音频消息]👉[点此收听]({$dataurl})👈\r\n标题：{$title}\r\n描述：{$des}";
                } else {
                    return "[音乐消息]👉[{$title}]👈";
                }

            case 19: // 聊天记录
                return "[聊天记录]👉[{$title}]👈\r\n{$des}";

            case 36: // 百度网盘
                return "[网盘文件]👉[点击查看]({$url})👈\r\n来源：{$sourcedisplayname}\r\n标题：{$title}\r\n描述：{$des}";

            case 51: // 视频号
                $nickname = $xmlData['finderFeed']['nickname'] ?? '';
                $desc = $xmlData['finderFeed']['desc'] ?? '';
                return "[视频号]👉[{$nickname}]👈\r\n{$desc}";

            case 57: // 引用回复
                $referContent = $xmlData['refermsg']['content'] ?? '';
                $referDisplayName = $xmlData['refermsg']['displayname'] ?? '';

                // 格式化为两行显示：第一行是回复内容，第二行是引用内容
                $formattedMessage = $title;
                if (!empty($referContent)) {
                    $referUser = !empty($referDisplayName) ? $referDisplayName : '未知用户';
                    // 个人对话不需要显示 (from user)，群对话需要显示
                    if ($context->isRoom) {
                        $formattedMessage = "[引用消息]{$title}\n[引用内容]{$referContent} \n[内容来自]{$referUser}";
                    } else {
                        $formattedMessage = "[引用消息]{$title}\n[引用内容]{$referContent}";
                    }
                }

                return $formattedMessage;

            default: // 其他未处理消息
                return "[其他消息]👉[请到手机查看]👈";
        }
    }
}
