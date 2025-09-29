<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Models\XbotSubscription;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'wechat',
            'wechat/*',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('xbot:islive')->hourly();

        // 在测试环境中跳过动态定时任务设置
        if (app()->environment('testing')) {
            return;
        }

        try {
            // 获取有有效wechatBot关联的订阅
            $xbotSubscriptions = XbotSubscription::with(['wechatBot'])
                ->whereHas('wechatBot')
                ->get();
                
            foreach ($xbotSubscriptions as $xbotSubscription) {
                $schedule->command("subscription:trigger {$xbotSubscription->id}")
                    ->cron($xbotSubscription->cron)
                    ->timezone('Asia/Shanghai');
            }
        } catch (\Exception $e) {
            Log::error('Failed to load subscription schedules: ' . $e->getMessage());
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
