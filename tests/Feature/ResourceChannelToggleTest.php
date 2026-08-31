<?php

use App\Pipelines\Xbot\Message\KeywordResponseHandler;
use App\Pipelines\Xbot\Message\SelfMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->wechatBot = XbotTestHelpers::createWechatBot([
        'wxid' => 'test_bot',
        'client_id' => 99,
    ]);
    // 全局关键词资源开关开启
    $this->wechatBot->setMeta('keyword_resources_enabled', true);

    $this->handler = new KeywordResponseHandler;
    $this->selfHandler = new SelfMessageHandler;
    $this->next = XbotTestHelpers::createPipelineNext();

    $this->resourceUrl = config('services.xbot.resource_endpoint').'700';

    // getResouce 请求 xlaravel 端点，返回带 Febc metric 的 700 目录菜单
    XbotTestHelpers::mockXbotService([
        $this->resourceUrl => Http::response([
            'type' => 'text',
            'data' => ['content' => "【701】灵程真言\n【702】喜乐灵程"],
            'statistics' => ['metric' => 'Febc', 'keyword' => '700', 'type' => 'text'],
        ], 200),
    ]);
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

function keyword700Context(): \App\Pipelines\Xbot\XbotMessageContext
{
    return XbotTestHelpers::createMessageContext(test()->wechatBot, [
        'data' => [
            'msg' => '700',
            'to_wxid' => test()->wechatBot->wxid,
            'from_wxid' => 'wxid_user123',
        ],
    ]);
}

it('sends the Febc 700 directory when the channel is enabled by default', function () {
    $this->handler->handle(keyword700Context(), $this->next);

    XbotTestHelpers::assertMessageSent('灵程真言');
});

it('suppresses the whole Febc channel BEFORE requesting the resource', function () {
    $this->wechatBot->setMeta('keyword_resources_Febc_enabled', false);

    $this->handler->handle(keyword700Context(), $this->next);

    // 请求前即拦截：既不请求 xlaravel 资源端点，也不下发任何消息
    Http::assertNothingSent();
});

it('registers a resource channel toggle via /set keyword_resources_Febc 0', function () {
    $context = XbotTestHelpers::createBotMessageContext($this->wechatBot, '/set keyword_resources_Febc 0');

    $this->selfHandler->handle($context, $this->next);

    XbotTestHelpers::assertMessageSent('资源频道 Febc 已禁用');
    expect($this->wechatBot->getMeta('keyword_resources_Febc_enabled'))->toBeFalse();
});

it('keeps the global keyword_resources switch independent from channel toggles', function () {
    $context = XbotTestHelpers::createBotMessageContext($this->wechatBot, '/set keyword_resources 0');

    $this->selfHandler->handle($context, $this->next);

    XbotTestHelpers::assertMessageSent('keyword_resources 已禁用');
    expect($this->wechatBot->getMeta('keyword_resources_enabled'))->toBeFalse();
});
