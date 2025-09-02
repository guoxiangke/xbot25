<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Models\XbotSubscription;
use Illuminate\Support\Str;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('xbot:islive')->hourly();

        $xbotSubscriptions = XbotSubscription::with(['wechatBot'])->get();
        foreach ($xbotSubscriptions as $xbotSubscription) {
            if (is_null($xbotSubscription->wechatBot)) {
                //$xbotSubscription->delete();
                continue;
            }

            // FEBC-US(id=13) 和 友4(id=1) 不支持个人订阅
            $to = $xbotSubscription->wxid;
            if ($xbotSubscription->wechat_bot_id == 13 && !Str::endsWith($to, '@chatroom')) {
                continue;
            }
            if ($xbotSubscription->wechat_bot_id == 1 && !Str::endsWith($to, '@chatroom')) {
                continue;
            }

            $schedule->command("subscription:trigger {$xbotSubscription->id}")
                ->cron($xbotSubscription->cron);
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
