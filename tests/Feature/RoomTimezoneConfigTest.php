<?php

use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'timezone_bot_test']);
    $this->configManager = new ConfigManager($this->wechatBot);
});

describe('Room Timezone Configuration Management', function () {
    
    test('can set and retrieve room timezone configuration', function () {
        $roomWxid = 'test_room@chatroom';
        $timezoneOffset = 8;
        
        // 设置群时区配置
        $success = $this->configManager->setGroupConfig('room_timezone_special', $timezoneOffset, $roomWxid);
        expect($success)->toBeTrue();
        
        // 验证配置已保存
        $savedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($savedOffset)->toBe($timezoneOffset);
        
        // 验证metadata结构
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->toHaveKey($roomWxid);
        expect($timezoneConfigs[$roomWxid])->toBe($timezoneOffset);
    });
    
    test('can handle multiple room timezone configurations', function () {
        $rooms = [
            'room_asia@chatroom' => 8,      // UTC+8 Asia/Shanghai
            'room_europe@chatroom' => 1,    // UTC+1 Europe/Berlin
            'room_america@chatroom' => -5,  // UTC-5 America/New_York
            'room_pacific@chatroom' => -8,  // UTC-8 America/Los_Angeles
            'room_utc@chatroom' => 0        // UTC+0 Greenwich
        ];
        
        // 设置多个群的时区配置
        foreach ($rooms as $roomWxid => $offset) {
            $success = $this->configManager->setGroupConfig('room_timezone_special', $offset, $roomWxid);
            expect($success)->toBeTrue();
        }
        
        // 验证所有配置都正确保存
        foreach ($rooms as $roomWxid => $expectedOffset) {
            $savedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            expect($savedOffset)->toBe($expectedOffset, "Failed for room: $roomWxid");
        }
        
        // 验证metadata结构包含所有配置
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect(count($timezoneConfigs))->toBe(count($rooms));
        
        foreach ($rooms as $roomWxid => $expectedOffset) {
            expect($timezoneConfigs)->toHaveKey($roomWxid);
            expect($timezoneConfigs[$roomWxid])->toBe($expectedOffset);
        }
    });
    
    test('can update existing room timezone configuration', function () {
        $roomWxid = 'update_test@chatroom';
        
        // 设置初始时区
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        $initialOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($initialOffset)->toBe(8);
        
        // 更新时区配置
        $this->configManager->setGroupConfig('room_timezone_special', -5, $roomWxid);
        $updatedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($updatedOffset)->toBe(-5);
        
        // 验证只有这一个群的配置被更新
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect(count($timezoneConfigs))->toBe(1);
        expect($timezoneConfigs[$roomWxid])->toBe(-5);
    });
    
    test('returns null for rooms without timezone configuration', function () {
        $roomWxid = 'unconfigured_room@chatroom';
        
        // 获取未配置的群时区
        $offset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($offset)->toBeNull();
    });
    
    test('can remove room timezone configuration', function () {
        $roomWxid = 'remove_test@chatroom';
        
        // 先设置配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBe(8);
        
        // 通过设置为null来移除配置
        $this->configManager->setGroupConfig('room_timezone_special', null, $roomWxid);
        expect($this->configManager->getGroupConfig('room_timezone_special', $roomWxid))->toBeNull();
        
        // 验证metadata中不再包含该配置
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($timezoneConfigs)->not->toHaveKey($roomWxid);
    });
    
    test('preserves other room configurations when updating one room', function () {
        $room1 = 'room1@chatroom';
        $room2 = 'room2@chatroom';
        $room3 = 'room3@chatroom';
        
        // 设置多个群的配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $room1);
        $this->configManager->setGroupConfig('room_timezone_special', -5, $room2);
        $this->configManager->setGroupConfig('room_timezone_special', 0, $room3);
        
        // 验证初始配置
        expect($this->configManager->getGroupConfig('room_timezone_special', $room1))->toBe(8);
        expect($this->configManager->getGroupConfig('room_timezone_special', $room2))->toBe(-5);
        expect($this->configManager->getGroupConfig('room_timezone_special', $room3))->toBe(0);
        
        // 更新其中一个群的配置
        $this->configManager->setGroupConfig('room_timezone_special', 3, $room2);
        
        // 验证其他群的配置不受影响
        expect($this->configManager->getGroupConfig('room_timezone_special', $room1))->toBe(8);
        expect($this->configManager->getGroupConfig('room_timezone_special', $room2))->toBe(3);
        expect($this->configManager->getGroupConfig('room_timezone_special', $room3))->toBe(0);
    });
});

