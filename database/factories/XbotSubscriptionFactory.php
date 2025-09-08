<?php

namespace Database\Factories;

use App\Models\XbotSubscription;
use App\Models\WechatBot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\XbotSubscription>
 */
class XbotSubscriptionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = XbotSubscription::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wechat_bot_id' => function () {
                // 创建一个完整的 WechatBot（包括 WechatClient）
                $wechatClient = \App\Models\WechatClient::factory()->create();
                return \App\Models\WechatBot::factory()->create([
                    'wechat_client_id' => $wechatClient->id
                ])->id;
            },
            'wxid' => $this->faker->regexify('[a-zA-Z0-9_]+@chatroom'),
            'keyword' => $this->faker->randomElement(['621', '新闻', '音乐', '视频', '学习']),
            'cron' => $this->faker->randomElement(['0 7 * * *', '0 8 * * *', '0 19 * * *', '0 20 * * *']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the subscription is for a group chat.
     */
    public function forGroup(): static
    {
        return $this->state(fn (array $attributes) => [
            'wxid' => $this->faker->regexify('[a-zA-Z0-9_]+@chatroom'),
        ]);
    }

    /**
     * Indicate that the subscription is for a personal contact.
     */
    public function forContact(): static
    {
        return $this->state(fn (array $attributes) => [
            'wxid' => $this->faker->regexify('wxid_[a-zA-Z0-9]+'),
        ]);
    }

    /**
     * Indicate that the subscription is for daily morning delivery.
     */
    public function dailyMorning(): static
    {
        return $this->state(fn (array $attributes) => [
            'cron' => '0 7 * * *',
        ]);
    }

    /**
     * Indicate that the subscription is for evening delivery.
     */
    public function dailyEvening(): static
    {
        return $this->state(fn (array $attributes) => [
            'cron' => '0 19 * * *',
        ]);
    }

    /**
     * Indicate that the subscription has a specific keyword.
     */
    public function withKeyword(string $keyword): static
    {
        return $this->state(fn (array $attributes) => [
            'keyword' => $keyword,
        ]);
    }
}