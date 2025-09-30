<?php

use App\Models\CheckIn;
use App\Models\WechatBot;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock HTTP请求以避免实际网络调用
    Http::fake();
    
    // 创建测试机器人
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'test_bot_cross_group']);
    
    // 启用全局签到配置 - 使用正确的配置格式
    $this->wechatBot->setMeta('check_in_enabled', true);
    $this->wechatBot->setMeta('room_msg_enabled', true);
    
    // 测试群组
    $this->roomA = 'test_group_a@chatroom';
    $this->roomB = 'test_group_b@chatroom';
    
    // 测试用户
    $this->userWxid = 'test_user_001';
    
    // 创建签到处理器
    $this->handler = new CheckInMessageHandler();
    
    // 设置联系人数据
    $contacts = [
        $this->userWxid => [
            'wxid' => $this->userWxid,
            'nickname' => '测试用户',
            'remark' => '测试用户备注'
        ]
    ];
    $this->wechatBot->setMeta('contacts', $contacts);
});

// 辅助方法：创建签到消息上下文
function createCheckInContext($roomWxid, $keyword, $userWxid = null): XbotMessageContext
{
    $userWxid = $userWxid ?? test()->userWxid;
    
    $requestData = [
        'msg_type' => 'MT_RECV_TEXT_MSG',
        'room_wxid' => $roomWxid,
        'from_wxid' => $userWxid,
        'from_remark' => '测试用户',
        'to_wxid' => test()->wechatBot->wxid,
        'msg' => $keyword,
        'msgid' => 'test_msg_' . uniqid(),
    ];
    
    return new XbotMessageContext(
        wechatBot: test()->wechatBot,
        requestRawData: $requestData,
        msgType: 'MT_RECV_TEXT_MSG',
        clientId: 12345
    );
}

describe('跨群签到状态独立性测试', function () {
    it('用户在群A签到后，在群B签到应该是新的签到记录', function () {
        // 构造群A的签到消息
        $contextA = createCheckInContext($this->roomA, '打卡');
        
        // 在群A签到
        $response = $this->handler->handle($contextA, function ($context) {
            return $context;
        });
        
        // 验证群A签到成功
        expect(CheckIn::where('chatroom', $this->roomA)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->exists())->toBeTrue();
        
        // 构造群B的签到消息
        $contextB = createCheckInContext($this->roomB, '打卡');
        
        // 在群B签到
        $response = $this->handler->handle($contextB, function ($context) {
            return $context;
        });
        
        // 验证群B也创建了新的签到记录
        expect(CheckIn::where('chatroom', $this->roomB)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->exists())->toBeTrue();
        
        // 验证两个群的签到记录是独立的
        $checkInA = CheckIn::where('chatroom', $this->roomA)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->first();
            
        $checkInB = CheckIn::where('chatroom', $this->roomB)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->first();
        
        expect($checkInA->id)->not->toBe($checkInB->id);
        expect($checkInA->chatroom)->toBe($this->roomA);
        expect($checkInB->chatroom)->toBe($this->roomB);
    });
    
    it('用户在群A重复签到，在群B首次签到应该区别对待', function () {
        // 在群A先签到一次
        $contextA1 = createCheckInContext($this->roomA, '打卡');
        $this->handler->handle($contextA1, function ($context) { return $context; });
        
        // 在群A再次签到（重复签到）
        $contextA2 = createCheckInContext($this->roomA, '打卡');
        $this->handler->handle($contextA2, function ($context) { return $context; });
        
        // 验证群A只有一条签到记录
        $countA = CheckIn::where('chatroom', $this->roomA)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->count();
        expect($countA)->toBe(1);
        
        // 在群B首次签到
        $contextB = createCheckInContext($this->roomB, '打卡');
        $this->handler->handle($contextB, function ($context) { return $context; });
        
        // 验证群B创建了新的签到记录
        $countB = CheckIn::where('chatroom', $this->roomB)
            ->where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->count();
        expect($countB)->toBe(1);
        
        // 验证总共有两条独立的签到记录
        $totalCount = CheckIn::where('wxid', $this->userWxid)
            ->whereDate('created_at', Carbon::today())
            ->count();
        expect($totalCount)->toBe(2);
    });
    
    it('多个用户在不同群签到不会互相影响', function () {
        $user2Wxid = 'test_user_002';
        
        // 用户1在群A签到
        $context1A = createCheckInContext($this->roomA, '打卡', $this->userWxid);
        $this->handler->handle($context1A, function ($context) { return $context; });
        
        // 用户2在群A签到
        $context2A = createCheckInContext($this->roomA, '打卡', $user2Wxid);
        $this->handler->handle($context2A, function ($context) { return $context; });
        
        // 用户1在群B签到
        $context1B = createCheckInContext($this->roomB, '打卡', $this->userWxid);
        $this->handler->handle($context1B, function ($context) { return $context; });
        
        // 验证每个用户在每个群都有独立的签到记录
        expect(CheckIn::where('chatroom', $this->roomA)->where('wxid', $this->userWxid)->count())->toBe(1);
        expect(CheckIn::where('chatroom', $this->roomA)->where('wxid', $user2Wxid)->count())->toBe(1);
        expect(CheckIn::where('chatroom', $this->roomB)->where('wxid', $this->userWxid)->count())->toBe(1);
        expect(CheckIn::where('chatroom', $this->roomB)->where('wxid', $user2Wxid)->count())->toBe(0); // 用户2没在群B签到
        
        // 验证总签到记录数
        expect(CheckIn::whereDate('created_at', Carbon::today())->count())->toBe(3);
    });
});