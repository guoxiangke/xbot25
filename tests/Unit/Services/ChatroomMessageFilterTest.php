<?php

use App\Services\ChatroomMessageFilter;
use App\Services\XbotConfigManager;
use App\Models\WechatBot;

describe('ChatroomMessageFilter Unit Tests', function () {
    
    beforeEach(function () {
        $this->wechatBot = Mockery::mock(WechatBot::class);
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('wxid')->andReturn('test_bot_' . uniqid());
        
        $this->configManager = Mockery::mock(XbotConfigManager::class);
        $this->messageFilter = new ChatroomMessageFilter($this->wechatBot, $this->configManager);
    });
    
    afterEach(function () {
        Mockery::close();
    });
    
    describe('Always Allowed Commands', function () {
        
        test('should allow group configuration commands regardless of room_msg setting', function () {
            $alwaysAllowedCommands = [
                '/set room_listen 1',
                '/set check_in_room 0',
                '/set youtube_room 1',
                '/config room_listen 1',
                '/config check_in_room 0',
                '/config youtube_room 1',
                '/get room_id'
            ];
            
            // 模拟room_msg被禁用
            $this->configManager->shouldReceive('isEnabled')
                ->with('room_msg')
                ->andReturn(false);
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn([]);
            
            foreach ($alwaysAllowedCommands as $command) {
                $result = $this->messageFilter->shouldProcess('test_room@chatroom', $command);
                expect($result)->toBeTrue("Command '{$command}' should always be allowed");
            }
        });
        
        test('should not allow regular messages when room_msg is disabled', function () {
            $this->configManager->shouldReceive('isEnabled')
                ->with('room_msg')
                ->andReturn(false);
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn([]);
            
            $regularMessages = [
                '普通消息',
                'help',
                '签到',
                '【621】关键词响应'
            ];
            
            foreach ($regularMessages as $message) {
                $result = $this->messageFilter->shouldProcess('test_room@chatroom', $message);
                expect($result)->toBeFalse("Regular message '{$message}' should be filtered when room_msg is disabled");
            }
        });
    });
    
    describe('Room-Specific Configuration Logic', function () {
        
        test('should handle global enabled with room exceptions (blacklist mode)', function () {
            $this->configManager->shouldReceive('isEnabled')
                ->with('room_msg')
                ->andReturn(true);
            
            $roomConfigs = [
                'allowed_room@chatroom' => null,  // 继承全局设置
                'blocked_room@chatroom' => false, // 明确禁用
                'enabled_room@chatroom' => true,  // 明确启用（冗余）
            ];
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn($roomConfigs);
            
            // 允许的房间（继承全局设置）
            $result = $this->messageFilter->shouldProcess('allowed_room@chatroom', '测试消息');
            expect($result)->toBeTrue('Room without specific config should inherit global setting');
            
            // 被阻止的房间（黑名单）
            $result = $this->messageFilter->shouldProcess('blocked_room@chatroom', '测试消息');
            expect($result)->toBeFalse('Room explicitly disabled should be blocked');
            
            // 明确启用的房间
            $result = $this->messageFilter->shouldProcess('enabled_room@chatroom', '测试消息');
            expect($result)->toBeTrue('Room explicitly enabled should be allowed');
        });
        
        test('should handle global disabled with room exceptions (whitelist mode)', function () {
            $this->configManager->shouldReceive('isEnabled')
                ->with('room_msg')
                ->andReturn(false);
            
            $roomConfigs = [
                'blocked_room@chatroom' => null,  // 继承全局设置（禁用）
                'allowed_room@chatroom' => true,  // 白名单启用
                'disabled_room@chatroom' => false, // 明确禁用（冗余）
            ];
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn($roomConfigs);
            
            // 被阻止的房间（继承全局设置）
            $result = $this->messageFilter->shouldProcess('blocked_room@chatroom', '测试消息');
            expect($result)->toBeFalse('Room without specific config should inherit global disabled setting');
            
            // 白名单房间
            $result = $this->messageFilter->shouldProcess('allowed_room@chatroom', '测试消息');
            expect($result)->toBeTrue('Room in whitelist should be allowed');
            
            // 明确禁用的房间
            $result = $this->messageFilter->shouldProcess('disabled_room@chatroom', '测试消息');
            expect($result)->toBeFalse('Room explicitly disabled should be blocked');
        });
    });
    
    describe('Room Configuration Management', function () {
        
        test('should set room listen status correctly', function () {
            $roomWxid = 'test_room@chatroom';
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn([]);
            
            $this->wechatBot->shouldReceive('setMeta')
                ->with('room_msg_enabled_specials', [$roomWxid => true])
                ->once()
                ->andReturn(true);
            
            $result = $this->messageFilter->setRoomListenStatus($roomWxid, true);
            expect($result)->toBeTrue();
        });
        
        test('should get room listen status correctly', function () {
            $roomWxid = 'test_room@chatroom';
            $roomConfigs = [
                $roomWxid => true
            ];
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn($roomConfigs);
            
            $result = $this->messageFilter->getRoomListenStatus($roomWxid);
            expect($result)->toBeTrue();
        });
        
        test('should return null for rooms without specific configuration', function () {
            $roomWxid = 'unconfigured_room@chatroom';
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled_specials', [])
                ->andReturn([]);
            
            $result = $this->messageFilter->getRoomListenStatus($roomWxid);
            expect($result)->toBeNull();
        });
    });
});