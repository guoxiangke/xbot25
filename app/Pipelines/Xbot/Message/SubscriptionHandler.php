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
        if (!$isRoom && $this->isPersonalSubscriptionDisabled($context->wechatBot)) {
            $this->sendTextMessage($context, '暂不支持个人订阅，请入群获取或回复编号！');
            return;
        }

        // 设置发送时间
        $cron = $this->getCronTime($context->wechatBot, $isRoom);

        // 创建或恢复订阅
        $subscription = XbotSubscription::createOrRestore(
            $context->wechatBot->id,
            $context->wxid,
            $keyword,
            $cron
        );

        if ($subscription->wasRecentlyCreated) {
            $chinaHour = ($context->wechatBot->id == 13) ? 5 : 7;
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


    /**
     * 检查是否禁用个人订阅
     * 根据旧代码逻辑，某些特定bot（如id=13的FEBC-US）不支持个人订阅
     */
    private function isPersonalSubscriptionDisabled($wechatBot): bool
    {
        // 这里可以根据具体业务需求配置哪些bot不支持个人订阅
        // 目前参考旧代码的逻辑，id=13的bot不支持个人订阅
        return $wechatBot->id == 13;
    }

    /**
     * 获取cron时间配置
     * 根据旧代码逻辑：FEBC-US(id=13)群5点发送，其他7点发送
     * 注意：由于调度器运行在UTC时区，需要将东八区时间转换为UTC时间
     * 东八区早上7点 = UTC晚上23点（前一天）
     * 东八区早上5点 = UTC晚上21点（前一天）
     */
    private function getCronTime($wechatBot, $isRoom): string
    {
        // 东八区时间
        $chinaHour = ($wechatBot->id == 13) ? 5 : 7;
        // 转换为UTC时间（东八区 -8小时 = UTC）
        $utcHour = ($chinaHour - 8 + 24) % 24;
        return "0 {$utcHour} * * *";
    }

    /**
     * 从cron表达式中提取小时
     */
    private function getHourFromCron(string $cron): int
    {
        $parts = explode(' ', $cron);
        return isset($parts[1]) ? intval($parts[1]) : 7;
    }
}
