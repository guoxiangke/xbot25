<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Tests\Support\TestXbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class StatisticsUnificationTest extends TestCase
{
    use RefreshDatabase;

    private WechatBot $wechatBot;
    private TestXbot $mockXbot;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 设置重定向配置
        config(['services.xbot.redirect' => 'http://localhost/redirect?url=']);
        
        // 创建测试客户端和机器人
        $wechatClient = WechatClient::factory()->create();
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $wechatClient->id,
            'wxid' => 'test_bot',
            'client_id' => 99,
            'id' => 1
        ]);

        // 创建模拟的Xbot客户端
        $this->mockXbot = new TestXbot();
        
        // 模拟WechatBot的xbot方法返回我们的测试客户端
        $this->wechatBot = $this->getMockBuilder(WechatBot::class)
            ->onlyMethods(['xbot'])
            ->setConstructorArgs([$this->wechatBot->getAttributes()])
            ->getMock();
            
        $this->wechatBot->method('xbot')->willReturn($this->mockXbot);
        $this->wechatBot->id = 1;
    }

    public function test_statistics_stays_in_top_level_after_getResouce()
    {
        // 模拟API响应，包含顶层statistics
        $apiResponse = [
            'type' => 'music',
            'data' => [
                'url' => 'https://example.com/audio.m4a',
                'title' => '测试音频'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'audio'
            ]
        ];

        // 模拟HTTP响应
        Http::fake([
            '*' => Http::response($apiResponse, 200)
        ]);

        // 清除缓存确保重新获取
        Cache::forget('resources.801');
        
        // 调用getResouce方法
        $resource = $this->wechatBot->getResouce('801');
        
        // 验证statistics仍在顶层，没有被移动到data内
        $this->assertArrayHasKey('statistics', $resource, 'statistics应该在顶层');
        $this->assertArrayNotHasKey('statistics', $resource['data'], 'statistics不应该在data内');
        
        // 验证statistics内容正确
        $this->assertEquals('test', $resource['statistics']['metric']);
        $this->assertEquals('801', $resource['statistics']['keyword']);
        $this->assertEquals('audio', $resource['statistics']['type']);
    }

    public function test_send_method_uses_top_level_statistics()
    {
        // 直接构造包含顶层statistics的资源
        $resource = [
            'type' => 'music',
            'data' => [
                'url' => 'https://example.com/audio.m4a',
                'title' => '测试音频'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'audio'
            ]
        ];

        $targetWxid = 'test_user';

        // 调用send方法
        $this->wechatBot->send([$targetWxid], $resource);

        // 获取调用记录
        $calls = $this->mockXbot->getCalls();
        
        $this->assertCount(1, $calls, '应该发送1个音频消息');
        $this->assertEquals('sendMusic', $calls[0]['method']);
        
        // 验证URL包含重定向和统计参数
        $url = $calls[0]['args'][1]; // sendMusic的第二个参数是URL
        $this->assertStringContainsString('redirect', $url, 'URL应该包含重定向服务');
        $this->assertStringContainsString('bot=1', $url, 'URL应该包含bot参数');
        $this->assertStringContainsString('metric=test', $url, 'URL应该包含metric参数');
    }

    public function test_addition_statistics_also_processed_correctly()
    {
        // 包含addition的复杂资源结构
        $resource = [
            'type' => 'music',
            'data' => [
                'url' => 'https://example.com/audio.m4a',
                'title' => '测试音频'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'audio'
            ],
            'addition' => [
                'type' => 'link',
                'data' => [
                    'url' => 'https://r2share.example.com/video.mp4',
                    'title' => '测试视频'
                ],
                'statistics' => [
                    'metric' => 'test',
                    'keyword' => '801',
                    'type' => 'video'
                ]
            ]
        ];

        $targetWxid = 'test_user';

        // 使用统一的sendResourceWithAdditions方法
        $this->wechatBot->sendResourceWithAdditions([$targetWxid], $resource);

        // 获取调用记录
        $calls = $this->mockXbot->getCalls();
        
        $this->assertCount(2, $calls, '应该发送2个消息：音频+视频');
        
        // 验证音频URL包含重定向
        $audioUrl = $calls[0]['args'][1];
        $this->assertStringContainsString('redirect', $audioUrl, '音频URL应该包含重定向');
        $this->assertStringContainsString('type=audio', $audioUrl, '音频URL应该包含type=audio');
        
        // 验证视频URL包含重定向
        $videoUrl = $calls[1]['args'][1];
        $this->assertStringContainsString('redirect', $videoUrl, '视频URL应该包含重定向');
        $this->assertStringContainsString('type=video', $videoUrl, '视频URL应该包含type=video');
    }
}