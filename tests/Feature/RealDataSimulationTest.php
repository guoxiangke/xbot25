<?php

use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Datasets\XbotMessageDataset;
use Tests\Support\XbotTestHelpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 创建完整的测试环境 - 使用真实数据库记录
    $this->wechatClient = \App\Models\WechatClient::create([
        'token' => 'test-win-token',
        'endpoint' => 'http://localhost:8001',
        'file_url' => 'http://localhost:8004',
        'file_path' => 'C:\\Windows\\test\\',
        'voice_url' => 'http://localhost:8003',
        'silk_path' => '/tmp/test'
    ]);
    
    // 创建真实的数据库记录，而不是TestWechatBot
    $this->wechatBot = \App\Models\WechatBot::create([
        'wxid' => 'wxid_t36o5djpivk312',
        'wechat_client_id' => $this->wechatClient->id,
        'client_id' => 5,
        'login_at' => now(),
        'is_live_at' => now(),
        'expires_at' => now()->addMonths(3)
    ]);
    
    // Mock所有HTTP服务
    XbotTestHelpers::mockXbotService();
    XbotTestHelpers::mockChatwootService();
});

afterEach(function () {
    XbotTestHelpers::cleanup();
});

describe('Real WeChat Client Data Processing', function () {
    
    test('processes real user login message', function () {
        // 基于真实日志的登录消息
        $realLoginData = [
            'type' => 'MT_USER_LOGIN',
            'client_id' => 5,
            'data' => [
                'account' => '',
                'avatar' => 'https://mmhead.c2c.wechat.com/mmhead/ver_1/ERch7iciaO6tKWIbVgEAJx2F7LmjNB9VuevnIhIBvAxWkGPR5ricdnVspadekYddKFO39wtz0mEH3YJuG4nsURgqFPXQU9nKW8G4TLYlo3GEus/132',
                'nickname' => 'AI助理',
                'phone' => '+16268881668',
                'pid' => 14204,
                'unread_msg_count' => 0,
                'wx_user_dir' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_t36o5djpivk312\\',
                'wxid' => 'wxid_t36o5djpivk312'
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realLoginData);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证机器人登录状态被更新
        $this->wechatBot->refresh();
        expect($this->wechatBot->login_at)->not->toBeNull();
    });
    
    test('processes real text message from group', function () {
        // 基于真实日志的群消息
        $realGroupMessage = [
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_e6zya8udgxyd12',
                'is_pc' => 0,
                'msg' => '🤔这个date确实有点问题，2024.08那会应该是V2.6',
                'msgid' => '8110349052485517268',
                'room_wxid' => '56878503348@chatroom',
                'timestamp' => 1757652412,
                'to_wxid' => '56878503348@chatroom',
                'wx_type' => 1
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realGroupMessage);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证消息被正确处理（实际项目中会有更多验证）
    });
    
    test('processes real picture message', function () {
        // 基于真实日志的图片消息
        $realPictureMessage = [
            'type' => 'MT_RECV_PICTURE_MSG',
            'client_id' => 5,
            'data' => [
                'from_wxid' => 'wxid_8130611305614',
                'image' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_t36o5djpivk312\\FileStorage\\Image\\2025-09\\b0657594292c07d39502faa72180eecc.dat',
                'image_thumb' => 'C:\\Users\\win11\\Documents\\WeChat Files\\wxid_t36o5djpivk312\\FileStorage\\Image\\Thumb\\2025-09\\9b35fdaf925ddb98b1bf3fa9302f5b93_t.dat',
                'is_pc' => 0,
                'msgid' => '496474829225181909',
                'raw_msg' => '<?xml version="1.0"?><msg><img aeskey="4b9dacd592a34f61fc61d892ef8ce68a" encryver="1" length="42398" md5="f7dcda8eb007fb2b8e786b19d5c0940f"></img></msg>',
                'room_wxid' => '45677731590@chatroom',
                'timestamp' => 1757655019,
                'to_wxid' => '45677731590@chatroom',
                'wx_type' => 3,
                'xor_key' => 53
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realPictureMessage);
        
        expect($response->getStatusCode())->toBe(200);
    });
    
    test('processes real other app message (reply reference)', function () {
        // 基于真实日志的引用回复消息
        $realOtherAppMessage = [
            'type' => 'MT_RECV_OTHER_APP_MSG',
            'client_id' => 5,
            'data' => [
                'from_wxid' => 'wxid_sg1lb0nztyhf12',
                'is_pc' => 0,
                'msgid' => '282007590778292508',
                'raw_msg' => '<?xml version="1.0"?><msg><appmsg appid="" sdkver="0"><title>已改</title><type>57</type><refermsg><type>1</type><content>🤔这个date确实有点问题，2024.08那会应该是V2.6</content></refermsg></appmsg></msg>',
                'room_wxid' => '56878503348@chatroom',
                'timestamp' => 1757654464,
                'to_wxid' => '56878503348@chatroom',
                'wx_sub_type' => 57,
                'wx_type' => 49
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realOtherAppMessage);
        
        expect($response->getStatusCode())->toBe(200);
    });
});

describe('Real Contact Data Processing', function () {
    
    test('processes real chatroom members data', function () {
        // 基于真实日志的群成员数据（从2021-09-13.txt日志提取）
        $realChatroomMembersData = [
            'type' => 'MT_DATA_CHATROOM_MEMBERS_MSG',
            'client_id' => 5,
            'data' => [
                'group_wxid' => '45677731590@chatroom',
                'member_list' => [
                    [
                        'nickname' => 'Deathwingsojean',
                        'wxid' => 'Deathwingsojean'
                    ],
                    [
                        'nickname' => 'LKMKJJN',
                        'wxid' => 'LKMKJJN'
                    ],
                    [
                        'nickname' => 'AI助理',
                        'wxid' => 'wxid_t36o5djpivk312'
                    ],
                    [
                        'nickname' => 'a2858520',
                        'wxid' => 'a2858520'
                    ]
                ]
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realChatroomMembersData);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证联系人数据被存储到meta中
        $this->wechatBot->refresh();
        $contacts = $this->wechatBot->getMeta('contacts', []);
        
        expect($contacts)->toHaveKey('Deathwingsojean');
        expect($contacts['Deathwingsojean']['nickname'])->toBe('Deathwingsojean');
        expect($contacts)->toHaveKey('LKMKJJN');
        expect($contacts['LKMKJJN']['nickname'])->toBe('LKMKJJN');
    });
    
    test('processes real single contact data', function () {
        $realContactData = XbotMessageDataset::singleContactMessage([
            'client_id' => 5,
            'data' => [
                'wxid' => 'wxid_newcontact123',
                'nickname' => '新联系人',
                'remark' => '备注名称',
                'avatar' => 'https://wx.qlogo.cn/mmhead/test.jpg',
                'type' => 1
            ]
        ]);
        
        $response = $this->postJson('/api/xbot/test-win-token', $realContactData);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证联系人被添加到contacts meta中
        $this->wechatBot->refresh();
        $contacts = $this->wechatBot->getMeta('contacts', []);
        expect($contacts)->toHaveKey('wxid_newcontact123');
        expect($contacts['wxid_newcontact123']['nickname'])->toBe('新联系人');
    });
});

describe('Command Processing with Real Data', function () {
    
    test('processes real builtin command', function () {
        $realHelpCommand = [
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_user123',
                'is_pc' => 0,
                'msg' => '/help',
                'msgid' => '1234567890123456789',
                'room_wxid' => '',
                'timestamp' => time(),
                'to_wxid' => 'wxid_t36o5djpivk312',
                'wx_type' => 1
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realHelpCommand);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证帮助消息被发送
        Http::assertSent(function ($request) {
            $data = $request->data();
            $msg = XbotTestHelpers::extractMessageContent($data);
            return $msg && str_contains($msg, 'AI机器人');
        });
    });
    
    test('processes real bot self config command', function () {
        $realConfigCommand = [
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => [
                'at_user_list' => [],
                'from_wxid' => 'wxid_t36o5djpivk312', // 机器人自己发送
                'is_pc' => 1,
                'msg' => '/set room_msg 1',
                'msgid' => '1234567890123456790',
                'room_wxid' => '',
                'timestamp' => time(),
                'to_wxid' => 'wxid_user123',
                'wx_type' => 1
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $realConfigCommand);
        
        expect($response->getStatusCode())->toBe(200);
        
        // 验证配置被设置
        $this->wechatBot->refresh();
        expect($this->wechatBot->getMeta('room_msg_enabled'))->toBeTrue();
    });
});

describe('Error Handling with Real Data', function () {
    
    test('handles malformed message data gracefully', function () {
        $malformedData = [
            'type' => 'MT_RECV_TEXT_MSG',
            'client_id' => 5,
            'data' => null // 异常数据
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $malformedData);
        
        expect($response->getStatusCode())->toBe(200);
    });
    
    test('handles unknown message type', function () {
        $unknownTypeData = [
            'type' => 'MT_UNKNOWN_TYPE',
            'client_id' => 5,
            'data' => [
                'some' => 'data'
            ]
        ];
        
        $response = $this->postJson('/api/xbot/test-win-token', $unknownTypeData);
        
        expect($response->getStatusCode())->toBe(200);
    });
    
    test('handles invalid token', function () {
        $validData = XbotMessageDataset::textMessage([
            'client_id' => 5,
            'data' => [
                'from_wxid' => 'wxid_user123',
                'to_wxid' => 'wxid_t36o5djpivk312',
                'msg' => 'test message'
            ]
        ]);
        
        $response = $this->postJson('/api/xbot/invalid-token', $validData);
        
        // 应该返回错误响应
        expect($response->getStatusCode())->toBe(200);
        $content = $response->getContent();
        expect($content)->toContain('找不到windows机器');
    });
});

describe('Performance with Batch Data', function () {
    
    test('handles large contact list efficiently', function () {
        // 模拟大量联系人数据
        $largeContactList = [];
        for ($i = 0; $i < 100; $i++) {
            $largeContactList[] = [
                'wxid' => "wxid_user_{$i}",
                'nickname' => "用户_{$i}",
                'remark' => "备注_{$i}",
                'avatar' => "https://example.com/avatar_{$i}.jpg",
                'type' => 1
            ];
        }
        
        $largeContactData = [
            'type' => 'MT_DATA_FRIENDS_MSG',
            'client_id' => 5,
            'data' => $largeContactList
        ];
        
        $startTime = microtime(true);
        
        $response = $this->postJson('/api/xbot/test-win-token', $largeContactData);
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        expect($response->getStatusCode())->toBe(200);
        expect($processingTime)->toBeLessThan(5.0); // 应该在5秒内完成
        
        // 验证所有联系人都被保存
        $this->wechatBot->refresh();
        $contacts = $this->wechatBot->getMeta('contacts', []);
        expect(count($contacts))->toBeGreaterThanOrEqual(100);
    });
    
    test('handles rapid message sequence', function () {
        // 模拟快速连续的消息
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = [
                'type' => 'MT_RECV_TEXT_MSG',
                'client_id' => 5,
                'data' => [
                    'from_wxid' => 'wxid_user123',
                    'to_wxid' => 'wxid_t36o5djpivk312',
                    'msg' => "消息 #{$i}",
                    'msgid' => "123456789012345678{$i}",
                    'timestamp' => time() + $i,
                    'room_wxid' => '',
                    'at_user_list' => [],
                    'is_pc' => 0,
                    'wx_type' => 1
                ]
            ];
        }
        
        foreach ($messages as $messageData) {
            $response = $this->postJson('/api/xbot/test-win-token', $messageData);
            
            expect($response->getStatusCode())->toBe(200);
        }
    });
});