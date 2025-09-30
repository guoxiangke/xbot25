<?php

use App\Models\CheckIn;
use App\Models\WechatBot;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\CheckInPermissionService;
use App\Services\Managers\ConfigManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock HTTP请求以避免实际网络调用
    Http::fake();
    
    // 创建测试机器人
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'test_debug_bot']);
    
    // 启用全局配置 - 使用正确的配置格式
    $this->wechatBot->setMeta('check_in_enabled', true);
    $this->wechatBot->setMeta('room_msg_enabled', true);
    
    // 测试群组
    $this->testRoom = 'debug_room@chatroom';
    $this->testUser = 'debug_user';
    
    // 设置联系人数据
    $contacts = [
        $this->testUser => [
            'wxid' => $this->testUser,
            'nickname' => 'Debug用户',
            'remark' => 'Debug用户'
        ]
    ];
    $this->wechatBot->setMeta('contacts', $contacts);
});

describe('签到权限调试测试', function () {
    it('检查权限配置状态', function () {
        $permissionService = new CheckInPermissionService($this->wechatBot);
        $configManager = new ConfigManager($this->wechatBot);
        
        // 检查全局配置
        $globalCheckIn = $configManager->isEnabled('check_in');
        $globalRoomMsg = $configManager->isEnabled('room_msg');
        
        echo "全局配置状态:\n";
        echo "check_in: " . ($globalCheckIn ? 'true' : 'false') . "\n";
        echo "room_msg: " . ($globalRoomMsg ? 'true' : 'false') . "\n";
        
        // 检查权限状态
        $permissionStatus = $permissionService->getPermissionStatusDescription($this->testRoom);
        echo "权限状态: " . json_encode($permissionStatus, JSON_PRETTY_PRINT) . "\n";
        
        $canCheckIn = $permissionService->canCheckIn($this->testRoom);
        echo "是否可以签到: " . ($canCheckIn ? 'true' : 'false') . "\n";
        
        expect($canCheckIn)->toBeTrue('权限检查应该通过');
    });
    
    it('测试简单签到流程', function () {
        // 确保权限正确
        $permissionService = new CheckInPermissionService($this->wechatBot);
        $canCheckIn = $permissionService->canCheckIn($this->testRoom);
        
        if (!$canCheckIn) {
            echo "权限检查失败，跳过测试\n";
            return;
        }
        
        // 创建签到上下文
        $requestData = [
            'msg_type' => 'MT_RECV_TEXT_MSG',
            'room_wxid' => $this->testRoom,
            'from_wxid' => $this->testUser,
            'from_remark' => 'Debug用户',
            'to_wxid' => $this->wechatBot->wxid,
            'msg' => '打卡',
            'msgid' => 'debug_msg_001',
        ];
        
        $context = new XbotMessageContext(
            wechatBot: $this->wechatBot,
            requestRawData: $requestData,
            msgType: 'MT_RECV_TEXT_MSG',
            clientId: 12345
        );
        
        // 创建Handler并处理
        $handler = new CheckInMessageHandler();
        
        echo "开始处理签到消息...\n";
        $result = $handler->handle($context, function ($ctx) {
            echo "消息处理完成\n";
            return $ctx;
        });
        
        // 检查数据库中是否有签到记录
        $todayString = Carbon::today()->toDateString();
        echo "查询日期: " . $todayString . "\n";
        
        $checkInExists = CheckIn::where('chatroom', $this->testRoom)
            ->where('wxid', $this->testUser)
            ->whereDate('created_at', $todayString)
            ->exists();
            
        echo "签到记录是否存在: " . ($checkInExists ? 'true' : 'false') . "\n";
        
        // 显示所有签到记录用于调试
        $allCheckIns = CheckIn::all();
        echo "所有签到记录: " . $allCheckIns->toJson() . "\n";
        
        // 检查具体的记录
        $record = CheckIn::where('chatroom', $this->testRoom)
            ->where('wxid', $this->testUser)
            ->first();
        if ($record) {
            echo "找到的记录日期: " . $record->created_at->toDateString() . "\n";
            echo "记录的完整时间: " . $record->created_at->toDateTimeString() . "\n";
        }
        
        expect($checkInExists)->toBeTrue('应该创建签到记录');
    });
});