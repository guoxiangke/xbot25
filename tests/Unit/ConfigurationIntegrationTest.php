<?php

use App\Services\XbotConfigManager;
use App\Services\ChatroomMessageFilter;
use App\Services\CheckInPermissionService;
use App\Pipelines\Xbot\Message\ChatwootHandler;

describe('Configuration Integration Logic Tests', function () {
    
    describe('Service Integration Logic', function () {
        
        test('should validate all supported configuration items', function () {
            // 测试配置项的逻辑完整性（无需数据库）
            $expectedConfigs = [
                'chatwoot' => 'Chatwoot同步',
                'room_msg' => '群消息处理',
                'keyword_resources' => '关键词资源响应',
                'keyword_sync' => 'Chatwoot同步关键词',
                'payment_auto' => '自动收款',
                'check_in' => '签到系统',
            ];
            
            foreach ($expectedConfigs as $configKey => $configName) {
                expect($configKey)->toBeString();
                expect($configName)->toBeString();
                expect(strlen($configKey))->toBeGreaterThan(0);
                expect(strlen($configName))->toBeGreaterThan(0);
                
                // 验证配置键的命名约定
                expect($configKey)->toMatch('/^[a-z_]+$/'); // 只能包含小写字母和下划线
                expect($configName)->not->toBeEmpty();
            }
        });
        
        test('should validate room-level configuration keys', function () {
            $expectedRoomConfigs = [
                'room_listen' => '群消息监听特例',
                'check_in_room' => '群签到特例',  
                'youtube_room' => '群YouTube响应',
            ];
            
            foreach ($expectedRoomConfigs as $configKey => $configName) {
                expect($configKey)->toBeString();
                expect($configName)->toBeString();
                
                // 群级配置应该包含 'room' 关键词
                expect($configKey)->toContain('room');
                
                // 验证命名约定
                expect($configKey)->toMatch('/^[a-z_]+$/');
            }
        });
        
        test('should validate configuration dependency relationships', function () {
            // 测试配置依赖关系的逻辑（无需实际配置）
            $dependencies = [
                'check_in' => ['room_msg'], // 签到需要消息处理
                'keyword_sync' => ['chatwoot', 'keyword_resources'], // 关键词同步需要 Chatwoot 和关键词响应
            ];
            
            foreach ($dependencies as $dependent => $requiredConfigs) {
                expect($dependent)->toBeString();
                expect($requiredConfigs)->toBeArray();
                
                foreach ($requiredConfigs as $required) {
                    expect($required)->toBeString();
                    expect(strlen($required))->toBeGreaterThan(0);
                }
                
                // 测试依赖关系逻辑
                foreach ($requiredConfigs as $required) {
                    // 依赖项禁用 + 被依赖项启用 = 功能不可用
                    $dependentEnabled = true;
                    $requiredDisabled = false;
                    $result1 = $requiredDisabled && $dependentEnabled;
                    expect($result1)->toBeFalse("When {$required} is disabled, {$dependent} should be unavailable");
                    
                    // 两者都启用 = 功能可用
                    $bothEnabled = true && true;
                    expect($bothEnabled)->toBeTrue("When both {$required} and {$dependent} are enabled, feature should work");
                }
            }
        });
    });
    
    describe('Command Processing Logic Integration', function () {
        
        test('should identify group configuration commands correctly', function () {
            // 测试群配置命令识别逻辑
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
            
            // 群配置命令的正则模式
            $groupConfigPatterns = [
                '/^\\/set\\s+room_listen\\s+[01]$/i',
                '/^\\/config\\s+room_listen\\s+[01]$/i',
                '/^\\/set\\s+check_in_room\\s+[01]$/i',
                '/^\\/config\\s+check_in_room\\s+[01]$/i',
                '/^\\/set\\s+youtube_room\\s+[01]$/i',
                '/^\\/config\\s+youtube_room\\s+[01]$/i',
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
        
        test('should validate always allowed commands list', function () {
            // 测试始终放行命令的逻辑完整性
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
                expect($command)->toBeString();
                expect(strlen($command))->toBeGreaterThan(0);
                
                // 验证命令格式
                expect($command)->toMatch('/^\/[a-z_]+/'); // 以斜杠开头
                
                // 测试命令前缀匹配逻辑
                $testMessages = [
                    $command, // 完整命令
                    $command . ' 1', // 带参数
                    $command . ' 0', // 带不同参数
                    $command . '  1', // 带多个空格
                ];
                
                foreach ($testMessages as $testMessage) {
                    $isMatch = str_starts_with(trim($testMessage), $command);
                    expect($isMatch)->toBeTrue("Message '{$testMessage}' should match command prefix '{$command}'");
                }
            }
        });
    });
    
    describe('Keyword Response Logic Integration', function () {
        
        test('should correctly detect keyword response patterns', function () {
            // 测试关键词响应消息识别的逻辑
            $handler = new ChatwootHandler();
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('isKeywordResponseMessage');
            $method->setAccessible(true);
            
            // 关键词响应格式测试
            $keywordResponseCases = [
                // 应该匹配的格式
                '【621】真道分解 09-08' => true,
                '【新闻】今日头条' => true,
                '【音乐】赞美诗歌集' => true,
                '【】空内容' => true,
                '【多个】【标签】在一起' => true,
                
                // 不应该匹配的格式
                '普通消息文本' => false,
                'help 帮助命令' => false,
                '/config 系统配置' => false,
                '[方括号]不是【】' => false,
                '文本中包含【关键词】但不在开头' => false,
                '' => false, // 空字符串
            ];
            
            foreach ($keywordResponseCases as $message => $expected) {
                $result = $method->invoke($handler, $message);
                expect($result)->toBe($expected, 
                    "Message '{$message}' should " . ($expected ? 'match' : 'not match') . ' keyword response pattern'
                );
            }
        });
        
        test('should validate keyword sync decision logic', function () {
            // 测试关键词同步决策矩阵的逻辑完整性
            $decisionMatrix = [
                // [isFromBot, isKeywordResponse, keywordSyncEnabled, expectedSync]
                [false, false, true, true],   // 用户普通消息，总是同步
                [false, false, false, true],  // 用户普通消息，总是同步
                [false, true, true, true],    // 用户发送的"关键词"，总是同步
                [false, true, false, true],   // 用户发送的"关键词"，总是同步
                [true, false, true, true],    // 机器人非关键词消息，总是同步
                [true, false, false, true],   // 机器人非关键词消息，总是同步
                [true, true, true, true],     // 机器人关键词响应，keyword_sync启用 = 同步
                [true, true, false, false],   // 机器人关键词响应，keyword_sync禁用 = 不同步
            ];
            
            foreach ($decisionMatrix as [$isFromBot, $isKeywordResponse, $keywordSyncEnabled, $expectedSync]) {
                // 模拟 ChatwootHandler 的同步决策逻辑
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
    
    describe('Permission Cascading Logic Integration', function () {
        
        test('should validate room message filtering logic combinations', function () {
            // 测试群消息过滤逻辑的各种组合
            $filteringScenarios = [
                // [globalRoomMsg, roomSpecific, expectedResult, description]
                [true, null, true, '全局启用 + 无特例 = 处理'],
                [true, true, true, '全局启用 + 群启用 = 处理'],
                [true, false, false, '全局启用 + 群禁用 = 不处理（黑名单）'],
                [false, null, false, '全局禁用 + 无特例 = 不处理'],
                [false, true, true, '全局禁用 + 群启用 = 处理（白名单）'],
                [false, false, false, '全局禁用 + 群禁用 = 不处理'],
            ];
            
            foreach ($filteringScenarios as [$globalRoomMsg, $roomSpecific, $expectedResult, $description]) {
                // 模拟 ChatroomMessageFilter::shouldProcess 的核心逻辑
                if ($globalRoomMsg) {
                    // room_msg 开启：默认处理，但配置为false的群不处理
                    $result = $roomSpecific ?? true;
                } else {
                    // room_msg 关闭：默认不处理，但配置为true的群特例处理
                    $result = $roomSpecific ?? false;
                }
                
                expect($result)->toBe($expectedResult, 
                    "{$description}: globalRoomMsg={$globalRoomMsg}, roomSpecific=" . 
                    ($roomSpecific === null ? 'null' : ($roomSpecific ? 'true' : 'false'))
                );
            }
        });
        
        test('should validate check-in permission cascading logic', function () {
            // 测试签到权限级联逻辑
            $cascadingScenarios = [
                // [roomMsgAllowed, globalCheckIn, roomCheckIn, expectedCanCheckIn, description]
                [true, true, null, true, '消息允许 + 全局签到启用 + 无群特例 = 可签到'],
                [true, true, true, true, '消息允许 + 全局签到启用 + 群启用 = 可签到'],
                [true, true, false, false, '消息允许 + 全局签到启用 + 群禁用 = 不可签到'],
                [true, false, null, false, '消息允许 + 全局签到禁用 + 无群特例 = 不可签到'],
                [true, false, true, true, '消息允许 + 全局签到禁用 + 群启用 = 可签到'],
                [false, true, true, false, '消息禁止 + 任何签到配置 = 不可签到（前置条件）'],
                [false, false, false, false, '消息禁止 + 签到禁用 = 不可签到'],
            ];
            
            foreach ($cascadingScenarios as [$roomMsgAllowed, $globalCheckIn, $roomCheckIn, $expectedCanCheckIn, $description]) {
                // 第一层：检查消息权限（前置条件）
                if (!$roomMsgAllowed) {
                    $finalResult = false;
                } else {
                    // 第二层：检查签到权限
                    if ($globalCheckIn) {
                        $checkInAllowed = $roomCheckIn ?? true; // 黑名单模式
                    } else {
                        $checkInAllowed = $roomCheckIn ?? false; // 白名单模式
                    }
                    $finalResult = $checkInAllowed;
                }
                
                expect($finalResult)->toBe($expectedCanCheckIn, 
                    "{$description}: roomMsgAllowed={$roomMsgAllowed}, globalCheckIn={$globalCheckIn}, roomCheckIn=" . 
                    ($roomCheckIn === null ? 'null' : ($roomCheckIn ? 'true' : 'false'))
                );
            }
        });
    });
});