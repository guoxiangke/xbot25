<?php

namespace App\Console\Commands;

use App\Models\WechatBot;
use Illuminate\Console\Command;

class XbotIsLive extends Command
{
    protected $signature = 'xbot:islive';
    protected $description = 'Check xbot is live or not';

    public function handle()
    {
        WechatBot::query()
            ->whereNotNull('client_id')
            ->whereNotNull('is_live_at')
            ->each(function(WechatBot $wechatBot) {
                $isLive = $wechatBot->isLive();
                if (!$isLive) {
                    $content = "掉线了:" . $wechatBot->name;
                    $url = config('services.bark.url') . urlencode($content);
                    file_get_contents($url);
                }
            });
    }
}
