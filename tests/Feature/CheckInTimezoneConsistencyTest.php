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
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'timezone_test_bot']);
    
    // 启用全局配置
    $this->wechatBot->setMeta('check_in_enabled', true);
    $this->wechatBot->setMeta('room_msg_enabled', true);
    
    // 测试群组和用户
    $this->roomWxid = 'timezone_test_room@chatroom';
    $this->userWxid = 'timezone_test_user';
    
    // 设置联系人数据
    $contacts = [
        $this->userWxid => [
            'wxid' => $this->userWxid,
            'nickname' => '时区测试用户',
            'remark' => '时区测试用户'
        ]
    ];
    $this->wechatBot->setMeta('contacts', $contacts);
    
    // 创建Handler
    $this->handler = new CheckInMessageHandler();
});

describe('打卡功能时区一致性测试（重构版）', function () {
    it('验证存储时间使用实际UTC时间而非00:00:00', function () {
        // 设置群时区为 +8 (Asia/Shanghai)
        $this->wechatBot->setMeta('group_config.timezone_test_room@chatroom.room_timezone_special', 8);
        
        // 模拟在UTC时间17:00签到（对应北京时间01:00，即第二天）
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 17:00:00', 'UTC'));
        
        // 创建签到上下文
        $context = createTimezoneTestContext($this->roomWxid, $this->userWxid, '打卡', $this->wechatBot);
        
        // 处理签到
        $this->handler->handle($context, function ($ctx) { return $ctx; });
        
        // 验证签到记录
        $checkIn = CheckIn::where('chatroom', $this->roomWxid)
            ->where('wxid', $this->userWxid)
            ->first();
        
        expect($checkIn)->not->toBeNull('应该创建签到记录');
        
        // 验证存储时间是实际的UTC时间，不是00:00:00
        expect($checkIn->created_at->toDateTimeString())->toBe('2025-09-30 17:00:00', '存储时间应该是实际UTC时间');
        expect($checkIn->created_at->format('H:i:s'))->not->toBe('00:00:00', 'created_at应该不是00:00:00');
        
        // 验证查询逻辑：应该能在群时区的今日范围内找到这条记录
        [$todayStart, $todayEnd] = TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $this->roomWxid);
        $isInRange = $checkIn->created_at->between($todayStart, $todayEnd);
        expect($isInRange)->toBeTrue('UTC时间17:00应该在群时区今日范围内');
        
        Carbon::setTestNow(); // 重置测试时间
    });
    
    it('验证不同时区群组的独立性和正确查询', function () {
        // 设置两个不同时区的群
        $roomA = 'room_utc8@chatroom';  // UTC+8
        $roomB = 'room_utc0@chatroom';  // UTC+0 
        
        $this->wechatBot->setMeta('group_config.room_utc8@chatroom.room_timezone_special', 8);
        $this->wechatBot->setMeta('group_config.room_utc0@chatroom.room_timezone_special', 0);
        
        // 模拟在UTC时间的17:00签到
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 17:00:00', 'UTC'));
        
        // 在UTC+8群签到（北京时间01:00，即10月1日）
        $contextA = createTimezoneTestContext($roomA, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($contextA, function ($ctx) { return $ctx; });
        
        // 在UTC+0群签到（伦敦时间17:00，即9月30日）
        $contextB = createTimezoneTestContext($roomB, $this->userWxid, '打卡', $this->wechatBot);
        $this->handler->handle($contextB, function ($ctx) { return $ctx; });
        
        // 验证两个签到记录都存在且独立
        $checkInA = CheckIn::where('chatroom', $roomA)->where('wxid', $this->userWxid)->first();
        $checkInB = CheckIn::where('chatroom', $roomB)->where('wxid', $this->userWxid)->first();
        
        expect($checkInA)->not->toBeNull('UTC+8群应该有签到记录');
        expect($checkInB)->not->toBeNull('UTC+0群应该有签到记录');
        expect($checkInA->id)->not->toBe($checkInB->id, '两个签到记录应该是独立的');
        
        // 验证存储时间都是实际的UTC时间
        expect($checkInA->created_at->toDateTimeString())->toBe('2025-09-30 17:00:00', 'UTC+8群存储实际UTC时间');
        expect($checkInB->created_at->toDateTimeString())->toBe('2025-09-30 17:00:00', 'UTC+0群存储实际UTC时间');
        
        // 验证时区判断逻辑
        [$todayStartA, $todayEndA] = TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $roomA);
        [$todayStartB, $todayEndB] = TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $roomB);
        
        expect($checkInA->created_at->between($todayStartA, $todayEndA))->toBeTrue('UTC+8群时区判断正确');
        expect($checkInB->created_at->between($todayStartB, $todayEndB))->toBeTrue('UTC+0群时区判断正确');
        
        Carbon::setTestNow(); // 重置测试时间
    });
    
    it('验证统计功能的时区一致性', function () {
        // 设置群时区为 +8
        $this->wechatBot->setMeta('group_config.timezone_test_room@chatroom.room_timezone_special', 8);
        
        // 创建几天的签到记录（使用实际UTC时间）
        $utcTimes = [
            '2025-09-28 10:00:00', // 北京时间2025-09-28 18:00
            '2025-09-29 15:00:00', // 北京时间2025-09-29 23:00
            '2025-09-30 02:00:00', // 北京时间2025-09-30 10:00
        ];
        
        foreach ($utcTimes as $utcTime) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => $this->userWxid,
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $utcTime, 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $utcTime, 'UTC');
            $checkIn->save();
        }
        
        // 使用统计服务分析
        $analytics = new CheckInAnalytics($this->userWxid, $this->roomWxid, [$this->userWxid => ['nickname' => '测试用户']], $this->wechatBot);
        $stats = $analytics->getPersonalStats();
        
        // 验证统计结果：应该识别出3天的连续打卡
        expect($stats['total_days'])->toBe(3, '应该统计到3天打卡');
        expect($stats['current_streak'])->toBe(3, '应该有3天连续打卡');
        expect($stats['max_streak'])->toBe(3, '最大连击应该是3天');
        expect($stats['missed_days'])->toBe(0.0, '不应该有缺勤天数');
    });
});

// 辅助方法：创建时区测试上下文
function createTimezoneTestContext($roomWxid, $userWxid, $keyword, $wechatBot): XbotMessageContext
{
    $requestData = [
        'msg_type' => 'MT_RECV_TEXT_MSG',
        'room_wxid' => $roomWxid,
        'from_wxid' => $userWxid,
        'from_remark' => '时区测试用户',
        'to_wxid' => $wechatBot->wxid,
        'msg' => $keyword,
        'msgid' => 'timezone_test_' . uniqid(),
    ];
    
    return new XbotMessageContext(
        wechatBot: $wechatBot,
        requestRawData: $requestData,
        msgType: 'MT_RECV_TEXT_MSG',
        clientId: 12345
    );
}