<?php

use App\Services\XbotConfigManager;
use App\Models\WechatBot;

describe('XbotConfigManager Unit Tests', function () {
    
    beforeEach(function () {
        // 创建一个mock WechatBot用于单元测试
        $this->wechatBot = Mockery::mock(WechatBot::class);
        $this->wechatBot->shouldReceive('getAttribute')
            ->with('wxid')->andReturn('test_bot_' . uniqid());
        
        $this->configManager = new XbotConfigManager($this->wechatBot);
    });
    
    afterEach(function () {
        Mockery::close();
    });
    
    describe('Configuration Management', function () {
        
        test('should set and get configuration correctly', function () {
            // 使用有效的配置键 'chatwoot'
            $this->wechatBot->shouldReceive('setMeta')
                ->with('chatwoot_enabled', true)
                ->once()
                ->andReturn(true);
                
            $this->wechatBot->shouldReceive('getMeta')
                ->with('chatwoot_enabled', false)
                ->once()
                ->andReturn(true);
            
            $result = $this->configManager->setConfig('chatwoot', true);
            expect($result)->toBeTrue();
            
            $isEnabled = $this->configManager->isEnabled('chatwoot');
            expect($isEnabled)->toBeTrue();
        });
        
        test('should return false for non-existent configuration', function () {
            // 测试不存在的配置应该返回false，而不应该调用getMeta
            $isEnabled = $this->configManager->isEnabled('non_existent_key');
            expect($isEnabled)->toBeFalse();
        });
        
        test('should handle boolean conversion correctly', function () {
            // 测试不同的boolean值转换
            $this->wechatBot->shouldReceive('getMeta')
                ->with('room_msg_enabled', false)
                ->andReturn(1);
            expect($this->configManager->isEnabled('room_msg'))->toBeTrue();
            
            // 创建新的Mock实例来避免expectation冲突
            $mockBot2 = Mockery::mock(WechatBot::class);
            $mockBot2->shouldReceive('getAttribute')->with('wxid')->andReturn('test_bot_2');
            $mockBot2->shouldReceive('getMeta')
                ->with('room_msg_enabled', false)
                ->andReturn(0);
            $configManager2 = new XbotConfigManager($mockBot2);
            expect($configManager2->isEnabled('room_msg'))->toBeFalse();
            
            // 测试null值
            $mockBot3 = Mockery::mock(WechatBot::class);
            $mockBot3->shouldReceive('getAttribute')->with('wxid')->andReturn('test_bot_3');
            $mockBot3->shouldReceive('getMeta')
                ->with('room_msg_enabled', false)
                ->andReturn(null);
            $configManager3 = new XbotConfigManager($mockBot3);
            expect($configManager3->isEnabled('room_msg'))->toBeFalse();
        });
    });
    
    describe('Available Commands', function () {
        
        test('should return list of available commands', function () {
            $availableCommands = $this->configManager->getAvailableCommands();
            
            expect($availableCommands)->toBeArray();
            expect($availableCommands)->toContain('chatwoot');
            expect($availableCommands)->toContain('room_msg');
            expect($availableCommands)->toContain('keyword_resources');
            expect($availableCommands)->toContain('keyword_sync');
            expect($availableCommands)->toContain('payment_auto');
            expect($availableCommands)->toContain('check_in');
        });
        
        test('should validate command availability', function () {
            expect($this->configManager->isValidCommand('chatwoot'))->toBeTrue();
            expect($this->configManager->isValidCommand('invalid_command'))->toBeFalse();
        });
    });
    
    describe('Configuration Names', function () {
        
        test('should return correct configuration names', function () {
            $expectedNames = [
                'chatwoot' => 'Chatwoot同步',
                'room_msg' => '群消息处理',
                'keyword_resources' => '关键词资源响应',
                'keyword_sync' => 'Chatwoot同步关键词',
                'payment_auto' => '自动收款',
                'check_in' => '签到系统',
            ];
            
            foreach ($expectedNames as $key => $expectedName) {
                $actualName = $this->configManager->getConfigName($key);
                expect($actualName)->toBe($expectedName);
            }
        });
        
        test('should return key as name for unknown configurations', function () {
            $unknownKey = 'unknown_config';
            $result = $this->configManager->getConfigName($unknownKey);
            expect($result)->toBe($unknownKey);
        });
    });
});