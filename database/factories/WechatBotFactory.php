<?php

namespace Database\Factories;

use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class WechatBotFactory extends Factory
{
    protected $model = WechatBot::class;

    public function definition(): array
    {
        return [
            'wxid' => 'wxid_' . $this->faker->unique()->word . $this->faker->unique()->randomNumber(4),
            'wechat_client_id' => WechatClient::factory(),
            'client_id' => $this->faker->unique()->numberBetween(1, 999),
            'login_at' => now(),
            'is_live_at' => now(),
            'expires_at' => now()->addMonths(3),
            'chatwoot_account_id' => null,
            'chatwoot_inbox_id' => null,
            'chatwoot_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * 配置有 Chatwoot 设置的机器人
     */
    public function withChatwoot(): static
    {
        return $this->state(fn (array $attributes) => [
            'chatwoot_account_id' => $this->faker->numberBetween(1, 99),
            'chatwoot_inbox_id' => $this->faker->numberBetween(1, 99),
            'chatwoot_token' => $this->faker->sha256(),
        ]);
    }
}