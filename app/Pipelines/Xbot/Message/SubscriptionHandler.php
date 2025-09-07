<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;
use Illuminate\Support\Str;

/**
 * 订阅管理处理器
 * 处理"订阅"和"取消订阅"命令
 */
class SubscriptionHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

        // 只处理文本消息
        if (!$this->isMessageType($context, ['MT_RECV_TEXT_MSG'])) {
            return $next($context);
        }

        // 不响应自己的消息，避免死循环
        if ($context->isFromBot) {
            return $next($context);
        }

        $content = $context->requestRawData['msg'] ?? '';
        $content = trim($content);

        // 处理订阅命令
        if (Str::startsWith($content, '订阅')) {
            $this->handleSubscribe($context, $content);
            // 保留原始消息类型以便后续扩展
            $context->requestRawData['origin_msg_type'] = $context->msgType;
            // 继续传递到下游处理器（如ChatwootHandler）
            return $next($context);
        }

        // 处理取消订阅命令
        if (Str::startsWith($content, '取消订阅')) {
            $this->handleUnsubscribe($context, $content);
            // 保留原始消息类型以便后续扩展
            $context->requestRawData['origin_msg_type'] = $context->msgType;
            // 继续传递到下游处理器（如ChatwootHandler）
            return $next($context);
        }

        // 没有匹配的订阅命令，继续到下一个处理器
        return $next($context);
    }

    /**
     * 处理订阅命令
     */
    private function handleSubscribe(XbotMessageContext $context, string $content): void
    {
        $keyword = Str::replace('订阅', '', $content);
        $keyword = trim($keyword);

        if (empty($keyword)) {
            $this->sendTextMessage($context, '请输入要订阅的关键词，例如：订阅 新闻');
            return;
        }

        // 验证关键词是否存在资源
        $resource = $context->wechatBot->getResouce($keyword);
        if (!$resource) {
            // 检查是否存在自动回复
            $autoReply = $context->wechatBot->autoReplies()->where('keyword', $keyword)->first();
            if (!$autoReply) {
                $this->sendTextMessage($context, '关键词不存在任何资源，无法订阅');
                return;
            }
            $resource = $autoReply->content;
        }

        // 检查个人订阅限制
        $isRoom = !empty($context->roomWxid);
        if (!$isRoom) {
            $donateText = config('services.xbot.donate', '');
            $this->sendTextMessage($context, "资源有限\n请入群或回复编号获取！\n{$donateText}");
            return;
        }

        // 设置发送时间
        $chinaHour = 5;
        $cron =  "0 {$chinaHour} * * *";
        //$this->getCronTime($context->wechatBot, $isRoom);

        // 创建或恢复订阅
        $subscription = XbotSubscription::createOrRestore(
            $context->wechatBot->id,
            $context->wxid,
            $keyword,
            $cron
        );

        if ($subscription->wasRecentlyCreated) {
            $this->sendTextMessage($context, "成功订阅，每早{$chinaHour}点，不见不散！");
        } else {
            $this->sendTextMessage($context, '已订阅成功！时间和之前一样');
        }

        $this->log('Subscription created', [
            'keyword' => $keyword,
            'wxid' => $context->wxid,
            'cron' => $cron
        ]);
    }

    /**
     * 处理取消订阅命令
     */
    private function handleUnsubscribe(XbotMessageContext $context, string $content): void
    {
        $keyword = Str::replace('取消订阅', '', $content);
        $keyword = trim($keyword);

        if (empty($keyword)) {
            $this->sendTextMessage($context, '请输入要取消订阅的关键词，例如：取消订阅 新闻');
            return;
        }

        $subscription = XbotSubscription::findByBotAndWxid(
            $context->wechatBot->id,
            $context->wxid,
            $keyword
        );

        if ($subscription) {
            $subscription->delete();
            $this->sendTextMessage($context, '已取消订阅！');
            $this->log('Subscription cancelled', [
                'keyword' => $keyword,
                'wxid' => $context->wxid
            ]);
        } else {
            $this->sendTextMessage($context, '查无此订阅！');
        }
    }
}
