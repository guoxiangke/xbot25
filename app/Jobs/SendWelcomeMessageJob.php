<?php

namespace App\Jobs;

use App\Models\WechatBot;
use App\Services\Managers\ConfigManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 发送欢迎消息队列任务
 * 在同意好友请求后延迟发送欢迎消息
 */
class SendWelcomeMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $maxExceptions = 3;
    public int $timeout = 60;

    public function __construct(
        public int $wechatBotId,
        public string $targetWxid
    ) {
        // 走 default 队列：线上 worker 只消费 default 和 contacts
    }

    public function handle(): void
    {
        $wechatBot = WechatBot::find($this->wechatBotId);
        
        if (!$wechatBot) {
            Log::error('SendWelcomeMessageJob: WechatBot not found', [
                'wechat_bot_id' => $this->wechatBotId
            ]);
            return;
        }

        $configManager = new ConfigManager($wechatBot);
        
        // 检查是否设置了好友欢迎消息
        if (!$configManager->shouldSendWelcomeMessage()) {
            Log::info(__FUNCTION__, [
                'wechat_bot_id' => $this->wechatBotId,
                'target_wxid' => $this->targetWxid,
                'message' => 'SendWelcomeMessageJob: Friend welcome message not configured, skipping'
            ]);
            return;
        }

        // 发送欢迎消息
        $this->sendWelcomeMessage($wechatBot, $configManager);
    }

    /**
     * 发送欢迎消息
     */
    private function sendWelcomeMessage(WechatBot $wechatBot, ConfigManager $configManager): void
    {
        $nickname = $this->getNickname($wechatBot);
        $welcomeMessage = $this->replaceNickname(
            $configManager->getStringConfig('welcome_msg'),
            $nickname
        );

        // 异常直接向上冒泡：队列会自动重试，最终失败由 failed() 记录
        $response = $wechatBot->xbot()->sendTextMessage($this->targetWxid, $welcomeMessage);

        Log::info(__FUNCTION__, [
            'wechat_bot_id' => $this->wechatBotId,
            'wxid' => $wechatBot->wxid,
            'target_wxid' => $this->targetWxid,
            'nickname' => $nickname,
            'welcome_message' => $welcomeMessage,
            'status' => $response?->status(),
            'message' => 'SendWelcomeMessageJob: Welcome message sent',
        ]);
    }

    /**
     * 获取联系人昵称
     */
    private function getNickname(WechatBot $wechatBot): string
    {
        $contacts = $wechatBot->getMeta('contacts', []);
        $contact = $contacts[$this->targetWxid] ?? [];

        // 优先使用备注，然后是昵称，最后是 wxid。
        // 注意必须判断「非空字符串」而不是用 ??：新好友的 remark 通常是 ''（空串而非 null），
        // 用 ?? 会让 @nickname 被替换成空。
        foreach (['remark', 'nickname'] as $field) {
            $value = $contact[$field] ?? '';

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->targetWxid;
    }

    /**
     * 替换消息模板中的@nickname变量
     */
    private function replaceNickname(string $template, string $nickname): string
    {
        return str_replace('@nickname', $nickname, $template);
    }

    /**
     * 任务失败时的处理
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendWelcomeMessageJob: Job failed permanently', [
            'wechat_bot_id' => $this->wechatBotId,
            'target_wxid' => $this->targetWxid,
            'exception' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}