<?php

use App\Services\CheckInPermissionService;
use App\Services\Managers\ConfigManager;
use App\Pipelines\Xbot\Message\CheckInMessageHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'checkin_timezone_bot']);
    $this->configManager = new ConfigManager($this->wechatBot);
    $this->checkInService = new CheckInPermissionService($this->wechatBot);
    
    // 启用签到功能
    $this->configManager->setConfig('check_in', true);
    $this->configManager->setConfig('room_msg', true);
});

describe('Check-in Timezone Integration', function () {
    
    test('check-in respects room timezone configuration for daily reset', function () {
        $roomWxid = 'timezone_room@chatroom';
        
        // 设置群时区为UTC+9（东京时间）
        $this->configManager->setGroupConfig('room_timezone_special', 9, $roomWxid);
        
        // 启用该群的签到
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 验证时区配置已保存
        $timezoneOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($timezoneOffset)->toBe(9);
        
        // 验证签到权限
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeTrue();
        
        // 验证群时区配置与签到配置共存
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        $checkInConfigs = $this->wechatBot->getMeta('check_in_specials', []);
        
        expect($timezoneConfigs)->toHaveKey($roomWxid);
        expect($checkInConfigs)->toHaveKey($roomWxid);
        expect($timezoneConfigs[$roomWxid])->toBe(9);
        expect($checkInConfigs[$roomWxid])->toBe(true);
    });
    
    test('multiple rooms with different timezones maintain independent check-in configs', function () {
        $rooms = [
            'room_tokyo@chatroom' => ['timezone' => 9, 'checkin' => true],
            'room_london@chatroom' => ['timezone' => 0, 'checkin' => true],
            'room_newyork@chatroom' => ['timezone' => -5, 'checkin' => false],
            'room_sydney@chatroom' => ['timezone' => 11, 'checkin' => true]
        ];
        
        // 设置各群的时区和签到配置
        foreach ($rooms as $roomWxid => $config) {
            $this->configManager->setGroupConfig('room_timezone_special', $config['timezone'], $roomWxid);
            $this->checkInService->setRoomCheckInStatus($roomWxid, $config['checkin']);
        }
        
        // 验证所有配置都正确独立保存
        foreach ($rooms as $roomWxid => $config) {
            $savedTimezone = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            $savedCheckIn = $this->checkInService->getRoomCheckInStatus($roomWxid);
            
            expect($savedTimezone)->toBe($config['timezone'], "Timezone failed for $roomWxid");
            expect($savedCheckIn)->toBe($config['checkin'], "Check-in failed for $roomWxid");
        }
        
        // 验证签到权限正确计算
        foreach ($rooms as $roomWxid => $config) {
            $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
            expect($canCheckIn)->toBe($config['checkin'], "Check-in permission failed for $roomWxid");
        }
    });
    
    test('timezone configuration does not affect check-in permission logic', function () {
        $roomWxid = 'permission_test@chatroom';
        
        // 确保群没有任何特殊配置
        $roomMsgSpecials = $this->wechatBot->getMeta('room_msg_specials', []);
        $checkInSpecials = $this->wechatBot->getMeta('check_in_specials', []);
        expect($roomMsgSpecials)->not->toHaveKey($roomWxid);
        expect($checkInSpecials)->not->toHaveKey($roomWxid);
        
        // 设置时区但不设置签到特殊配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        
        // 验证签到权限依然遵循全局配置（room_msg + check_in）
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeTrue(); // 因为全局 room_msg 和 check_in 都已启用
        
        // 关闭全局room_msg
        $this->configManager->setConfig('room_msg', false);
        
        // 重新创建CheckInPermissionService以获取最新配置
        $this->checkInService = new CheckInPermissionService($this->wechatBot);
        
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeFalse(); // 时区配置不影响权限逻辑
        
        // 为该群启用签到特殊配置
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeTrue(); // 特殊配置绕过room_msg要求
    });
    
    test('can simulate check-in scenarios across different timezones', function () {
        // 模拟北京时间早上8点的签到场景
        $beijingRoom = 'beijing_office@chatroom';
        $this->configManager->setGroupConfig('room_timezone_special', 8, $beijingRoom);
        $this->checkInService->setRoomCheckInStatus($beijingRoom, true);
        
        // 模拟纽约时间早上8点的签到场景  
        $newyorkRoom = 'newyork_office@chatroom';
        $this->configManager->setGroupConfig('room_timezone_special', -5, $newyorkRoom);
        $this->checkInService->setRoomCheckInStatus($newyorkRoom, true);
        
        // 模拟伦敦时间早上8点的签到场景
        $londonRoom = 'london_office@chatroom';
        $this->configManager->setGroupConfig('room_timezone_special', 0, $londonRoom);
        $this->checkInService->setRoomCheckInStatus($londonRoom, true);
        
        // 验证所有房间都可以签到
        expect($this->checkInService->canCheckIn($beijingRoom))->toBeTrue();
        expect($this->checkInService->canCheckIn($newyorkRoom))->toBeTrue();
        expect($this->checkInService->canCheckIn($londonRoom))->toBeTrue();
        
        // 验证时区配置正确
        expect($this->configManager->getGroupConfig('room_timezone_special', $beijingRoom))->toBe(8);
        expect($this->configManager->getGroupConfig('room_timezone_special', $newyorkRoom))->toBe(-5);
        expect($this->configManager->getGroupConfig('room_timezone_special', $londonRoom))->toBe(0);
        
        // 验证配置结构完整性
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        $checkInConfigs = $this->wechatBot->getMeta('check_in_specials', []);
        
        expect(count($timezoneConfigs))->toBe(3);
        expect(count($checkInConfigs))->toBe(3);
        
        foreach ([$beijingRoom, $newyorkRoom, $londonRoom] as $room) {
            expect($timezoneConfigs)->toHaveKey($room);
            expect($checkInConfigs)->toHaveKey($room);
        }
    });
});

