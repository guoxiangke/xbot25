<?php

use App\Models\CheckIn;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Analytics\CheckInAnalytics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock HTTP请求以避免实际网络调用
    Http::fake();
    
    // 创建测试机器人
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'ranking_test_bot']);
    
    // 启用全局配置
    $this->wechatBot->setMeta('check_in_enabled', true);
    $this->wechatBot->setMeta('room_msg_enabled', true);
    
    // 测试群组
    $this->roomWxid = 'ranking_test_room@chatroom';
    
    // 测试用户
    $this->users = [
        'user1' => ['wxid' => 'user1', 'nickname' => '用户一', 'remark' => '用户一备注'],
        'user2' => ['wxid' => 'user2', 'nickname' => '用户二', 'remark' => '用户二备注'],
        'user3' => ['wxid' => 'user3', 'nickname' => '用户三', 'remark' => '用户三备注'],
    ];
    
    // 设置联系人数据
    $this->wechatBot->setMeta('contacts', $this->users);
    
    // 创建Handler
    $this->handler = new CheckInMessageHandler();
});

describe('打卡排行功能测试', function () {
    it('验证总打卡天数排行榜功能', function () {
        // 创建不同用户的打卡记录
        $dates = ['2025-09-28', '2025-09-29', '2025-09-30'];
        
        // 用户1：3天打卡
        foreach ($dates as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => 'user1',
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 用户2：2天打卡
        foreach (array_slice($dates, 0, 2) as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => 'user2',
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 11:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 11:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 用户3：1天打卡
        $checkIn = new CheckIn([
            'chatroom' => $this->roomWxid,
            'wxid' => 'user3',
        ]);
        $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 12:00:00', 'UTC');
        $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 12:00:00', 'UTC');
        $checkIn->save();
        
        // 测试排行榜功能
        $analytics = new CheckInAnalytics('', $this->roomWxid, $this->users, $this->wechatBot);
        $ranking = $analytics->getTotalDaysRanking(10);
        
        // 验证排行榜结果
        expect($ranking)->toHaveCount(3, '应该有3个用户');
        
        // 验证排序正确性
        expect($ranking[0]['wxid'])->toBe('user1', '第1名应该是user1');
        expect($ranking[0]['total_days'])->toBe(3, 'user1应该有3天打卡');
        expect($ranking[0]['rank'])->toBe(1, 'user1应该是第1名');
        
        expect($ranking[1]['wxid'])->toBe('user2', '第2名应该是user2');
        expect($ranking[1]['total_days'])->toBe(2, 'user2应该有2天打卡');
        expect($ranking[1]['rank'])->toBe(2, 'user2应该是第2名');
        
        expect($ranking[2]['wxid'])->toBe('user3', '第3名应该是user3');
        expect($ranking[2]['total_days'])->toBe(1, 'user3应该有1天打卡');
        expect($ranking[2]['rank'])->toBe(3, 'user3应该是第3名');
        
        // 验证昵称显示
        expect($ranking[0]['nickname'])->toBe('用户一', '昵称应该正确显示');
        expect($ranking[1]['nickname'])->toBe('用户二', '昵称应该正确显示');
        expect($ranking[2]['nickname'])->toBe('用户三', '昵称应该正确显示');
    });
    
    it('验证连续打卡天数排行榜功能', function () {
        // 创建不同用户的连续打卡记录
        
        // 用户1：连续3天
        $dates1 = ['2025-09-28', '2025-09-29', '2025-09-30'];
        foreach ($dates1 as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => 'user1',
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 10:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 用户2：连续2天
        $dates2 = ['2025-09-29', '2025-09-30'];
        foreach ($dates2 as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => 'user2',
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 11:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 11:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 用户3：不连续（跳过一天）
        $dates3 = ['2025-09-28', '2025-09-30']; // 跳过29号
        foreach ($dates3 as $date) {
            $checkIn = new CheckIn([
                'chatroom' => $this->roomWxid,
                'wxid' => 'user3',
            ]);
            $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 12:00:00', 'UTC');
            $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', $date . ' 12:00:00', 'UTC');
            $checkIn->save();
        }
        
        // 测试连续打卡排行榜
        $analytics = new CheckInAnalytics('', $this->roomWxid, $this->users, $this->wechatBot);
        $ranking = $analytics->getCurrentStreakRanking(10);
        
        // 验证排行榜结果（用户3的当前连击为1，因为不连续）
        expect($ranking)->toHaveCount(3, '应该有3个用户');
        
        // 验证排序正确性
        expect($ranking[0]['wxid'])->toBe('user1', '第1名应该是user1');
        expect($ranking[0]['current_streak'])->toBe(3, 'user1应该有3天连击');
        expect($ranking[0]['rank'])->toBe(1, 'user1应该是第1名');
        
        expect($ranking[1]['wxid'])->toBe('user2', '第2名应该是user2');
        expect($ranking[1]['current_streak'])->toBe(2, 'user2应该有2天连击');
        expect($ranking[1]['rank'])->toBe(2, 'user2应该是第2名');
        
        expect($ranking[2]['wxid'])->toBe('user3', '第3名应该是user3');
        expect($ranking[2]['current_streak'])->toBe(1, 'user3应该有1天连击（最后一天）');
        expect($ranking[2]['rank'])->toBe(3, 'user3应该是第3名');
    });
    
    it('验证打卡排行消息处理', function () {
        // 创建一些打卡记录
        $checkIn = new CheckIn([
            'chatroom' => $this->roomWxid,
            'wxid' => 'user1',
        ]);
        $checkIn->created_at = Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 10:00:00', 'UTC');
        $checkIn->updated_at = Carbon::createFromFormat('Y-m-d H:i:s', '2025-09-30 10:00:00', 'UTC');
        $checkIn->save();
        
        // 创建打卡排行消息上下文
        $context = createRankingTestContext($this->roomWxid, 'user1', '打卡排行', $this->wechatBot);
        
        // 处理消息（应该不报错）
        $result = $this->handler->handle($context, function ($ctx) { return $ctx; });
        
        // 验证处理结果
        expect($result)->toBeInstanceOf(XbotMessageContext::class, '应该返回正常的上下文');
    });
});

// 辅助方法：创建排行榜测试上下文
function createRankingTestContext($roomWxid, $userWxid, $keyword, $wechatBot): XbotMessageContext
{
    $requestData = [
        'msg_type' => 'MT_RECV_TEXT_MSG',
        'room_wxid' => $roomWxid,
        'from_wxid' => $userWxid,
        'from_remark' => '排行测试用户',
        'to_wxid' => $wechatBot->wxid,
        'msg' => $keyword,
        'msgid' => 'ranking_test_' . uniqid(),
    ];
    
    return new XbotMessageContext(
        wechatBot: $wechatBot,
        requestRawData: $requestData,
        msgType: 'MT_RECV_TEXT_MSG',
        clientId: 12345
    );
}