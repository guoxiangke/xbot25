<?php

use App\Models\CheckIn;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Analytics\CheckInAnalytics;
use App\Services\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock HTTP请求以避免实际网络调用
    Http::fake();
    
    // 创建测试机器人
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'refactored_test_bot']);
    
    // 启用全局配置
    $this->wechatBot->setMeta('check_in_enabled', true);
    $this->wechatBot->setMeta('room_msg_enabled', true);
    
    // 测试群组和用户
    $this->roomWxid = 'refactored_room@chatroom';
    $this->userWxid = 'refactored_user';
    
    // 设置联系人数据
    $contacts = [
        $this->userWxid => [
            'wxid' => $this->userWxid,
            'nickname' => '重构测试用户',
            'remark' => '重构测试用户'
        ]
    ];
    $this->wechatBot->setMeta('contacts', $contacts);
    
    // 创建Handler
    $this->handler = new CheckInMessageHandler();
});

describe('重构后的打卡功能测试', function () {
    it('验证新表结构：使用chatroom字段和created_at时间', function () {
        // 创建签到上下文
        $context = createRefactoredTestContext($this->roomWxid, $this->userWxid, '打卡', $this->wechatBot);
        
        // 处理签到
        $this->handler->handle($context, function ($ctx) { return $ctx; });
        
        // 验证签到记录
        $checkIn = CheckIn::where('chatroom', $this->roomWxid)
            ->where('wxid', $this->userWxid)
            ->first();
        
        expect($checkIn)->not->toBeNull('应该创建签到记录');
        expect($checkIn->chatroom)->toBe($this->roomWxid, '应该使用chatroom字段存储群ID');
        expect($checkIn->created_at)->not->toBeNull('应该有created_at时间');
        
        // 验证created_at记录的是实际打卡时间，不是00:00:00
        $createdTime = $checkIn->created_at;
        expect($createdTime->format('H:i:s'))->not->toBe('00:00:00', 'created_at应该是实际打卡时间');
    });
    
    it('验证时区逻辑：使用群时区判断但存储UTC时间', function () {
        // 设置群时区为 +8
        $this->wechatBot->setMeta('group_config.refactored_room@chatroom.room_timezone_special', 8);
        
        // 模拟在UTC时间17:00（北京时间01:00，即第二天）
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 17:00:00', 'UTC'));
        
        // 创建签到
        $context = createRefactoredTestContext($this->roomWxid, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($context, function ($ctx) { return $ctx; });
        
        // 验证存储时间是UTC
        $checkIn = CheckIn::where('chatroom', $this->roomWxid)
            ->where('wxid', $this->userWxid)
            ->first();
        
        expect($checkIn->created_at->toDateTimeString())->toBe('2025-09-30 17:00:00', '存储时间应该是UTC时间');
        
        // 验证查询逻辑：应该能在群时区的今日范围内找到这条记录
        [$todayStart, $todayEnd] = TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $this->roomWxid);
        $isInRange = $checkIn->created_at->between($todayStart, $todayEnd);
        expect($isInRange)->toBeTrue('UTC时间17:00应该在群时区今日范围内');
        
        Carbon::setTestNow(); // 重置测试时间
    });
    
    it('验证跨群独立性：不同群的打卡状态独立', function () {
        $roomA = 'room_a@chatroom';
        $roomB = 'room_b@chatroom';
        
        // 在群A打卡
        $contextA = createRefactoredTestContext($roomA, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($contextA, function ($ctx) { return $ctx; });
        
        // 在群B打卡
        $contextB = createRefactoredTestContext($roomB, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($contextB, function ($ctx) { return $ctx; });
        
        // 验证两个群都有独立的打卡记录
        $checkInA = CheckIn::where('chatroom', $roomA)->where('wxid', $this->userWxid)->first();
        $checkInB = CheckIn::where('chatroom', $roomB)->where('wxid', $this->userWxid)->first();
        
        expect($checkInA)->not->toBeNull('群A应该有打卡记录');
        expect($checkInB)->not->toBeNull('群B应该有打卡记录');
        expect($checkInA->id)->not->toBe($checkInB->id, '两个群的打卡记录应该独立');
    });
    
    it('验证重复打卡检测仍然有效', function () {
        // 第一次打卡
        $context1 = createRefactoredTestContext($this->roomWxid, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($context1, function ($ctx) { return $ctx; });
        
        // 第二次打卡（重复）
        $context2 = createRefactoredTestContext($this->roomWxid, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($context2, function ($ctx) { return $ctx; });
        
        // 验证只有一条打卡记录
        $count = CheckIn::where('chatroom', $this->roomWxid)
            ->where('wxid', $this->userWxid)
            ->count();
        expect($count)->toBe(1, '重复打卡应该只有一条记录');
    });
    
    it('验证统计功能使用新字段正确计算', function () {
        // 创建多条打卡记录（模拟多天）
        $dates = ['2025-09-28', '2025-09-29', '2025-09-30'];
        foreach ($dates as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => $this->userWxid,
            ]);
            // 手动设置时间并保存
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 使用统计服务
        $analytics = new CheckInAnalytics($this->userWxid, $this->roomWxid, [$this->userWxid => ['nickname' => '测试用户']], $this->wechatBot);
        $stats = $analytics->getPersonalStats();
        
        // 验证统计结果
        expect($stats['total_days'])->toBe(3, '应该统计到3天的打卡');
        expect($stats['current_streak'])->toBe(3, '应该有3天连续打卡');
    });
});

// 辅助方法：创建重构测试上下文
function createRefactoredTestContext($roomWxid, $userWxid, $keyword, $wechatBot): XbotMessageContext
{
    $requestData = [
        'msg_type' => 'MT_RECV_TEXT_MSG',
        'room_wxid' => $roomWxid,
        'from_wxid' => $userWxid,
        'from_remark' => '重构测试用户',
        'to_wxid' => $wechatBot->wxid,
        'msg' => $keyword,
        'msgid' => 'refactored_test_' . uniqid(),
    ];
    
    return new XbotMessageContext(
        wechatBot: $wechatBot,
        requestRawData: $requestData,
        msgType: 'MT_RECV_TEXT_MSG',
        clientId: 12345
    );
}