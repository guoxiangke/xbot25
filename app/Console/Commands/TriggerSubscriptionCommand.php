<?php

namespace App\Console\Commands;

use App\Models\XbotSubscription;
use Illuminate\Console\Command;

class TriggerSubscriptionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:trigger {subscription}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '触发指定订阅的推送';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $subscriptionId = $this->argument('subscription');
        $subscription = XbotSubscription::find($subscriptionId);

        if (!$subscription) {
            $this->error("订阅 ID {$subscriptionId} 不存在");
            return 1;
        }

        $this->info("开始处理订阅: {$subscription->keyword} -> {$subscription->wxid}");

        $to = $subscription->wxid;
        $wechatBot = $subscription->wechatBot;

        // 支持多个关键词，用分号分隔
        $keywords = explode(';', $subscription->keyword);

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (empty($keyword)) {
                continue;
            }

            $this->info("处理关键词: {$keyword}");

            // 获取资源
            $resource = $wechatBot->getResouce($keyword);
            
            if (!$resource) {
                // 如果没有找到资源，发送关键词本身
                $resource = [
                    'type' => 'text',
                    'data' => ['content' => $keyword]
                ];
                $this->warn("关键词 '{$keyword}' 没有找到对应资源，将发送关键词本身");
            }

            // 发送资源
            $wechatBot->send([$to], $resource);
            $this->info("已发送资源到 {$to}");

            // 避免发送过于频繁
            sleep(1);
        }

        $this->info("订阅处理完成");
        return 0;
    }
}
