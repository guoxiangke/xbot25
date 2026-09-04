<?php

use App\Jobs\SendWelcomeMessageJob;
use App\Pipelines\Xbot\Contact\NotificationHandler;
use Illuminate\Support\Facades\Queue;
use Tests\Support\XbotTestHelpers;

/**
 * 好友添加/删除通知的处理测试
 *
 * 背景：xbot 下发的 MT_CONTACT_ADD_NOITFY_MSG / MT_CONTACT_DEL_NOTIFY_MSG
 * payload 是一个联系人对象，wxid 放在 `wxid` 字段。此前代码读的是 `from_wxid`，
 * 永远取到 null 后直接 return，欢迎消息任务从未被派发。
 *
 * 线上真实 payload（2026-09-03）：
 * {"account":"","avatar":"","city":"","country":"","nickname":"上帝的女儿",
 *  "remark":"","sex":0,"wxid":"wxid_qptwbcnhbt5g12"}
 */
describe('好友添加通知', function () {

    beforeEach(function () {
        $this->wechatBot = XbotTestHelpers::createWechatBot();
        $this->handler = new NotificationHandler();
        $this->next = XbotTestHelpers::createPipelineNext();

        XbotTestHelpers::mockXbotService();
        Queue::fake();
    });

    test('payload 使用 wxid 字段时派发欢迎消息任务', function () {
        $this->wechatBot->setMeta('welcome_msg', '@nickname 欢迎你成为良友的知音');

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            [
                'account' => '',
                'avatar' => '',
                'nickname' => '上帝的女儿',
                'remark' => '',
                'sex' => 0,
                'wxid' => 'wxid_qptwbcnhbt5g12',
            ],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        Queue::assertPushed(
            SendWelcomeMessageJob::class,
            fn ($job) => $job->targetWxid === 'wxid_qptwbcnhbt5g12'
        );
    });

    test('兼容历史的 from_wxid 字段', function () {
        $this->wechatBot->setMeta('welcome_msg', '欢迎你');

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['from_wxid' => 'wxid_legacy_field'],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        Queue::assertPushed(
            SendWelcomeMessageJob::class,
            fn ($job) => $job->targetWxid === 'wxid_legacy_field'
        );
    });

    test('payload 中没有任何 wxid 时不派发任务', function () {
        $this->wechatBot->setMeta('welcome_msg', '欢迎你');

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['nickname' => '没有wxid'],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        Queue::assertNotPushed(SendWelcomeMessageJob::class);
    });

    test('未设置欢迎消息模板时不派发任务', function () {
        // 不设置 welcome_msg：此前因 STRING_DEFAULT_VALUES 有非空默认值，
        // hasWelcomeMessage() 恒为 true，会给从没配过模板的 bot 发默认欢迎语
        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['wxid' => 'wxid_new_friend'],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        Queue::assertNotPushed(SendWelcomeMessageJob::class);
    });

    test('模板为空白字符时不派发任务', function () {
        $this->wechatBot->setMeta('welcome_msg', '   ');

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['wxid' => 'wxid_new_friend'],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        Queue::assertNotPushed(SendWelcomeMessageJob::class);
    });

    test('通知被转换为文本消息且会话归属正确', function () {
        $this->wechatBot->setMeta('contacts', [
            'wxid_qptwbcnhbt5g12' => ['nickname' => '上帝的女儿', 'remark' => ''],
        ]);

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['wxid' => 'wxid_qptwbcnhbt5g12', 'nickname' => '上帝的女儿'],
            'MT_CONTACT_ADD_NOITFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        expect($context->msgType)->toBe('MT_RECV_TEXT_MSG')
            ->and($context->requestRawData['msg'])->toContain('新好友添加成功')
            ->and($context->requestRawData['msg'])->toContain('上帝的女儿')
            // 通知 payload 没有 to_wxid，若不显式修正，下游会拿到空 wxid 建会话
            ->and($context->wxid)->toBe('wxid_qptwbcnhbt5g12')
            ->and($context->fromWxid)->toBe($this->wechatBot->wxid);
    });
});

describe('好友删除通知', function () {

    beforeEach(function () {
        $this->wechatBot = XbotTestHelpers::createWechatBot();
        $this->handler = new NotificationHandler();
        $this->next = XbotTestHelpers::createPipelineNext();

        XbotTestHelpers::mockXbotService();
        Queue::fake();
    });

    test('payload 使用 wxid 字段时能从联系人列表移除', function () {
        $this->wechatBot->setMeta('contacts', [
            'wxid_deleted' => ['nickname' => '被删好友'],
            'wxid_kept' => ['nickname' => '保留好友'],
        ]);

        $context = XbotTestHelpers::createMessageContext(
            $this->wechatBot,
            ['wxid' => 'wxid_deleted'],
            'MT_CONTACT_DEL_NOTIFY_MSG'
        );

        $this->handler->handle($context, $this->next);

        $contacts = $this->wechatBot->getMeta('contacts', []);

        expect($contacts)->not->toHaveKey('wxid_deleted')
            ->and($contacts)->toHaveKey('wxid_kept')
            ->and($context->requestRawData['msg'])->toContain('好友已被移除');
    });
});
