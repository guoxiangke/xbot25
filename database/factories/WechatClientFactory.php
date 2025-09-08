<?php

namespace Database\Factories;

use App\Models\WechatClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class WechatClientFactory extends Factory
{
    protected $model = WechatClient::class;

    public function definition(): array
    {
        return [
            'token' => 'win11',
            'endpoint' => 'http://100.96.141.89:8001',
            'file_url' => 'http://localhost:8004',
            'file_path' => 'C:\\Users\\Public\\Pictures\\WeChat Files',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}