<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use App\Services\XbotConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Services\CheckInPermissionService;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Pipelines\Xbot\Message\KeywordResponseHandler;
use App\Pipelines\Xbot\Message\ChatwootHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Configuration System Tests', function () {
    
    beforeEach(function () {
        // 创建测试所需的基础数据
        $this->wechatClient = WechatClient::factory()->create([
            'token' => 'test-token',
            'endpoint' => 'http://localhost:8001',
        ]);
        
        $this->wechatBot = WechatBot::factory()->create([
            'wxid' => 'test-bot',
            'wechat_client_id' => $this->wechatClient->id,
            'client_id' => 'test-client-1',
        ]);
    });

    describe('XbotConfigManager', function () {
        
        test('should have correct default configuration keys', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            $availableCommands = $configManager->getAvailableCommands();
            
            $expectedKeys = [
                'chatwoot', 
                'room_msg', 
                'keyword_resources', 
                'keyword_sync', 
                'payment_auto', 
                'check_in'
            ];
            
            foreach ($expectedKeys as $key) {
                expect($availableCommands)->toHaveKey($key);
            }
        });
        
        test('should enable and disable configurations correctly', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            
            // 测试启用配置
            expect($configManager->isEnabled('chatwoot'))->toBeFalse();
            $this->wechatBot->setMeta('chatwoot_enabled', true);
            expect($configManager->isEnabled('chatwoot'))->toBeTrue();
            
            // 测试禁用配置
            $this->wechatBot->setMeta('chatwoot_enabled', false);
            expect($configManager->isEnabled('chatwoot'))->toBeFalse();
        });
        
        test('should return correct configuration names', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            
            expect($configManager->getConfigName('chatwoot'))->toBe('Chatwoot同步');
            expect($configManager->getConfigName('room_msg'))->toBe('群消息处理');
            expect($configManager->getConfigName('keyword_sync'))->toBe('Chatwoot同步关键词');
        });
    });

    describe('ChatroomMessageFilter', function () {
        
        test('should allow messages when room_msg is enabled globally', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            $filter = new ChatroomMessageFilter($this->wechatBot, $configManager);
            
            // 启用全局群消息处理
            $this->wechatBot->setMeta('room_msg_enabled', true);
            
            // 普通消息应该被处理
            expect($filter->shouldProcess('test-room@chatroom', '普通消息'))->toBeTrue();
            
            // 配置命令始终被处理
            expect($filter->shouldProcess('test-room@chatroom', '/set room_listen 1'))->toBeTrue();
        });
        
        test('should block messages when room_msg is disabled globally', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            $filter = new ChatroomMessageFilter($this->wechatBot, $configManager);
            
            // 禁用全局群消息处理
            $this->wechatBot->setMeta('room_msg_enabled', false);
            
            // 普通消息应该被阻止
            expect($filter->shouldProcess('test-room@chatroom', '普通消息'))->toBeFalse();
            
            // 但配置命令始终被处理
            expect($filter->shouldProcess('test-room@chatroom', '/set room_listen 1'))->toBeTrue();
        });
        
        test('should handle room-specific overrides correctly', function () {
            $configManager = new XbotConfigManager($this->wechatBot);
            $filter = new ChatroomMessageFilter($this->wechatBot, $configManager);
            
            $roomId = 'test-room@chatroom';
            
            // 全局禁用，但该群特例启用
            $this->wechatBot->setMeta('room_msg_enabled', false);
            $filter->setRoomListenStatus($roomId, true);
            
            expect($filter->shouldProcess($roomId, '普通消息'))->toBeTrue();
            expect($filter->getRoomListenStatus($roomId))->toBeTrue();
            
            // 全局启用，但该群特例禁用
            $this->wechatBot->setMeta('room_msg_enabled', true);
            $filter->setRoomListenStatus($roomId, false);
            
            expect($filter->shouldProcess($roomId, '普通消息'))->toBeFalse();
            expect($filter->getRoomListenStatus($roomId))->toBeFalse();
        });
    });

    describe('CheckInPermissionService', function () {
        
        test('should check room message permission as prerequisite', function () {
            $service = new CheckInPermissionService($this->wechatBot);
            $roomId = 'test-room@chatroom';
            
            // 如果群消息处理被禁用，签到也应该被禁用
            $this->wechatBot->setMeta('room_msg_enabled', false);
            $this->wechatBot->setMeta('check_in_enabled', true);
            
            expect($service->canCheckIn($roomId))->toBeFalse();
        });
        
        test('should handle global check-in configuration correctly', function () {
            $service = new CheckInPermissionService($this->wechatBot);
            $roomId = 'test-room@chatroom';
            
            // 启用群消息处理（前置条件）
            $this->wechatBot->setMeta('room_msg_enabled', true);
            
            // 全局启用签到
            $this->wechatBot->setMeta('check_in_enabled', true);
            expect($service->canCheckIn($roomId))->toBeTrue();
            
            // 全局禁用签到
            $this->wechatBot->setMeta('check_in_enabled', false);
            expect($service->canCheckIn($roomId))->toBeFalse();
        });
        
        test('should handle room-specific check-in overrides', function () {
            $service = new CheckInPermissionService($this->wechatBot);
            $roomId = 'test-room@chatroom';
            
            // 启用群消息处理（前置条件）
            $this->wechatBot->setMeta('room_msg_enabled', true);
            
            // 全局禁用，但该群特例启用
            $this->wechatBot->setMeta('check_in_enabled', false);
            $service->setRoomCheckInStatus($roomId, true);
            
            expect($service->canCheckIn($roomId))->toBeTrue();
            expect($service->getRoomCheckInStatus($roomId))->toBeTrue();
            
            // 全局启用，但该群特例禁用
            $this->wechatBot->setMeta('check_in_enabled', true);
            $service->setRoomCheckInStatus($roomId, false);
            
            expect($service->canCheckIn($roomId))->toBeFalse();
            expect($service->getRoomCheckInStatus($roomId))->toBeFalse();
        });
    });

    describe('Keyword Sync Logic', function () {
        
        test('ChatwootHandler should identify keyword response messages correctly', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('isKeywordResponseMessage');
            $method->setAccessible(true);
            
            // 测试关键词响应格式
            expect($method->invoke($handler, '【621】真道分解 09-08'))->toBeTrue();
            expect($method->invoke($handler, '【新闻】今日头条新闻'))->toBeTrue();
            
            // 测试非关键词响应格式
            expect($method->invoke($handler, '普通消息'))->toBeFalse();
            expect($method->invoke($handler, '设置成功: chatwoot 已启用'))->toBeFalse();
            expect($method->invoke($handler, '/help 帮助信息'))->toBeFalse();
        });
        
        test('ChatwootHandler should respect keyword_sync configuration for bot messages', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('shouldSyncToChatwoot');
            $method->setAccessible(true);
            
            // 创建模拟的消息上下文
            $context = $this->createMockMessageContext(true); // isFromBot = true
            
            // 当 keyword_sync 启用时，关键词响应应该同步
            $this->wechatBot->setMeta('keyword_sync_enabled', true);
            expect($method->invoke($handler, $context, '【621】真道分解 09-08'))->toBeTrue();
            
            // 当 keyword_sync 禁用时，关键词响应不应该同步
            $this->wechatBot->setMeta('keyword_sync_enabled', false);
            expect($method->invoke($handler, $context, '【621】真道分解 09-08'))->toBeFalse();
            
            // 非关键词响应的机器人消息始终同步
            expect($method->invoke($handler, $context, '设置成功: chatwoot 已启用'))->toBeTrue();
        });
        
        test('ChatwootHandler should always sync user messages regardless of keyword_sync', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('shouldSyncToChatwoot');
            $method->setAccessible(true);
            
            // 创建模拟的用户消息上下文
            $context = $this->createMockMessageContext(false); // isFromBot = false
            
            // 无论 keyword_sync 如何设置，用户消息都应该同步
            $this->wechatBot->setMeta('keyword_sync_enabled', true);
            expect($method->invoke($handler, $context, '621'))->toBeTrue();
            
            $this->wechatBot->setMeta('keyword_sync_enabled', false);
            expect($method->invoke($handler, $context, '621'))->toBeTrue();
        });
    });

    describe('Integration Tests', function () {
        
        test('complete keyword response workflow when keyword_sync is disabled', function () {
            // 设置配置：启用关键词资源，禁用关键词同步
            $this->wechatBot->setMeta('keyword_resources_enabled', true);
            $this->wechatBot->setMeta('keyword_sync_enabled', false);
            
            $configManager = new XbotConfigManager($this->wechatBot);
            
            // 验证配置状态
            expect($configManager->isEnabled('keyword_resources'))->toBeTrue();
            expect($configManager->isEnabled('keyword_sync'))->toBeFalse();
            
            // 模拟 KeywordResponseHandler 的逻辑
            $keywordResponseEnabled = $configManager->isEnabled('keyword_resources');
            $keywordSyncEnabled = $configManager->isEnabled('keyword_sync');
            
            expect($keywordResponseEnabled)->toBeTrue(); // 应该发送关键词响应
            expect($keywordSyncEnabled)->toBeFalse(); // 但不应该同步到 Chatwoot
        });
        
        test('check-in requires both room_msg and check_in to be properly configured', function () {
            $service = new CheckInPermissionService($this->wechatBot);
            $roomId = 'test-room@chatroom';
            
            // 场景1：check_in 启用但 room_msg 禁用 - 应该不能签到
            $this->wechatBot->setMeta('room_msg_enabled', false);
            $this->wechatBot->setMeta('check_in_enabled', true);
            expect($service->canCheckIn($roomId))->toBeFalse();
            
            // 场景2：room_msg 启用但 check_in 禁用 - 应该不能签到
            $this->wechatBot->setMeta('room_msg_enabled', true);
            $this->wechatBot->setMeta('check_in_enabled', false);
            expect($service->canCheckIn($roomId))->toBeFalse();
            
            // 场景3：两者都启用 - 应该能签到
            $this->wechatBot->setMeta('room_msg_enabled', true);
            $this->wechatBot->setMeta('check_in_enabled', true);
            expect($service->canCheckIn($roomId))->toBeTrue();
            
            // 场景4：全局 room_msg 禁用，但该群启用 room_listen，同时 check_in 启用 - 应该能签到
            $filter = new ChatroomMessageFilter($this->wechatBot, new XbotConfigManager($this->wechatBot));
            $this->wechatBot->setMeta('room_msg_enabled', false);
            $this->wechatBot->setMeta('check_in_enabled', true);
            $filter->setRoomListenStatus($roomId, true);
            
            expect($service->canCheckIn($roomId))->toBeTrue();
        });
    });
});

// 辅助方法
function createMockMessageContext(bool $isFromBot): object
{
    return new class($isFromBot) {
        public bool $isFromBot;
        public object $wechatBot;
        
        public function __construct(bool $isFromBot)
        {
            global $wechatBot;
            $this->isFromBot = $isFromBot;
            $this->wechatBot = $wechatBot ?? test()->wechatBot;
        }
    };
}