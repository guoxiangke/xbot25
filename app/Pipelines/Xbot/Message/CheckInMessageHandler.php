<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\CheckIn;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\CheckInStatsService;
use App\Services\CheckInPermissionService;
use Carbon\Carbon;
use Closure;

/**
 * 签到消息处理器
 * 处理群聊中的签到相关消息
 */
class CheckInMessageHandler extends BaseXbotHandler
{
    protected $checkInKeywords = [
        'qd', 'Qd', 'qiandao', 'Qiandao', '签到', '簽到',
        'dk', 'Dk', 'Daka', 'daka', '打卡',
        '已读', '已看', '已讀', '已听', '已聽', '已完成',
        '报名', '報名', 'bm', 'Bm', 'baoming', 'Baoming'
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context)) {
            return $next($context);
        }

        // 支持文本消息和语音转文字消息
        if (!$this->isMessageType($context, 'MT_RECV_TEXT_MSG') &&
            !$this->isMessageType($context, 'MT_TRANS_VOICE_MSG')) {
            return $next($context);
        }

        // 获取消息内容
        $message = $this->extractMessageContent($context);
        if (!$message) {
            return $next($context);
        }

        $roomWxid = $context->requestRawData['room_wxid'] ?? null;
        $fromWxid = $context->requestRawData['from_wxid'] ?? '';
        $fromRemark = $context->requestRawData['from_remark'] ?? '';

        // 只处理群消息
        if (!$roomWxid) {
            return $next($context);
        }

        // 检查签到权限
        $permissionService = new CheckInPermissionService($context->wechatBot);
        if (!$permissionService->canCheckIn($roomWxid)) {
            // 权限不足时直接跳过，不给出任何回应
            return $next($context);
        }

        // 处理签到
        if (in_array($message, $this->checkInKeywords)) {
            $this->processCheckIn($context, $roomWxid, $fromWxid, $fromRemark, $message);
            // 保留原始消息类型以便后续扩展
            $context->requestRawData['origin_msg_type'] = $context->msgType;
            // 继续传递到下游处理器（如ChatwootHandler）
            return $next($context);
        }

        // 处理签到排行
        if ($message === '打卡排行') {
            $this->processCheckInRanking($context, $roomWxid);
            // 保留原始消息类型以便后续扩展
            $context->requestRawData['origin_msg_type'] = $context->msgType;
            // 继续传递到下游处理器（如ChatwootHandler）
            return $next($context);
        }

        // 处理个人打卡查询
        if ($message === '我的打卡') {
            $this->processPersonalStats($context, $roomWxid, $fromWxid, $fromRemark);
            // 保留原始消息类型以便后续扩展
            $context->requestRawData['origin_msg_type'] = $context->msgType;
            // 继续传递到下游处理器（如ChatwootHandler）
            return $next($context);
        }

        return $next($context);
    }

    protected function extractMessageContent(XbotMessageContext $context): ?string
    {
        if ($this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return trim($context->requestRawData['msg'] ?? '');
        }

        if ($this->isMessageType($context, 'MT_TRANS_VOICE_MSG')) {
            // 语音转文字消息，文本内容在 text 字段或 data.text 字段
            return trim($context->requestRawData['text'] ?? $context->requestRawData['data']['text'] ?? '');
        }

        return null;
    }

    protected function processCheckIn(XbotMessageContext $context, string $roomWxid, string $fromWxid, string $fromRemark, string $keyword)
    {
        $today = now()->startOfDay();

        // 先检查今天是否已经签到
        $checkIn = CheckIn::where('content', $roomWxid)
            ->where('wxid', $fromWxid)
            ->whereDate('check_in_at', $today)
            ->first();

        if ($checkIn) {
            // 已存在签到记录
            $wasRecentlyCreated = false;
        } else {
            // 创建新的签到记录
            $checkIn = CheckIn::create([
                'content' => $roomWxid,
                'wxid' => $fromWxid,
                'check_in_at' => $today
            ]);
            $wasRecentlyCreated = true;
        }

        $service = new CheckInStatsService($fromWxid, $roomWxid, $context->getAllContacts());
        $stats = $service->getPersonalStats();

        $encourages = [
            "太棒了🌟",
            "做的好👏👏",
            "耶✌️✌️✌️",
            "给身边的人击掌一下吧🙌",
            "给自己一个微笑吧😊",
            "得意的笑一个吧✌️",
            "给自己一个赞吧👍",
            "庆祝🪅一下吧🤩",
            "大声对自己说：我赢了🥇",
            "给自己说一句鼓励的话吧🥳"
        ];
        $randomEncourage = $encourages[array_rand($encourages)];

        // 根据关键词确定回复类型
        $first = match(true) {
            in_array($keyword, ['签到', 'qd', 'Qd', 'Qiandao', 'qiandao', '簽到']) => "✅签到成功",
            in_array($keyword, ['打卡', 'daka', 'Daka', 'dk', 'Dk']) => "✅打卡成功",
            in_array($keyword, ['报名', 'bm', 'Bm', 'baoming', 'Baoming', '報名']) => "✅报名成功",
            default => "✅挑战成功"
        };

        if ($wasRecentlyCreated) {
            // 首次签到 - 先发群消息
            $groupContent = "{$first}\n🥇今天您是第 {$stats['rank']} 位挑战者";
            $this->sendMessage($context, $roomWxid, $groupContent);

            // 再发个人消息
            $personalContent = "{$first}\n✊您已连续坚持了 {$stats['current_streak']} 天\n🏅您总共攒了 {$stats['total_days']} 枚🌟\n您是今天第 {$stats['rank']} 个签到的🥇\n给你一个大大的赞👍\n{$randomEncourage}";
            $this->sendMessage($context, $fromWxid, $personalContent);
        } else {
            // 重复签到
            $content = "✅再次祝贺你！今日您已经挑战过了！";
            $this->sendMessage($context, $roomWxid, $content);
        }

        $this->log('CheckIn processed', [
            'wxid' => $fromWxid,
            'room' => $roomWxid,
            'keyword' => $keyword,
            'was_created' => $wasRecentlyCreated
        ]);
    }

    protected function processCheckInRanking(XbotMessageContext $context, string $roomWxid)
    {
        $service = new CheckInStatsService('', $roomWxid, $context->getAllContacts());

        $totalRanking = $service->getTotalDaysRanking(10);
        $streakRanking = $service->getCurrentStreakRanking(10);

        // 构建总打卡天数排行榜文本
        $textTotalRanking = "📊 总打卡天数排行榜 TOP10\n";
        $textTotalRanking .= "━━━━━━━━━━━━━━━━━━━━━━\n";

        if (empty($totalRanking)) {
            $textTotalRanking .= "暂无打卡记录\n";
        } else {
            foreach ($totalRanking as $user) {
                $rankIcon = $service->getRankIcon($user['rank']);
                $textTotalRanking .= sprintf(
                    "%s %s %s (%d天)\n",
                    $rankIcon,
                    $user['rank'],
                    $user['nickname'],
                    $user['total_days']
                );
            }
        }

        // 构建连续打卡天数排行榜文本
        $textStreakRanking = "\n🔥 连续打卡天数排行榜 TOP10\n";
        $textStreakRanking .= "━━━━━━━━━━━━━━━━━━━━━━\n";

        if (empty($streakRanking)) {
            $textStreakRanking .= "暂无连续打卡记录\n";
        } else {
            foreach ($streakRanking as $user) {
                $rankIcon = $service->getRankIcon($user['rank']);
                $streakText = $user['current_streak'] == 1 ? "1天" : "{$user['current_streak']}天连击";
                $textStreakRanking .= sprintf(
                    "%s %s %s (%s)\n",
                    $rankIcon,
                    $user['rank'],
                    $user['nickname'],
                    $streakText
                );
            }
        }

        $finalText = $textTotalRanking . $textStreakRanking;
        $finalText .= "\n💡 发送「我的打卡」查看个人统计";

        $this->sendMessage($context, $roomWxid, $finalText);
    }

    protected function processPersonalStats(XbotMessageContext $context, string $roomWxid, string $fromWxid, string $fromRemark)
    {
        $service = new CheckInStatsService($fromWxid, $roomWxid, $context->getAllContacts());
        $stats = $service->getPersonalStats();

        if ($stats['total_days'] == 0) {
            $text = "📝 您的打卡统计\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "还没有打卡记录哦～\n";
            $text .= "发送「打卡」开始您的第一次打卡吧！";
        } else {
            $text = "📝 您的打卡统计\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━━\n";

            $text .= sprintf("📅 总打卡天数：%d天\n", $stats['total_days']);
            $text .= sprintf("🔥 当前连续：%d天\n", $stats['current_streak']);
            $text .= sprintf("🏆 最高连击：%d天\n", $stats['max_streak']);

            if ($stats['rank'] > 0) {
                $text .= sprintf("⏰ 今日第%d个打卡\n", $stats['rank']);
            }

            if ($stats['missed_days'] > 0) {
                $text .= sprintf("😴 缺勤天数：%d天 (%.1f%%)\n",
                    $stats['missed_days'],
                    floatval($stats['missed_percentage'])
                );
            } else {
                $text .= "😴 缺勤天数：0天 (全勤！)\n";
            }

            $text .= "\n" . $service->getStatusComment($stats) . "\n";

            // 显示最近缺勤日期
            if (!empty($stats['missed_dates']) && count($stats['missed_dates']) <= 5) {
                $text .= "\n📋 缺勤日期：\n";
                foreach ($stats['missed_dates'] as $missedDate) {
                    $text .= "• " . Carbon::parse($missedDate)->format('m月d日') . "\n";
                }
            } elseif (count($stats['missed_dates']) > 5) {
                $text .= sprintf("\n📋 共缺勤%d天（最近5次）：\n", count($stats['missed_dates']));
                $recentMissed = array_slice($stats['missed_dates'], -5);
                foreach ($recentMissed as $missedDate) {
                    $text .= "• " . Carbon::parse($missedDate)->format('m月d日') . "\n";
                }
            }
        }

        // 群里回复已发送
        $this->sendMessage($context, $roomWxid, '📅 统计已单独发您微信。');

        // 私发详细统计
        $this->sendMessage($context, $fromWxid, $text);
    }

    protected function sendMessage(XbotMessageContext $context, string $toWxid, string $content)
    {
        $this->sendTextMessage($context, $content, $toWxid);
    }
}
