<?php

namespace App\Console\Commands;

use App\Models\WechatBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
                    Log::error('XbotIsNotLive', [
                        'name' => $wechatBot->name, 
                        'wxid' => $wechatBot->wxid, 
                        'isLive' => $isLive, 
                        'class' => __CLASS__
                    ]);
                    
                    $monitorBot = WechatBot::find(7);
                    if ($monitorBot) {
                        $content = "掉线了:" . $wechatBot->name;
                        $monitorBot->xbot()->sendTextMessage("17916158456@chatroom", "whoami");
                        $monitorBot->xbot()->sendTextMessage("5829025039@chatroom", "whoami");
                        $monitorBot->xbot()->sendTextMessage("5829025039@chatroom", $content);
                        
                        $url = "https://api.day.app/hzJ44um4NTx9JWoNJ5TFia/" . urlencode($content);
                        file_get_contents($url);
                    }
                }
            });
    }
}