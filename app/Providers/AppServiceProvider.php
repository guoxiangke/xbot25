<?php

namespace App\Providers;

use App\Services\Clients\WebridgeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // iOS 微信（WeBridge 插件）客户端，配置来自 config/services.php
        $this->app->singleton(WebridgeClient::class, fn () => WebridgeClient::make());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
