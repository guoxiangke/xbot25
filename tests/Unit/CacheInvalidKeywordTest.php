<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\WechatBot;
use App\Models\WechatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheInvalidKeywordTest extends TestCase
{
    use RefreshDatabase;

    private WechatBot $wechatBot;

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
    }

    protected function tearDown(): void
    {
        // 清理测试缓存
        Cache::flush();
        parent::tearDown();
    }

    public function test_valid_keyword_gets_cached()
    {
        // 模拟有效的资源响应
        Http::fake([
            '*' => Http::response([
                'type' => 'text',
                'data' => ['content' => '测试资源']
            ], 200)
        ]);

        $resource = $this->wechatBot->getResouce('801');
        
        $this->assertNotFalse($resource, '有效关键词应该返回资源');
        $this->assertEquals('text', $resource['type']);
        
        // 验证缓存被创建
        $cacheExists = Cache::has('resources.801');
        $this->assertTrue($cacheExists, '有效资源应该被缓存');
    }

    public function test_invalid_keyword_not_cached()
    {
        // 模拟无效的资源响应（404或空响应）
        Http::fake([
            '*' => Http::response(null, 404)
        ]);

        $chineseKeyword = '你好世界';
        $resource = $this->wechatBot->getResouce($chineseKeyword);
        
        $this->assertFalse($resource, '无效关键词应该返回false');
        
        // 验证缓存未被创建
        $cacheExists = Cache::has("resources.{$chineseKeyword}");
        $this->assertFalse($cacheExists, '无效资源不应该被缓存');
    }

    public function test_empty_response_not_cached()
    {
        // 模拟空的JSON响应
        Http::fake([
            '*' => Http::response([], 200)
        ]);

        $keyword = 'nonexistent';
        $resource = $this->wechatBot->getResouce($keyword);
        
        $this->assertFalse($resource, '空响应应该返回false');
        
        // 验证缓存未被创建
        $cacheExists = Cache::has("resources.{$keyword}");
        $this->assertFalse($cacheExists, '空响应不应该被缓存');
    }

    public function test_http_error_not_cached()
    {
        // 模拟HTTP错误
        Http::fake([
            '*' => Http::response('Server Error', 500)
        ]);

        $keyword = 'server_error';
        $resource = $this->wechatBot->getResouce($keyword);
        
        $this->assertFalse($resource, 'HTTP错误应该返回false');
        
        // 验证缓存未被创建
        $cacheExists = Cache::has("resources.{$keyword}");
        $this->assertFalse($cacheExists, 'HTTP错误不应该被缓存');
    }

    public function test_multiple_invalid_requests_dont_accumulate_cache()
    {
        // 模拟多个无效请求
        Http::fake([
            '*' => Http::response(null, 404)
        ]);

        $invalidKeywords = [
            '你好世界',
            '@所有人 重要通知',
            '[OK][玫瑰][玫瑰]',
            'will～归山 加入了群聊',
            '这是一个很长的用户消息不应该被当作关键词处理'
        ];

        foreach ($invalidKeywords as $keyword) {
            $resource = $this->wechatBot->getResouce($keyword);
            $this->assertFalse($resource, "无效关键词 '{$keyword}' 应该返回false");
        }

        // 验证没有无效缓存被创建
        $cacheCount = DB::table('cache')
            ->where('key', 'like', 'laravel-cache-resources.%')
            ->count();
            
        $this->assertEquals(0, $cacheCount, '不应该创建任何无效缓存');
    }

    public function test_invalid_response_does_not_call_cache_put()
    {
        // 使用Cache spy来验证put不被调用用于无效响应
        Cache::spy();
        
        Http::fake([
            '*' => Http::response(null, 404)
        ]);

        $this->wechatBot->getResouce('invalid_keyword');

        // 验证Cache::put没有被调用（不缓存无效结果）
        Cache::shouldNotHaveReceived('put');
    }
}