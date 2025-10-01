<?php

use App\Models\CheckIn;
use App\Services\Analytics\CheckInAnalytics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 创建测试机器人
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'rank_test_bot']);
    
    // 测试群组
    $this->roomWxid = 'rank_test_room@chatroom';
    
    // 设置联系人数据
    $contacts = [
        'user1' => ['nickname' => '用户1'],
        'user2' => ['nickname' => '用户2'],
        'user3' => ['nickname' => '用户3'],
    ];
    $this->wechatBot->setMeta('contacts', $contacts);
    
    // 设置群时区为UTC+8（默认）
    $timezoneMap = ['rank_test_room@chatroom' => 8];
    $this->wechatBot->setMeta('room_timezone_specials', $timezoneMap);
});

describe('CheckInAnalytics getTodayRank 方法测试', function () {
    it('正确计算用户今日打卡排名', function () {
        // 使用固定的基准时间，确保时间不同
        $baseTime = Carbon::now('UTC')->startOfHour(); // 使用整点时间作为基准
        
        // 获取今日范围
        [$todayStart, $todayEnd] = \App\Services\TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $this->roomWxid);
        
        // 第1位打卡：今日开始后1小时（最早）
        $checkIn1 = new CheckIn([
            'wxid' => 'user1',
            'chatroom' => $this->roomWxid,
        ]);
        $checkIn1->created_at = $todayStart->copy()->addHours(1);
        $checkIn1->updated_at = $todayStart->copy()->addHours(1);
        $checkIn1->save();
        
        // 第2位打卡：今日开始后2小时
        $checkIn2 = new CheckIn([
            'wxid' => 'user2',
            'chatroom' => $this->roomWxid,
        ]);
        $checkIn2->created_at = $todayStart->copy()->addHours(2);
        $checkIn2->updated_at = $todayStart->copy()->addHours(2);
        $checkIn2->save();
        
        // 第3位打卡：今日开始后3小时（最晚）
        $checkIn3 = new CheckIn([
            'wxid' => 'user3',
            'chatroom' => $this->roomWxid,
        ]);
        $checkIn3->created_at = $todayStart->copy()->addHours(3);
        $checkIn3->updated_at = $todayStart->copy()->addHours(3);
        $checkIn3->save();
        
        
        // 测试各用户的排名
        $analytics1 = new CheckInAnalytics('user1', $this->roomWxid, [], $this->wechatBot);
        $analytics2 = new CheckInAnalytics('user2', $this->roomWxid, [], $this->wechatBot);
        $analytics3 = new CheckInAnalytics('user3', $this->roomWxid, [], $this->wechatBot);
        
        expect($analytics1->getTodayRank())->toBe(1, 'user1应该是第1位');
        expect($analytics2->getTodayRank())->toBe(2, 'user2应该是第2位');
        expect($analytics3->getTodayRank())->toBe(3, 'user3应该是第3位');
    });
    
    it('不同群的排名独立计算', function () {
        $roomA = 'room_a@chatroom';
        $roomB = 'room_b@chatroom';
        
        // 获取今日范围确保时间在范围内
        [$todayStart, $todayEnd] = \App\Services\TimezoneHelper::getTodayRangeInUtc($this->wechatBot, $this->roomWxid);
        
        // 群A：user1第1位，user2第2位
        $checkInA1 = new CheckIn(['wxid' => 'user1', 'chatroom' => $roomA]);
        $checkInA1->created_at = $todayStart->copy()->addHour();
        $checkInA1->updated_at = $todayStart->copy()->addHour();
        $checkInA1->save();
        
        $checkInA2 = new CheckIn(['wxid' => 'user2', 'chatroom' => $roomA]);
        $checkInA2->created_at = $todayStart->copy()->addHours(2);
        $checkInA2->updated_at = $todayStart->copy()->addHours(2);
        $checkInA2->save();
        
        // 群B：user2第1位，user1第2位（顺序相反）
        $checkInB1 = new CheckIn(['wxid' => 'user2', 'chatroom' => $roomB]);
        $checkInB1->created_at = $todayStart->copy()->addHour();
        $checkInB1->updated_at = $todayStart->copy()->addHour();
        $checkInB1->save();
        
        $checkInB2 = new CheckIn(['wxid' => 'user1', 'chatroom' => $roomB]);
        $checkInB2->created_at = $todayStart->copy()->addHours(2);
        $checkInB2->updated_at = $todayStart->copy()->addHours(2);
        $checkInB2->save();
        
        // 测试群A的排名
        $analyticsA1 = new CheckInAnalytics('user1', $roomA, [], $this->wechatBot);
        $analyticsA2 = new CheckInAnalytics('user2', $roomA, [], $this->wechatBot);
        expect($analyticsA1->getTodayRank())->toBe(1, '群A中user1应该是第1位');
        expect($analyticsA2->getTodayRank())->toBe(2, '群A中user2应该是第2位');
        
        // 测试群B的排名
        $analyticsB1 = new CheckInAnalytics('user1', $roomB, [], $this->wechatBot);
        $analyticsB2 = new CheckInAnalytics('user2', $roomB, [], $this->wechatBot);
        expect($analyticsB2->getTodayRank())->toBe(1, '群B中user2应该是第1位');
        expect($analyticsB1->getTodayRank())->toBe(2, '群B中user1应该是第2位');
    });
    
    it('未打卡用户返回0排名', function () {
        // 创建其他用户的打卡记录
        $now = Carbon::now('UTC');
        CheckIn::create([
            'wxid' => 'user1',
            'chatroom' => $this->roomWxid,
            'created_at' => $now->copy()->subHour(),
            'updated_at' => $now->copy()->subHour(),
        ]);
        
        // 测试未打卡用户的排名
        $analytics = new CheckInAnalytics('user_not_checked_in', $this->roomWxid, [], $this->wechatBot);
        expect($analytics->getTodayRank())->toBe(0, '未打卡用户应该返回0排名');
    });
    
    it('空用户wxid返回0排名', function () {
        // 测试空用户wxid
        $analytics = new CheckInAnalytics('', $this->roomWxid, [], $this->wechatBot);
        expect($analytics->getTodayRank())->toBe(0, '空用户wxid应该返回0排名');
    });
});