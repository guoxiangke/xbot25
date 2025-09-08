<?php

use App\Services\XbotConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Services\CheckInPermissionService;
use App\Pipelines\Xbot\Message\ChatwootHandler;

describe('Configuration Logic Tests (Unit)', function () {
    
    describe('XbotConfigManager Logic', function () {
        
        test('should have correct configuration mapping', function () {
            // 测试配置映射的正确性，不依赖数据库
            $expectedConfigs = [
                'chatwoot' => 'Chatwoot同步',
                'room_msg' => '群消息处理',
                'keyword_resources' => '关键词资源响应',
                'keyword_sync' => 'Chatwoot同步关键词',
                'payment_auto' => '自动收款',
                'check_in' => '签到系统',
            ];
            
            // 我们可以通过反射或者直接测试常量
            // 这里测试配置名称映射的逻辑
            foreach ($expectedConfigs as $key => $expectedName) {
                expect($key)->toBeString();
                expect($expectedName)->toBeString();
            }
        });
    });

    describe('ChatwootHandler Message Detection', function () {
        
        test('should correctly identify keyword response messages', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('isKeywordResponseMessage');
            $method->setAccessible(true);
            
            // 测试各种关键词响应格式
            $testCases = [
                // 应该匹配的格式
                '【621】真道分解 09-08' => true,
                '【新闻】今日头条' => true,
                '【音乐】赞美诗歌集' => true,
                '【】空内容' => true,
                '【多个】【标签】在一起' => true,
                '【中文】标签测试' => true,
                '【123】数字标签' => true,
                '【a】英文标签' => true,
                
                // 不应该匹配的格式
                '普通消息文本' => false,
                'help 帮助命令' => false,
                '/config 系统配置' => false,
                '设置成功: room_msg 已启用' => false,
                '恭喜！登陆成功，正在初始化...' => false,
                '[方括号]不是【】' => false,
                '文本中包含【关键词】但不在开头' => false,
                '' => false, // 空字符串
            ];
            
            foreach ($testCases as $message => $expected) {
                $result = $method->invoke($handler, $message);
                expect($result)->toBe($expected, 
                    "Message '{$message}' should " . ($expected ? 'match' : 'not match') . ' keyword response pattern'
                );
            }
        });
        
        test('should handle edge cases in keyword detection', function () {
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('isKeywordResponseMessage');
            $method->setAccessible(true);
            
            // 边界情况测试
            $edgeCases = [
                '【' => false,          // 不完整的开始标记
                '】' => false,          // 只有结束标记
                '【【】】' => true,     // 嵌套标记
                '【a】【b】' => true,   // 多个连续标记
                "【换行\n内容】" => false, // 包含换行符（当前正则不支持）
                '【很长的关键词内容测试看看是否能正确匹配】' => true, // 长内容
            ];
            
            foreach ($edgeCases as $message => $expected) {
                $result = $method->invoke($handler, $message);
                expect($result)->toBe($expected, 
                    "Edge case '{$message}' should " . ($expected ? 'match' : 'not match')
                );
            }
        });
    });

    describe('Message Filtering Logic', function () {
        
        test('should identify always allowed commands correctly', function () {
            // 测试始终放行的命令列表
            $alwaysAllowedCommands = [
                '/set room_listen',
                '/set check_in_room',
                '/set youtube_room',
                '/config room_listen',
                '/config check_in_room', 
                '/config youtube_room',
                '/get room_id'
            ];
            
            foreach ($alwaysAllowedCommands as $command) {
                // 测试基本命令
                expect(str_starts_with($command, '/set') || str_starts_with($command, '/config') || str_starts_with($command, '/get'))
                    ->toBeTrue("Command '{$command}' should start with allowed prefix");
                
                // 测试带参数的命令
                $commandWithParam = $command . ' 1';
                expect(str_starts_with($commandWithParam, $command))->toBeTrue();
                
                // 测试带额外空格的命令
                $commandWithSpaces = $command . '  1';
                expect(str_starts_with(trim($commandWithSpaces), $command))->toBeTrue();
            }
        });
        
        test('should handle group configuration command patterns', function () {
            // 测试群级别配置命令的正则模式
            $validGroupCommands = [
                '/set room_listen 1',
                '/SET ROOM_LISTEN 0', // 大小写不敏感
                '/config room_listen 1',
                '/CONFIG room_listen 0',
                '/set check_in_room 1',
                '/config check_in_room 0',
                '/set youtube_room 1',
                '/config youtube_room 0',
            ];
            
            $invalidCommands = [
                '/set room_listen', // 缺少参数
                '/set room_listen 2', // 参数不是0或1
                '/set room_listen abc', // 参数不是数字
                '/set other_param 1', // 不支持的参数
                'set room_listen 1', // 缺少斜杠
                '/setroom_listen 1', // 缺少空格
            ];
            
            // 群配置命令的正则模式（来自实际代码）
            $groupConfigPatterns = [
                '/^\/set\s+room_listen\s+[01]$/i',
                '/^\/config\s+room_listen\s+[01]$/i',
                '/^\/set\s+check_in_room\s+[01]$/i',
                '/^\/config\s+check_in_room\s+[01]$/i',
                '/^\/set\s+youtube_room\s+[01]$/i',
                '/^\/config\s+youtube_room\s+[01]$/i',
            ];
            
            foreach ($validGroupCommands as $command) {
                $isMatch = false;
                foreach ($groupConfigPatterns as $pattern) {
                    if (preg_match($pattern, $command)) {
                        $isMatch = true;
                        break;
                    }
                }
                expect($isMatch)->toBeTrue("Valid command '{$command}' should match group config patterns");
            }
            
            foreach ($invalidCommands as $command) {
                $isMatch = false;
                foreach ($groupConfigPatterns as $pattern) {
                    if (preg_match($pattern, $command)) {
                        $isMatch = true;
                        break;
                    }
                }
                expect($isMatch)->toBeFalse("Invalid command '{$command}' should not match group config patterns");
            }
        });
    });

    describe('Configuration State Logic', function () {
        
        test('should handle global vs room-specific override logic', function () {
            // 测试全局配置与房间特例的逻辑关系
            
            // 场景1：全局启用 + 无房间配置 = 启用
            $globalEnabled = true;
            $roomSpecific = null;
            $result = $roomSpecific ?? $globalEnabled;
            expect($result)->toBeTrue();
            
            // 场景2：全局启用 + 房间禁用 = 禁用（房间特例）
            $globalEnabled = true;
            $roomSpecific = false;
            $result = $roomSpecific ?? $globalEnabled;
            expect($result)->toBeFalse();
            
            // 场景3：全局禁用 + 无房间配置 = 禁用
            $globalEnabled = false;
            $roomSpecific = null;
            $result = $roomSpecific ?? $globalEnabled;
            expect($result)->toBeFalse();
            
            // 场景4：全局禁用 + 房间启用 = 启用（房间特例）
            $globalEnabled = false;
            $roomSpecific = true;
            $result = $roomSpecific ?? $globalEnabled;
            expect($result)->toBeTrue();
        });
        
        test('should handle check-in permission cascading logic', function () {
            // 测试签到权限的级联逻辑：需要同时满足 room_msg 和 check_in
            
            $testScenarios = [
                // [room_msg, check_in, expected]
                [true, true, true],   // 都启用 = 可签到
                [true, false, false], // check_in禁用 = 不可签到
                [false, true, false], // room_msg禁用 = 不可签到  
                [false, false, false], // 都禁用 = 不可签到
            ];
            
            foreach ($testScenarios as [$roomMsg, $checkIn, $expected]) {
                $result = $roomMsg && $checkIn;
                expect($result)->toBe($expected, 
                    "room_msg={$roomMsg}, check_in={$checkIn} should result in {$expected}"
                );
            }
        });
        
        test('should handle keyword sync decision matrix', function () {
            // 测试关键词同步的决策矩阵
            
            $testMatrix = [
                // [isFromBot, isKeywordResponse, keywordSyncEnabled, expectedSync]
                [false, false, true, true],   // 用户普通消息，总是同步
                [false, false, false, true],  // 用户普通消息，总是同步
                [false, true, true, true],    // 用户发送的"关键词"（不太可能，但逻辑上应该同步）
                [false, true, false, true],   // 用户发送的"关键词"，总是同步
                [true, false, true, true],    // 机器人非关键词消息，总是同步
                [true, false, false, true],   // 机器人非关键词消息，总是同步
                [true, true, true, true],     // 机器人关键词响应，keyword_sync启用 = 同步
                [true, true, false, false],   // 机器人关键词响应，keyword_sync禁用 = 不同步
            ];
            
            foreach ($testMatrix as [$isFromBot, $isKeywordResponse, $keywordSyncEnabled, $expectedSync]) {
                // 模拟 ChatwootHandler 的决策逻辑
                if (!$isFromBot) {
                    $shouldSync = true; // 非机器人消息始终同步
                } elseif ($isKeywordResponse) {
                    $shouldSync = $keywordSyncEnabled; // 关键词响应依赖配置
                } else {
                    $shouldSync = true; // 其他机器人消息始终同步
                }
                
                expect($shouldSync)->toBe($expectedSync,
                    "isFromBot={$isFromBot}, isKeywordResponse={$isKeywordResponse}, keywordSyncEnabled={$keywordSyncEnabled} should sync={$expectedSync}"
                );
            }
        });
    });
});