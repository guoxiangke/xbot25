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
        Schema::create('wechat_clients', function (Blueprint $table) {
            $table->id();
            $table->string('token')->comment('Windows机器标识 $winToken');
            $table->string('endpoint')->comment('Windows机器 xbot api 接口地址:8001');
            $table->string('file_url')->comment('Windows机器暴露的Wechat Files文件夹:8004');
            $table->string('file_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wechat_clients');
    }
};
