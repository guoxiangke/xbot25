<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 文件/视频消息处理器
 * 处理 MT_RECV_FILE_MSG 和 MT_RECV_VIDEO_MSG 类型的消息，转换为文本消息传递给 TextMessageHandler
 */
class FileVideoMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, ['MT_RECV_FILE_MSG', 'MT_RECV_VIDEO_MSG'])) {
            return $next($context);
        }

        // 根据消息类型确定数据字段
        $isFile = $this->isMessageType($context, 'MT_RECV_FILE_MSG');
        $field = $isFile ? 'file' : 'video';

        $fileData = $context->requestRawData[$field] ?? '';

        // 根据文件扩展名确定显示文本
        $fileType = $this->getFileType($fileData);
        $typeText = $this->getFileTypeText($fileType);
        $unknownText = $this->getUnknownFileText($fileType);

        // 解析XML获取文件信息
        $rawMsg = $context->requestRawData['raw_msg'] ?? '';
        $xmlData = $this->parseFileXml($rawMsg);
        
        // 格式化文件/视频消息为文本格式
        // 参考 XbotCallbackController.php 第471-483行的逻辑
        $fileUrl = $this->formatFileUrl($fileData, $context, $unknownText);
        $sizeInfo = $this->getFileSizeInfoFromXml($xmlData);
        $formattedMessage = "[{$typeText}]👉[点击查看]({$fileUrl})👈{$sizeInfo}";

        // 保存原始消息类型
        $context->requestRawData['origin_msg_type'] = $context->msgType;
        
        // 修改 context 中的消息类型为文本消息
        $context->msgType = 'MT_RECV_TEXT_MSG';

        // 替换消息内容
        $context->requestRawData['msg'] = $formattedMessage;

        $this->log('File/Video message converted to text', [
            'message_type' => $context->msgType,
            'file_data' => $fileData,
            'file_type' => $fileType,
            'file_url' => $fileUrl,
            'formatted_message' => $formattedMessage,
            'from' => $context->requestRawData['from_wxid'] ?? ''
        ]);

        return $next($context);
    }

    /**
     * 格式化文件/视频URL
     * 参考 XbotCallbackController.php 第472-475行的逻辑
     */
    private function formatFileUrl(string $fileData, XbotMessageContext $context, string $unknownText): string
    {
        if (empty($fileData)) {
            return $unknownText;
        }

        try {
            // 获取微信客户端配置
            $wechatClient = $this->getWechatClientFromContext($context);
            if (!$wechatClient) {
                return $unknownText;
            }

            // 移除Windows路径前缀，转换为Web路径
            // 参考：$file = str_replace($wechatClient->file_path, '', $originPath);
            $relativePath = str_replace($wechatClient->file_path, '', $fileData);

            // 转换路径分隔符并拼接域名
            // 参考：$content = $wechatClient->file_url . $content;
            $webPath = str_replace('\\', '/', $relativePath);
            $fileUrl = $wechatClient->file_url . $webPath;

            return $fileUrl;

        } catch (\Exception $e) {
            $this->logError('Error formatting file/video URL: ' . $e->getMessage(), [
                'file_data' => $fileData
            ]);
            return $unknownText;
        }
    }

    /**
     * 判断文件是否为视频文件
     */
    private function isVideoFile(string $filePath): bool
    {
        if (empty($filePath)) {
            return false;
        }

        // 常见视频文件扩展名
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', '3gp'];

        // 获取文件扩展名
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, $videoExtensions);
    }

    /**
     * 获取文件类型
     */
    private function getFileType(string $filePath): string
    {
        if (empty($filePath)) {
            return 'unknown';
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // 视频文件
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', '3gp'];
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }

        // 音频文件
        $audioExtensions = ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a', 'wma'];
        if (in_array($extension, $audioExtensions)) {
            return 'audio';
        }

        // 图片文件
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico'];
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }

        // 文档文件
        $documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'];
        if (in_array($extension, $documentExtensions)) {
            return 'document';
        }

        // 表格文件
        $spreadsheetExtensions = ['xls', 'xlsx', 'csv', 'ods'];
        if (in_array($extension, $spreadsheetExtensions)) {
            return 'spreadsheet';
        }

        // 演示文稿
        $presentationExtensions = ['ppt', 'pptx', 'odp'];
        if (in_array($extension, $presentationExtensions)) {
            return 'presentation';
        }

        // 压缩文件
        $archiveExtensions = ['zip', 'rar', '7z', 'tar', 'gz'];
        if (in_array($extension, $archiveExtensions)) {
            return 'archive';
        }

        // 代码文件
        $codeExtensions = ['js', 'ts', 'php', 'py', 'java', 'c', 'cpp', 'html', 'css', 'xml', 'json'];
        if (in_array($extension, $codeExtensions)) {
            return 'code';
        }

        return 'file';
    }

    /**
     * 获取文件类型显示文本
     */
    private function getFileTypeText(string $fileType): string
    {
        $typeMap = [
            'video' => '视频消息',
            'audio' => '音频消息',
            'image' => '图片消息',
            'document' => '文档消息',
            'spreadsheet' => '表格消息',
            'presentation' => '演示文稿',
            'archive' => '压缩文件',
            'code' => '代码文件',
            'file' => '文件消息',
            'unknown' => '未知文件'
        ];

        return $typeMap[$fileType] ?? '文件消息';
    }

    /**
     * 获取未知文件显示文本
     */
    private function getUnknownFileText(string $fileType): string
    {
        $typeMap = [
            'video' => '未知视频',
            'audio' => '未知音频',
            'image' => '未知图片',
            'document' => '未知文档',
            'spreadsheet' => '未知表格',
            'presentation' => '未知演示文稿',
            'archive' => '未知压缩文件',
            'code' => '未知代码文件',
            'file' => '未知文件',
            'unknown' => '未知文件'
        ];

        return $typeMap[$fileType] ?? '未知文件';
    }

    /**
     * 解析文件XML消息
     * 参考 ImageMessageHandler 的XML解析逻辑
     */
    private function parseFileXml(string $rawMsg): array
    {
        if (empty($rawMsg)) {
            return [];
        }

        $xmlData = [];

        // 提取appattach标签中的totallen（文件大小）
        if (preg_match('/<totallen>(\d+)<\/totallen>/', $rawMsg, $matches)) {
            $xmlData['totallen'] = $matches[1];
        }

        // 提取title（文件名）
        if (preg_match('/<title>(.*?)<\/title>/', $rawMsg, $matches)) {
            $xmlData['title'] = $matches[1];
        }

        // 提取fileext（文件扩展名）
        if (preg_match('/<fileext>(.*?)<\/fileext>/', $rawMsg, $matches)) {
            $xmlData['fileext'] = $matches[1];
        }

        return $xmlData;
    }

    /**
     * 从XML数据获取文件大小信息
     */
    private function getFileSizeInfoFromXml(array $xmlData): string
    {
        $fileSize = $xmlData['totallen'] ?? '';
        
        if (empty($fileSize) || !is_numeric($fileSize) || $fileSize <= 0) {
            return '';
        }

        $fileSize = intval($fileSize);

        // 格式化文件大小，统一显示为MB
        $sizeMb = round($fileSize / (1024 * 1024), 2);
        return " {$sizeMb}M";
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