describe('Room Timezone Configuration Edge Cases', function () {
    
    test('handles extreme timezone values correctly', function () {
        $extremeTimezones = [
            'room_extreme_east@chatroom' => 12,   // UTC+12
            'room_extreme_west@chatroom' => -12,  // UTC-12
        ];
        
        foreach ($extremeTimezones as $roomWxid => $offset) {
            $success = $this->configManager->setGroupConfig('room_timezone_special', $offset, $roomWxid);
            expect($success)->toBeTrue();
            
            $savedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            expect($savedOffset)->toBe($offset);
        }
    });
    
    test('handles zero timezone offset correctly', function () {
        $roomWxid = 'room_utc@chatroom';
        
        $success = $this->configManager->setGroupConfig('room_timezone_special', 0, $roomWxid);
        expect($success)->toBeTrue();
        
        $savedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($savedOffset)->toBe(0);
        
        // 确保0不会被当作null或false处理
        expect($savedOffset)->not->toBeNull();
        expect($savedOffset)->not->toBeFalse();
    });
    
    test('handles room wxid with special characters', function () {
        $specialRoomWxids = [
            'room-with-dash@chatroom',
            'room_with_underscore@chatroom',
            'room.with.dot@chatroom',
            'room123@chatroom'
        ];
        
        foreach ($specialRoomWxids as $index => $roomWxid) {
            $offset = $index - 2; // 生成不同的时区偏移值
            
            $success = $this->configManager->setGroupConfig('room_timezone_special', $offset, $roomWxid);
            expect($success)->toBeTrue();
            
            $savedOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
            expect($savedOffset)->toBe($offset);
        }
    });
    
    test('maintains configuration consistency across operations', function () {
        $operations = [
            ['room1@chatroom', 8],
            ['room2@chatroom', -5],
            ['room1@chatroom', 3], // 更新room1
            ['room3@chatroom', 0],
            ['room2@chatroom', null], // 删除room2
            ['room4@chatroom', -8]
        ];
        
        foreach ($operations as [$roomWxid, $offset]) {
            $this->configManager->setGroupConfig('room_timezone_special', $offset, $roomWxid);
        }
        
        // 验证最终状态
        expect($this->configManager->getGroupConfig('room_timezone_special', 'room1@chatroom'))->toBe(3);
        expect($this->configManager->getGroupConfig('room_timezone_special', 'room2@chatroom'))->toBeNull();
        expect($this->configManager->getGroupConfig('room_timezone_special', 'room3@chatroom'))->toBe(0);
        expect($this->configManager->getGroupConfig('room_timezone_special', 'room4@chatroom'))->toBe(-8);
        
        // 验证metadata结构
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect(count($timezoneConfigs))->toBe(3); // room1, room3, room4
        expect($timezoneConfigs)->not->toHaveKey('room2@chatroom');
    });
});

describe('Room Timezone Configuration Integration', function () {
    
    test('timezone configuration works with other room configurations', function () {
        $roomWxid = 'multi_config_room@chatroom';
        
        // 设置多种群级别配置
        $this->configManager->setGroupConfig('room_timezone_special', 8, $roomWxid);
        $this->configManager->setGroupConfig('room_alias', 'test-alias', $roomWxid);
        
        // 模拟其他配置（使用bot的setMeta）
        $this->wechatBot->setMeta('room_msg_specials', [$roomWxid => true]);
        $this->wechatBot->setMeta('check_in_specials', [$roomWxid => false]);
        
        // 验证时区配置不受其他配置影响
        $timezoneOffset = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        expect($timezoneOffset)->toBe(8);
        
        $roomAlias = $this->configManager->getGroupConfig('room_alias', $roomWxid);
        expect($roomAlias)->toBe('test-alias');
        
        // 验证metadata结构正确分离
        $timezoneConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        $roomAliases = $this->wechatBot->getMeta('room_alias', []);
        $roomMsgConfigs = $this->wechatBot->getMeta('room_msg_specials', []);
        $checkInConfigs = $this->wechatBot->getMeta('check_in_specials', []);
        
        expect($timezoneConfigs)->toHaveKey($roomWxid);
        expect($roomAliases)->toHaveKey($roomWxid);
        expect($roomMsgConfigs)->toHaveKey($roomWxid);
        expect($checkInConfigs)->toHaveKey($roomWxid);
    });
    
    test('can retrieve all room timezone configurations', function () {
        $testConfigs = [
            'room_beijing@chatroom' => 8,
            'room_tokyo@chatroom' => 9,
            'room_london@chatroom' => 0,
            'room_newyork@chatroom' => -5
        ];
        
        // 设置所有配置
        foreach ($testConfigs as $roomWxid => $offset) {
            $this->configManager->setGroupConfig('room_timezone_special', $offset, $roomWxid);
        }
        
        // 获取所有配置
        $allConfigs = $this->wechatBot->getMeta('room_timezone_specials', []);
        
        // 验证返回的配置完整且正确
        expect(count($allConfigs))->toBe(count($testConfigs));
        
        foreach ($testConfigs as $roomWxid => $expectedOffset) {
            expect($allConfigs)->toHaveKey($roomWxid);
            expect($allConfigs[$roomWxid])->toBe($expectedOffset);
        }
    });
    
    test('handles concurrent configuration updates correctly', function () {
        $roomWxid = 'concurrent_test@chatroom';
        
        // 模拟并发更新场景
        $this->configManager->setGroupConfig('room_timezone_special', 5, $roomWxid);
        $firstRead = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        
        $this->configManager->setGroupConfig('room_timezone_special', -3, $roomWxid);
        $secondRead = $this->configManager->getGroupConfig('room_timezone_special', $roomWxid);
        
        // 验证读取到的是最新值
        expect($firstRead)->toBe(5);
        expect($secondRead)->toBe(-3);
        
        // 验证最终状态正确
        $finalConfig = $this->wechatBot->getMeta('room_timezone_specials', []);
        expect($finalConfig[$roomWxid])->toBe(-3);
    });
});