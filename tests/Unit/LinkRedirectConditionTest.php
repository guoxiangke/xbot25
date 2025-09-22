<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Tests\Support\TestXbot;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LinkRedirectConditionTest extends TestCase
{
    use RefreshDatabase;

    private WechatBot $wechatBot;
    private TestXbot $mockXbot;

    protected function setUp(): void
    {
        parent::setUp();
        
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

    public function test_r2share_mp4_link_with_statistics_gets_redirect()
    {
        // r2share + .mp4 的链接，应该添加重定向
        $resource = [
            'type' => 'link',
            'data' => [
                'url' => 'https://r2share250422.simai.life/@test/video.mp4',
                'title' => '测试视频'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'video'
            ]
        ];

        $targetWxid = 'test_user';
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendLink', $calls[0]['method']);
        
        $url = $calls[0]['args'][1]; // 第二个参数是URL
        $this->assertStringContainsString('redirect', $url, 'r2share .mp4链接应该包含重定向');
        $this->assertStringContainsString('bot=1', $url, 'URL应该包含bot参数');
    }

    public function test_regular_link_no_redirect()
    {
        // 普通链接，不应该添加重定向
        $resource = [
            'type' => 'link',
            'data' => [
                'url' => 'https://example.com/page.html',
                'title' => '普通网页'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'link'
            ]
        ];

        $targetWxid = 'test_user';
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendLink', $calls[0]['method']);
        
        $url = $calls[0]['args'][1];
        $this->assertEquals('https://example.com/page.html', $url, '普通链接应该保持原样');
        $this->assertStringNotContainsString('redirect', $url, '普通链接不应该包含重定向');
    }

    public function test_r2share_non_mp4_link_no_redirect()
    {
        // r2share但不是.mp4的链接，不应该添加重定向
        $resource = [
            'type' => 'link',
            'data' => [
                'url' => 'https://r2share.simai.life/page.html',
                'title' => 'r2share网页'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'link'
            ]
        ];

        $targetWxid = 'test_user';
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendLink', $calls[0]['method']);
        
        $url = $calls[0]['args'][1];
        $this->assertEquals('https://r2share.simai.life/page.html', $url, 'r2share非mp4链接应该保持原样');
        $this->assertStringNotContainsString('redirect', $url, 'r2share非mp4链接不应该包含重定向');
    }

    public function test_mp4_link_non_r2share_no_redirect()
    {
        // .mp4但不是r2share的链接，不应该添加重定向
        $resource = [
            'type' => 'link',
            'data' => [
                'url' => 'https://example.com/video.mp4',
                'title' => '其他视频'
            ],
            'statistics' => [
                'metric' => 'test',
                'keyword' => '801',
                'type' => 'video'
            ]
        ];

        $targetWxid = 'test_user';
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendLink', $calls[0]['method']);
        
        $url = $calls[0]['args'][1];
        $this->assertEquals('https://example.com/video.mp4', $url, '非r2share的mp4链接应该保持原样');
        $this->assertStringNotContainsString('redirect', $url, '非r2share的mp4链接不应该包含重定向');
    }

    public function test_r2share_mp4_without_statistics_no_redirect()
    {
        // r2share + .mp4 但没有statistics的链接，不应该添加重定向
        $resource = [
            'type' => 'link',
            'data' => [
                'url' => 'https://r2share250422.simai.life/@test/video.mp4',
                'title' => '测试视频（无统计）'
            ]
            // 没有 statistics 字段
        ];

        $targetWxid = 'test_user';
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendLink', $calls[0]['method']);
        
        $url = $calls[0]['args'][1];
        $this->assertEquals('https://r2share250422.simai.life/@test/video.mp4', $url, '无statistics的链接应该保持原样');
        $this->assertStringNotContainsString('redirect', $url, '无statistics的链接不应该包含重定向');
    }

    public function test_music_still_gets_redirect_with_statistics()
    {
        // 验证music类型仍然正常添加重定向（不受link条件影响）
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
        $this->wechatBot->send([$targetWxid], $resource);

        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        $this->assertEquals('sendMusic', $calls[0]['method']);
        
        $url = $calls[0]['args'][1];
        $this->assertStringContainsString('redirect', $url, 'music类型仍应该包含重定向');
        $this->assertStringContainsString('bot=1', $url, 'music URL应该包含bot参数');
    }
}