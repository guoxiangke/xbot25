<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WechatBot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ChatwootWebhookController extends Controller
{
    /**
     * 处理来自 Chatwoot 的 webhook 请求
     */
    public function handle(Request $request, WechatBot $wechatBot)
    {
        $messageType = $request['message_type'];
        $event = $request['event'];
        $sourceId = $request['source_id'] ?? '';

        // 忽略xbot_agent发送的消息，避免循环
        if ($sourceId === 'xbot_agent') {
            return;
        }

        // 只处理outgoing消息的created和updated事件
        if ($messageType !== 'outgoing' || !in_array($event, ['message_created', 'message_updated'])) {
            return;
        }

        // Log::error('debug chatwoot webhook', [$request->all()]);

        $toWxid = $request['conversation']['meta']['sender']['custom_attributes']['wxid'] ?? '';
        if (empty($toWxid)) {
            return;
        }

        // 处理文本内容（如果有）
        $content = $request['content'] ?? '';
        if (!empty($content)) {
            $wechatBot->xbot()->sendTextMessage($toWxid, $content);
            Cache::set("chatwoot_outgoing_{$wechatBot->id}_{$toWxid}", $content, 30);
        }

        // 处理附件
        $attachments = $request['attachments'] ?? [];
        foreach ($attachments as $attachment) {
            $fileType = $attachment['file_type'];
            $fileUrl = $attachment['data_url'];

            if ($fileType === 'image') {
                $wechatBot->xbot()->sendImageByUrl($toWxid, $fileUrl);

                // 缓存图片附件信息，用于避免重复发送到Chatwoot
                Cache::set("chatwoot_outgoing_attachment_{$wechatBot->id}_{$toWxid}_image", true, 30);

                Log::info('Chatwoot image sent to WeChat', [
                    'to_wxid' => $toWxid,
                    'file_url' => $fileUrl,
                    'attachment_id' => $attachment['id']
                ]);
            } elseif (in_array($fileType, ['audio', 'file', 'video'])) {
                $wechatBot->xbot()->sendFileByUrl($toWxid, $fileUrl);

                // 缓存文件附件信息，用于避免重复发送到Chatwoot
                Cache::set("chatwoot_outgoing_attachment_{$wechatBot->id}_{$toWxid}_{$fileType}", true, 30);

                Log::info('Chatwoot file sent to WeChat', [
                    'to_wxid' => $toWxid,
                    'file_type' => $fileType,
                    'file_url' => $fileUrl,
                    'attachment_id' => $attachment['id'],
                    'file_size' => $attachment['file_size'] ?? 0
                ]);
            }
        }

        return true;
    }
}
