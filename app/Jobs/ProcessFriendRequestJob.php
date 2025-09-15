<?php

namespace App\Jobs;

use App\Models\WechatBot;
use App\Services\Managers\ConfigManager;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 处理好友请求队列任务
 * 实现防封号的延迟处理机制
 */
class ProcessFriendRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 300;

    public function __construct(
        public int $wechatBotId,
        public array $friendRequestData
    ) {
        // 设置队列名称
        $this->onQueue('friend_requests');
    }

    public function handle(): void
    {
        $wechatBot = WechatBot::find($this->wechatBotId);
        
        if (!$wechatBot) {
            Log::error('ProcessFriendRequestJob: WechatBot not found', [
                'wechat_bot_id' => $this->wechatBotId
            ]);
            return;
        }

        $configManager = new ConfigManager($wechatBot);
        
        // 检查是否仍然启用自动同意
        if (!$configManager->isEnabled('friend_auto_accept')) {
            Log::info(__FUNCTION__, [
                'wechat_bot_id' => $this->wechatBotId,
                'wxid' => $wechatBot->wxid,
                'message' => 'ProcessFriendRequestJob: Auto accept disabled, skipping'
            ]);
            return;
        }

        // 检查每日限制
        if (!$this->checkDailyLimit($configManager)) {
            // 超出限制，延期到明天
            $this->rescheduleToTomorrow();
            return;
        }

        // 处理好友请求
        $this->processFriendRequest($wechatBot, $configManager);
    }

    /**
     * 检查每日处理限制
     */
    private function checkDailyLimit(ConfigManager $configManager): bool
    {
        $dailyLimit = (int) $configManager->getFriendConfig('friend_daily_limit', 50);
        $todayStats = $this->getTodayStats($configManager);
        
        if ($todayStats['count'] >= $dailyLimit) {
            Log::info(__FUNCTION__, [
                'daily_limit' => $dailyLimit,
                'today_count' => $todayStats['count'],
                'wechat_bot_id' => $this->wechatBotId,
                'message' => 'ProcessFriendRequestJob: Daily limit exceeded'
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * 获取今日统计数据
     */
    private function getTodayStats(ConfigManager $configManager): array
    {
        $stats = $configManager->getFriendConfig('daily_stats', []);
        $today = now()->toDateString();
        
        // 如果不是今天的数据，重置统计
        if (empty($stats['date']) || $stats['date'] !== $today) {
            $stats = [
                'date' => $today,
                'count' => 0,
                'last_processed' => null
            ];
        }
        
        return $stats;
    }

    /**
     * 更新今日统计数据
     */
    private function updateTodayStats(ConfigManager $configManager): void
    {
        $stats = $this->getTodayStats($configManager);
        $stats['count'] += 1;
        $stats['last_processed'] = now()->toDateTimeString();
        
        $configManager->setFriendConfig('daily_stats', $stats);
    }

    /**
     * 处理好友请求
     */
    private function processFriendRequest(WechatBot $wechatBot, ConfigManager $configManager): void
    {
        $scene = $this->friendRequestData['scene'] ?? '';
        $encryptusername = $this->friendRequestData['encryptusername'] ?? '';
        $ticket = $this->friendRequestData['ticket'] ?? '';
        $fromnickname = $this->friendRequestData['fromnickname'] ?? '';
        $content = $this->friendRequestData['content'] ?? '';

        if (empty($scene) || empty($encryptusername) || empty($ticket)) {
            Log::error('ProcessFriendRequestJob: Missing required parameters', [
                'scene' => $scene,
                'encryptusername' => $encryptusername,
                'ticket' => $ticket,
                'wechat_bot_id' => $this->wechatBotId
            ]);
            return;
        }

        try {
            // 同意好友请求
            $xbot = $wechatBot->xbot();
            $result = $xbot->acceptFriendRequest((int)$scene, $encryptusername, $ticket);
            
            // 更新统计
            $this->updateTodayStats($configManager);
            
            Log::info(__FUNCTION__, [
                'wechat_bot_id' => $this->wechatBotId,
                'wxid' => $wechatBot->wxid,
                'from_nickname' => $fromnickname,
                'content' => $content,
                'scene' => $scene,
                'result' => $result,
                'message' => 'ProcessFriendRequestJob: Friend request processed successfully'
            ]);

            // 如果启用欢迎消息，等待一段时间后发送欢迎消息
            if ($configManager->isEnabled('friend_welcome_enabled')) {
                $this->scheduleWelcomeMessage($wechatBot, $encryptusername, $configManager);
            }

        } catch (\Exception $e) {
            Log::error('ProcessFriendRequestJob: Failed to process friend request', [
                'wechat_bot_id' => $this->wechatBotId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 重新抛出异常以触发重试机制
            throw $e;
        }
    }

    /**
     * 安排发送欢迎消息
     */
    private function scheduleWelcomeMessage(WechatBot $wechatBot, string $wxid, ConfigManager $configManager): void
    {
        // 延迟5-15分钟发送欢迎消息，模拟人工操作
        $delay = rand(300, 900); // 5-15分钟（秒）
        
        SendWelcomeMessageJob::dispatch($wechatBot->id, $wxid)
            ->delay(now()->addSeconds($delay));
            
        Log::info(__FUNCTION__, [
            'wechat_bot_id' => $wechatBot->id,
            'target_wxid' => $wxid,
            'delay_seconds' => $delay,
            'message' => 'ProcessFriendRequestJob: Welcome message scheduled'
        ]);
    }

    /**
     * 将任务重新安排到明天
     */
    private function rescheduleToTomorrow(): void
    {
        // 明天的随机时间（早上8点到晚上8点之间）
        $tomorrow = now()->addDay()->startOfDay()->addHours(8);
        $randomHours = rand(0, 12); // 0-12小时随机
        $randomMinutes = rand(0, 59); // 0-59分钟随机
        
        $scheduledTime = $tomorrow->addHours($randomHours)->addMinutes($randomMinutes);
        
        static::dispatch($this->wechatBotId, $this->friendRequestData)
            ->delay($scheduledTime);
            
        Log::info(__FUNCTION__, [
            'wechat_bot_id' => $this->wechatBotId,
            'scheduled_time' => $scheduledTime->toDateTimeString(),
            'friend_request' => $this->friendRequestData,
            'message' => 'ProcessFriendRequestJob: Rescheduled to tomorrow'
        ]);
    }

    /**
     * 计算智能延迟时间
     * 根据今日已处理数量动态调整延迟
     */
    public static function calculateSmartDelay(ConfigManager $configManager): int
    {
        $dailyLimit = (int) $configManager->getFriendConfig('friend_daily_limit', 50);
        $todayStats = $configManager->getFriendConfig('daily_stats', []);
        $todayCount = $todayStats['count'] ?? 0;
        
        // 剩余可处理数量
        $remaining = max(1, $dailyLimit - $todayCount);
        
        // 今天剩余小时数
        $currentHour = now()->hour;
        $remainingHours = max(1, 24 - $currentHour);
        
        // 基础间隔：剩余时间平均分配
        $baseIntervalMinutes = ($remainingHours * 60) / $remaining;
        
        // 限制在10分钟到120分钟之间
        $baseIntervalMinutes = max(10, min(120, $baseIntervalMinutes));
        
        // 添加±20%的随机波动
        $randomFactor = rand(80, 120) / 100;
        $finalDelay = round($baseIntervalMinutes * $randomFactor);
        
        return max(10, (int) $finalDelay); // 至少10分钟延迟
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFriendRequestJob: Job failed permanently', [
            'wechat_bot_id' => $this->wechatBotId,
            'friend_request_data' => $this->friendRequestData,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}