describe('Check-in Timezone Edge Cases', function () {
    
    test('check-in works in rooms with extreme timezone offsets', function () {
        $extremeRooms = [
            'extreme_east@chatroom' => 12,   // UTC+12 (例如：斐济)
            'extreme_west@chatroom' => -12   // UTC-12 (例如：贝克岛)
        ];
        
        foreach ($extremeRooms as $roomWxid => $timezoneOffset) {
            // 设置极端时区
            $this->configManager->setGroupConfig('room_timezone_special', $timezoneOffset, $roomWxid);
            
            // 启用签到
            $this->checkInService->setRoomCheckInStatus($roomWxid, true);
            
            // 验证配置正确保存
            $savedTimezone = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            expect($savedTimezone)->toBe($timezoneOffset);
            
            // 验证签到权限正常
            $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
            expect($canCheckIn)->toBeTrue();
        }
    });
    
    test('check-in handles timezone configuration removal gracefully', function () {
        $roomWxid = 'timezone_removal_test@chatroom';
        
        // 设置时区和签到配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 验证初始状态
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBe(8);
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue();
        
        // 移除时区配置
        $this->configManager->setGroupConfig('room_timezone_special', null, $roomWxid);
        
        // 验证时区配置已移除但签到配置保持
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBeNull();
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue(); // 签到配置不受影响
        
        // 验证metadata结构
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        $checkInConfigs = $this->wechatBot->getMeta('check_in_specials', []);
        
        expect($timezoneConfigs)->not->toHaveKey($roomWxid);
        expect($checkInConfigs)->toHaveKey($roomWxid);
    });
    
    test('check-in handles zero timezone offset correctly', function () {
        $roomWxid = 'utc_room@chatroom';
        
        // 设置UTC时区（偏移为0）
        $this->configManager->setGroupConfig('room_timezone_special', 0, $roomWxid);
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 验证0时区偏移被正确处理
        $savedTimezone = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($savedTimezone)->toBe(0);
        expect($savedTimezone)->not->toBeNull();
        expect($savedTimezone)->not->toBeFalse();
        
        // 验证签到功能正常
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue();
    });
    
    test('check-in configuration survives timezone updates', function () {
        $roomWxid = 'timezone_update_test@chatroom';
        
        // 设置初始配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 验证初始状态
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBe(8);
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue();
        
        // 更新时区配置
        $this->configManager->setGroupConfig('room_timezone_special', -5, $roomWxid);
        
        // 验证时区更新但签到配置保持
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBe(-5);
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue();
        
        // 再次更新时区
        $this->configManager->setGroupConfig('room_timezone_special', 0, $roomWxid);
        
        // 验证签到配置仍然保持
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBe(0);
        expect($this->checkInService->canCheckIn($roomWxid))->toBeTrue();
    });
});

