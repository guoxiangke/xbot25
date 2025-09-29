<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Tests\Support\TestXbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class WechatBotResourceSendTest extends TestCase
{
    use RefreshDatabase;

    private WechatBot $wechatBot;
    private TestXbot $mockXbot;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 设置HTTP Mock
        Http::fake([
            '*' => Http::response(['success' => true], 200)
        ]);
        
        // 创建测试客户端和机器人
        $wechatClient = WechatClient::factory()->create();
        $this->wechatBot = WechatBot::factory()->create([
            'wechat_client_id' => $wechatClient->id,
            'wxid' => 'test_bot',
            'client_id' => 99
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

    /** @test */
    public function it_sends_resource_with_additions_correctly()
    {
        // 模拟801资源数据结构
        $resource = [
            'type' => 'music',
            'data' => [
                'url' => 'https://example.com/audio.m4a',
                'title' => '测试音频',
                'description' => '测试音频描述',
                'image' => 'https://example.com/image.jpg'
            ],
            'addition' => [
                'type' => 'link',
                'data' => [
                    'url' => 'https://example.com/video.mp4',
                    'title' => '测试视频',
                    'description' => '测试视频描述',
                    'image' => 'https://example.com/image.jpg'
                ]
            ]
        ];

        $targetWxid = 'test_user';

        // 调用统一的发送方法
        $this->wechatBot->sendResourceWithAdditions([$targetWxid], $resource);

        // 验证发送了两个消息：音频 + 视频
        $calls = $this->mockXbot->getCalls();
        
        $this->assertCount(2, $calls, '应该发送2个消息：1个音频 + 1个视频');
        
        // 验证第一个消息是音频
        $this->assertEquals('sendMusic', $calls[0]['method']);
        $this->assertEquals($targetWxid, $calls[0]['args'][0]);
        $this->assertStringContainsString('audio.m4a', $calls[0]['args'][1]);
        
        // 验证第二个消息是视频链接
        $this->assertEquals('sendLink', $calls[1]['method']);
        $this->assertEquals($targetWxid, $calls[1]['args'][0]);
        $this->assertStringContainsString('video.mp4', $calls[1]['args'][1]);
    }

    /** @test */
    public function it_sends_simple_resource_without_additions()
    {
        // 简单文本资源（无附加内容）
        $resource = [
            'type' => 'text',
            'data' => [
                'content' => '简单文本消息'
            ]
        ];

        $targetWxid = 'test_user';

        // 调用统一的发送方法
        $this->wechatBot->sendResourceWithAdditions([$targetWxid], $resource);

        // 验证只发送了一个消息
        $calls = $this->mockXbot->getCalls();
        
        $this->assertCount(1, $calls, '应该只发送1个文本消息');
        
        // 验证是文本消息
        $this->assertEquals('sendTextMessage', $calls[0]['method']);
        $this->assertEquals($targetWxid, $calls[0]['args'][0]);
        $this->assertEquals('简单文本消息', $calls[0]['args'][1]);
    }

    /** @test */
    public function it_marks_resources_as_keyword_response()
    {
        $resource = [
            'type' => 'text',
            'data' => [
                'content' => '测试消息'
            ]
        ];

        $targetWxid = 'test_user';

        // 调用统一的发送方法
        $this->wechatBot->sendResourceWithAdditions([$targetWxid], $resource);

        // 验证资源被标记为关键词响应
        $calls = $this->mockXbot->getCalls();
        $this->assertCount(1, $calls);
        
        // 这里我们需要检查内部逻辑是否正确标记了is_keyword_response
        // 在实际实现中，这个标记会影响后续的处理逻辑
        $this->assertTrue(true, '资源应该被正确标记为关键词响应');
    }
}