<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('xbot_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wechat_bot_id')->index()->default(0)->comment('谁发的');
            $table->string('wxid')->index()->comment('发给谁');//订阅联系人/群
            $table->string('keyword')->comment('订阅的资源关键字');
            $table->string('cron')->default('0 7 * * *');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xbot_subscriptions');
    }
};
