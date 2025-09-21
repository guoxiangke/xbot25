<?php

use App\Services\CheckInPermissionService;
use App\Services\Managers\ConfigManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->wechatBot = XbotTestHelpers::createWechatBot(['wxid' => 'test_bot_checkin']);
    $this->checkInService = new CheckInPermissionService($this->wechatBot);
    $this->configManager = new ConfigManager($this->wechatBot);
});

describe('Check-in Special Config Logic', function () {
    
    test('room with check_in_specials config bypasses room_msg requirement', function () {
        $roomWxid = 'special_room@chatroom';
        
        // 设置全局配置：签到启用，但群消息处理禁用
        $this->configManager->setConfig('check_in', true);
        $this->configManager->setConfig('room_msg', false);
        
        // 为该群设置特殊签到配置
        $this->checkInService->setRoomCheckInStatus($roomWxid, true);
        
        // 验证该群可以签到，即使 room_msg 全局禁用
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeTrue('Room with special check-in config should bypass room_msg requirement');
        
        // 验证该群在特殊配置中
        $roomStatus = $this->checkInService->getRoomCheckInStatus($roomWxid);
        expect($roomStatus)->toBeTrue();
    });
    
    test('room without check_in_specials config still requires room_msg', function () {
        $roomWxid = 'normal_room@chatroom';
        
        // 设置全局配置：签到启用，但群消息处理禁用
        $this->configManager->setConfig('check_in', true);
        $this->configManager->setConfig('room_msg', false);
        
        // 该群没有特殊配置
        
        // 验证该群不能签到，因为需要 room_msg 前置条件
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeFalse('Room without special config should still require room_msg');
        
        // 验证该群没有特殊配置
        $roomStatus = $this->checkInService->getRoomCheckInStatus($roomWxid);
        expect($roomStatus)->toBeNull();
    });
    
    test('room with disabled check_in_specials config cannot check in', function () {
        $roomWxid = 'disabled_room@chatroom';
        
        // 设置全局配置：签到启用，群消息处理也启用
        $this->configManager->setConfig('check_in', true);
        $this->configManager->setConfig('room_msg', true);
        
        // 为该群设置特殊签到配置为禁用
        $this->checkInService->setRoomCheckInStatus($roomWxid, false);
        
        // 验证该群不能签到，因为特殊配置禁用了
        $canCheckIn = $this->checkInService->canCheckIn($roomWxid);
        expect($canCheckIn)->toBeFalse('Room with disabled special config should not allow check-in');
        
        // 验证该群的特殊配置是禁用状态
        $roomStatus = $this->checkInService->getRoomCheckInStatus($roomWxid);
        expect($roomStatus)->toBeFalse();
    });
    
    test('mixed scenario: multiple rooms with different configs', function () {
        $specialRoom = 'special@chatroom';
        $normalRoom = 'normal@chatroom';
        $disabledRoom = 'disabled@chatroom';
        
        // 设置全局配置：签到启用，群消息处理禁用
        $this->configManager->setConfig('check_in', true);
        $this->configManager->setConfig('room_msg', false);
        
        // 为特殊群启用签到
        $this->checkInService->setRoomCheckInStatus($specialRoom, true);
        // 为禁用群禁用签到
        $this->checkInService->setRoomCheckInStatus($disabledRoom, false);
        // 普通群不设置特殊配置
        
        // 验证结果
        expect($this->checkInService->canCheckIn($specialRoom))->toBeTrue('Special room should allow check-in');
        expect($this->checkInService->canCheckIn($normalRoom))->toBeFalse('Normal room should require room_msg');
        expect($this->checkInService->canCheckIn($disabledRoom))->toBeFalse('Disabled room should deny check-in');
        
        // 验证配置状态
        expect($this->checkInService->getRoomCheckInStatus($specialRoom))->toBeTrue();
        expect($this->checkInService->getRoomCheckInStatus($normalRoom))->toBeNull();
        expect($this->checkInService->getRoomCheckInStatus($disabledRoom))->toBeFalse();
    });
});