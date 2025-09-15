<?php

use App\Services\CheckInPermissionService;
use App\Services\Managers\ConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Models\WechatBot;

describe('CheckInPermissionService Unit Tests', function () {
    
    beforeEach(function () {
        $this->wechatBot = Mockery::mock(WechatBot::class);
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('wxid')->andReturn('test_bot_' . uniqid());
        
        $this->checkInService = new CheckInPermissionService($this->wechatBot);
    });
    
    describe('Check-in Permission Logic', function () {
        
        test('should allow check-in when both room_msg and check_in are enabled globally', function () {
            // Mock the room message filter to return true
            $mockRoomFilter = Mockery::mock(ChatroomMessageFilter::class);
            $mockRoomFilter->shouldReceive('shouldProcess')
                ->with('test_room@chatroom', '签到')
                ->andReturn(true);
            
            // Mock the config manager
            $mockConfigManager = Mockery::mock(ConfigManager::class);
            $mockConfigManager->shouldReceive('isEnabled')
                ->with('check_in')
                ->andReturn(true);
            
            // Mock the room check-in configuration
            $this->wechatBot->shouldReceive('getMeta')
                ->with('check_in_specials', [])
                ->andReturn([]);
            
            // Use reflection to inject mocks (for unit testing)
            $reflection = new ReflectionClass($this->checkInService);
            
            $configManagerProperty = $reflection->getProperty('configManager');
            $configManagerProperty->setAccessible(true);
            $configManagerProperty->setValue($this->checkInService, $mockConfigManager);
            
            $roomFilterProperty = $reflection->getProperty('roomFilter');
            $roomFilterProperty->setAccessible(true);
            $roomFilterProperty->setValue($this->checkInService, $mockRoomFilter);
            
            $result = $this->checkInService->canCheckIn('test_room@chatroom');
            expect($result)->toBeTrue();
        });
        
        test('should deny check-in when room_msg is disabled', function () {
            // Mock the room message filter to return false
            $mockRoomFilter = Mockery::mock(ChatroomMessageFilter::class);
            $mockRoomFilter->shouldReceive('shouldProcess')
                ->with('test_room@chatroom', '签到')
                ->andReturn(false);
            
            // Mock the config manager
            $mockConfigManager = Mockery::mock(ConfigManager::class);
            
            // Inject mocks
            $reflection = new ReflectionClass($this->checkInService);
            
            $configManagerProperty = $reflection->getProperty('configManager');
            $configManagerProperty->setAccessible(true);
            $configManagerProperty->setValue($this->checkInService, $mockConfigManager);
            
            $roomFilterProperty = $reflection->getProperty('roomFilter');
            $roomFilterProperty->setAccessible(true);
            $roomFilterProperty->setValue($this->checkInService, $mockRoomFilter);
            
            $result = $this->checkInService->canCheckIn('test_room@chatroom');
            expect($result)->toBeFalse('Check-in should be denied when room message processing is disabled');
        });
        
        test('should deny check-in when check_in is disabled globally', function () {
            // Mock the room message filter to return true
            $mockRoomFilter = Mockery::mock(ChatroomMessageFilter::class);
            $mockRoomFilter->shouldReceive('shouldProcess')
                ->with('test_room@chatroom', '签到')
                ->andReturn(true);
            
            // Mock the config manager
            $mockConfigManager = Mockery::mock(ConfigManager::class);
            $mockConfigManager->shouldReceive('isEnabled')
                ->with('check_in')
                ->andReturn(false);
            
            // Mock the room check-in configuration (no room exceptions)
            $this->wechatBot->shouldReceive('getMeta')
                ->with('check_in_specials', [])
                ->andReturn([]);
            
            // Inject mocks
            $reflection = new ReflectionClass($this->checkInService);
            
            $configManagerProperty = $reflection->getProperty('configManager');
            $configManagerProperty->setAccessible(true);
            $configManagerProperty->setValue($this->checkInService, $mockConfigManager);
            
            $roomFilterProperty = $reflection->getProperty('roomFilter');
            $roomFilterProperty->setAccessible(true);
            $roomFilterProperty->setValue($this->checkInService, $mockRoomFilter);
            
            $result = $this->checkInService->canCheckIn('test_room@chatroom');
            expect($result)->toBeFalse('Check-in should be denied when global check_in is disabled');
        });
    });
    
    describe('Room-Specific Check-in Configuration', function () {
        
        test('should set room check-in status correctly', function () {
            $roomWxid = 'test_room@chatroom';
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('check_in_specials', [])
                ->andReturn([]);
            
            $this->wechatBot->shouldReceive('setMeta')
                ->with('check_in_specials', [$roomWxid => true])
                ->once()
                ->andReturn(true);
            
            $result = $this->checkInService->setRoomCheckInStatus($roomWxid, true);
            expect($result)->toBeTrue();
        });
        
        test('should get room check-in status correctly', function () {
            $roomWxid = 'test_room@chatroom';
            $roomConfigs = [
                $roomWxid => false
            ];
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('check_in_specials', [])
                ->andReturn($roomConfigs);
            
            $result = $this->checkInService->getRoomCheckInStatus($roomWxid);
            expect($result)->toBeFalse();
        });
        
        test('should return null for rooms without specific check-in configuration', function () {
            $roomWxid = 'unconfigured_room@chatroom';
            
            $this->wechatBot->shouldReceive('getMeta')
                ->with('check_in_specials', [])
                ->andReturn([]);
            
            $result = $this->checkInService->getRoomCheckInStatus($roomWxid);
            expect($result)->toBeNull();
        });
    });
    
    describe('Global Check-in Configuration', function () {
        
        test('should correctly report global check-in status', function () {
            $mockConfigManager = Mockery::mock(ConfigManager::class);
            $mockConfigManager->shouldReceive('isEnabled')
                ->with('check_in')
                ->andReturn(true);
            
            // Inject mock
            $reflection = new ReflectionClass($this->checkInService);
            $configManagerProperty = $reflection->getProperty('configManager');
            $configManagerProperty->setAccessible(true);
            $configManagerProperty->setValue($this->checkInService, $mockConfigManager);
            
            $result = $this->checkInService->isGlobalCheckInEnabled();
            expect($result)->toBeTrue();
        });
    });
    
    describe('Permission Status Description', function () {
        
        test('should provide comprehensive permission status', function () {
            // This test would require more complex mocking
            // For now, we'll test that the method returns the expected structure
            
            $roomWxid = 'test_room@chatroom';
            
            // Mock dependencies
            $mockRoomFilter = Mockery::mock(ChatroomMessageFilter::class);
            $mockRoomFilter->shouldReceive('shouldProcess')->andReturn(true);
            
            $mockConfigManager = Mockery::mock(ConfigManager::class);
            $mockConfigManager->shouldReceive('isEnabled')->andReturn(true);
            
            $this->wechatBot->shouldReceive('getMeta')->andReturn([]);
            
            // Inject mocks
            $reflection = new ReflectionClass($this->checkInService);
            
            $configManagerProperty = $reflection->getProperty('configManager');
            $configManagerProperty->setAccessible(true);
            $configManagerProperty->setValue($this->checkInService, $mockConfigManager);
            
            $roomFilterProperty = $reflection->getProperty('roomFilter');
            $roomFilterProperty->setAccessible(true);
            $roomFilterProperty->setValue($this->checkInService, $mockRoomFilter);
            
            $status = $this->checkInService->getPermissionStatusDescription($roomWxid);
            
            expect($status)->toBeArray();
            expect($status)->toHaveKeys([
                'room_msg_allowed',
                'global_check_in_enabled', 
                'room_specific_status',
                'final_permission',
                'mode'
            ]);
        });
    });
    
    afterEach(function () {
        Mockery::close();
    });
});