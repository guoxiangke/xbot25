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
        // 设置队列名称
        $this->onQueue('welcome_messages');
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
        if (!$configManager->hasWelcomeMessage()) {
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
        try {
            // 获取联系人信息用于nickname替换
            $nickname = $this->getNickname($wechatBot);
            
            // 获取欢迎消息模板
            $messageTemplate = $configManager->getStringConfig('welcome_msg', '@nickname 你好，欢迎你！');
            
            // 替换@nickname变量
            $welcomeMessage = $this->replaceNickname($messageTemplate, $nickname);
            
            // 发送消息
            $xbot = $wechatBot->xbot();
            $result = $xbot->sendText($this->targetWxid, $welcomeMessage);
            
            Log::info(__FUNCTION__, [
                'wechat_bot_id' => $this->wechatBotId,
                'wxid' => $wechatBot->wxid,
                'target_wxid' => $this->targetWxid,
                'nickname' => $nickname,
                'welcome_message' => $welcomeMessage,
                'result' => $result,
                'message' => 'SendWelcomeMessageJob: Welcome message sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('SendWelcomeMessageJob: Failed to send welcome message', [
                'wechat_bot_id' => $this->wechatBotId,
                'target_wxid' => $this->targetWxid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // 重新抛出异常以触发重试机制
            throw $e;
        }
    }

    /**
     * 获取联系人昵称
     */
    private function getNickname(WechatBot $wechatBot): string
    {
        $contacts = $wechatBot->getMeta('contacts', []);
        
        if (isset($contacts[$this->targetWxid])) {
            $contact = $contacts[$this->targetWxid];
            // 优先使用备注，然后是昵称，最后是wxid
            return $contact['remark'] ?? $contact['nickname'] ?? $this->targetWxid;
        }
        
        // 如果联系人信息不存在，返回wxid
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