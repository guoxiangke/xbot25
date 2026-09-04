<?php

use App\Jobs\SendWelcomeMessageJob;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * 好友欢迎消息发送任务测试
 *
 * 这是此前完全缺失的一环：原有测试只覆盖 /set welcome_msg 的配置读写，
 * 从不验证消息是否真的发得出去，导致 $xbot->sendText()（XbotClient 上并不存在
 * 该方法，只有 sendTextMessage）这个笔误长期未被发现。
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $client = WechatClient::factory()->create([
        'endpoint' => 'http://localhost:8001',
    ]);

    $this->wechatBot = WechatBot::factory()->create([
        'wechat_client_id' => $client->id,
        'wxid' => 'wxid_bot',
        'client_id' => 1,
    ]);

    Http::fake([
        'http://localhost:8001/*' => Http::response(['success' => true], 200),
    ]);
});

test('欢迎消息真的通过 xbot 客户端发出', function () {
    $this->wechatBot->setMeta('welcome_msg', '@nickname 欢迎你成为良友的知音');
    $this->wechatBot->setMeta('contacts', [
        'wxid_new_friend' => ['nickname' => '上帝的女儿', 'remark' => ''],
    ]);

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertSent(function ($request) {
        return $request['type'] === 'MT_SEND_TEXTMSG'
            && $request['data']['to_wxid'] === 'wxid_new_friend'
            && $request['data']['content'] === '上帝的女儿 欢迎你成为良友的知音';
    });
});

test('昵称优先使用备注', function () {
    $this->wechatBot->setMeta('welcome_msg', '@nickname 你好');
    $this->wechatBot->setMeta('contacts', [
        'wxid_new_friend' => ['nickname' => '王某某', 'remark' => '老王'],
    ]);

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertSent(fn ($request) => $request['data']['content'] === '老王 你好');
});

test('联系人未同步时退化为 wxid', function () {
    $this->wechatBot->setMeta('welcome_msg', '@nickname 你好');

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_unknown'))->handle();

    Http::assertSent(fn ($request) => $request['data']['content'] === 'wxid_unknown 你好');
});

test('模板不含 @nickname 时原样发送', function () {
    $this->wechatBot->setMeta('welcome_msg', '欢迎你，回复【600】获取节目单！');

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertSent(
        fn ($request) => $request['data']['content'] === '欢迎你，回复【600】获取节目单！'
    );
});

test('未设置模板时发送内置默认模板', function () {
    $this->wechatBot->setMeta('contacts', [
        'wxid_new_friend' => ['nickname' => '上帝的女儿', 'remark' => ''],
    ]);

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertSent(
        fn ($request) => $request['data']['content'] === '上帝的女儿 你好，欢迎你！'
    );
});

test('模板被显式写成空白时不发送', function () {
    // /set welcome_msg 会拒绝空值，正常路径不会出现；这里是数据异常时的保护
    $this->wechatBot->setMeta('welcome_msg', '   ');

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertNothingSent();
});

test('显式关闭 friend_welcome 后不发送', function () {
    $this->wechatBot->setMeta('welcome_msg', '@nickname 你好');
    $this->wechatBot->setMeta('friend_welcome_enabled', false);

    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertNothingSent();
});

test('friend_welcome 默认开启，无需任何配置即可发送', function () {
    // 既不设 friend_welcome_enabled 也不设 welcome_msg，走完全默认的路径
    (new SendWelcomeMessageJob($this->wechatBot->id, 'wxid_new_friend'))->handle();

    Http::assertSent(
        fn ($request) => $request['data']['content'] === 'wxid_new_friend 你好，欢迎你！'
    );
});

test('机器人不存在时安全退出', function () {
    (new SendWelcomeMessageJob(999999, 'wxid_new_friend'))->handle();

    Http::assertNothingSent();
});

test('任务派发到 default 队列', function () {
    Queue::fake();

    SendWelcomeMessageJob::dispatch($this->wechatBot->id, 'wxid_new_friend');

    // 线上 worker 只消费 default 和 contacts；
    // 此前用了自定义的 welcome_messages 队列，任务永远无人执行。
    // queue 为 null 表示走连接的默认队列（default）
    Queue::assertPushed(
        SendWelcomeMessageJob::class,
        fn ($job) => $job->queue === null || $job->queue === 'default'
    );
});