describe('Check-in Timezone Integration Scenarios', function () {
    
    test('mixed global and room-specific configurations work correctly', function () {
        // 设置全局配置：check_in启用，room_msg禁用
        $this->configManager->setConfig('check_in', true);
        $this->configManager->setConfig('room_msg', false);
        
        $rooms = [
            // 有签到特殊配置的群（绕过room_msg要求）
            'special_checkin@chatroom' => ['timezone' => 8, 'checkin_special' => true, 'expected' => true],
            // 有时区配置但无签到特殊配置的群（受room_msg限制）
            'timezone_only@chatroom' => ['timezone' => -5, 'checkin_special' => null, 'expected' => false],
            // 既有时区又有签到特殊配置的群
            'both_config@chatroom' => ['timezone' => 0, 'checkin_special' => true, 'expected' => true],
            // 无任何特殊配置的群（受全局配置限制）
            'no_config@chatroom' => ['timezone' => null, 'checkin_special' => null, 'expected' => false]
        ];
        
        // 设置各群配置
        foreach ($rooms as $roomWxid => $config) {
            if ($config['timezone'] !== null) {
                $this->configManager->setGroupConfig('room_timezone_special', $config['timezone'], $roomWxid);
            }
            if ($config['checkin_special'] !== null) {
                $this->checkInService->setRoomCheckInStatus($roomWxid, $config['checkin_special']);
            }
        }
        
        // 验证签到权限结果
        foreach ($rooms as $roomWxid => $config) {
            $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
            expect($canCheckIn)->toBe($config['expected'], "Failed for room: $roomWxid");
        }
    });
    
    test('check-in permission status includes timezone information', function () {
        $roomWxid = 'status_test@chatroom';
        
        // 设置时区和签到配置
        $this->configManager->setGroupConfig('room_timezone_special', 9, $roomWxid);
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 获取权限状态详情
        $status = $this->checkInService->getPermissionStatusDescription($roomWxid);
        
        // 验证状态结构包含必要信息
        expect($status)->toBeArray();
        expect($status)->toHaveKeys([
            'room_msg_allowed',
            'global_check_in_enabled',
            'room_specific_status',
            'final_permission',
            'mode'
        ]);
        
        // 验证最终权限为true
        expect($status['final_permission'])->toBeTrue();
        expect($status['room_specific_status'])->toBeTrue();
        
        // 验证时区配置独立存在
        $timezoneOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($timezoneOffset)->toBe(9);
    });
    
    test('bulk configuration operations maintain consistency', function () {
        $bulkRooms = [];
        
        // 生成10个房间的配置
        for ($i = 1; $i <= 10; $i++) {
            $roomWxid = "bulk_room_{$i}@chatroom";
            $timezoneOffset = ($i % 25) - 12; // 生成-12到+12的时区偏移
            $checkinEnabled = ($i % 2 === 0); // 偶数房间启用签到
            
            $bulkRooms[$roomWxid] = [
                'timezone' => $timezoneOffset,
                'checkin' => $checkinEnabled
            ];
        }
        
        // 批量设置配置
        foreach ($bulkRooms as $roomWxid => $config) {
            $this->configManager->setGroupConfig('room_timezone_special', $config['timezone'], $roomWxid);
            $this->checkInService->setRoomCheckInStatus($roomWxid, $config['checkin']);
        }
        
        // 验证所有配置都正确保存
        foreach ($bulkRooms as $roomWxid => $config) {
            $savedTimezone = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            $savedCheckIn = $this->checkInService->getRoomCheckInStatus($roomWxid);
            $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
            
            expect($savedTimezone)->toBe($config['timezone'], "Timezone mismatch for $roomWxid");
            expect($savedCheckIn)->toBe($config['checkin'], "Check-in mismatch for $roomWxid");
            expect($canCheckIn)->toBe($config['checkin'], "Permission mismatch for $roomWxid");
        }
        
        // 验证metadata结构完整性
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        $checkInConfigs = $this->wechatBot->getMeta('check_in_specials', []);
        
        expect(count($timezoneConfigs))->toBe(10);
        expect(count($checkInConfigs))->toBe(10);
        
        // 验证配置内容正确性
        foreach ($bulkRooms as $roomWxid => $config) {
            expect($timezoneConfigs)->toHaveKey($roomWxid);
            expect($checkInConfigs)->toHaveKey($roomWxid);
            expect($timezoneConfigs[$roomWxid])->toBe($config['timezone']);
            expect($checkInConfigs[$roomWxid])->toBe($config['checkin']);
        }
    });
});