<?php

use App\Http\Controllers\WechatController;
use Tests\TestCase;
use ReflectionClass;

class WechatControllerTest extends TestCase
{
    protected WechatController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new WechatController();
    }

    /** @test */
    public function it_cleans_html_tags_from_music_content()
    {
        // 使用反射来测试私有方法
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试用户提到的具体例子
        $result = $method->invoke($this->controller, '<p style="text-align: left;">以斯帖记（3）以斯帖被立为王后（斯2:1-18）（讲员：张得仁）');
        expect($result)->toBe('以斯帖记（3）以斯帖被立为王后（斯2:1-18）（讲员：张得仁）');

        // 测试复杂HTML结构
        $result = $method->invoke($this->controller, '<p style="text-align: center;">这是一个<strong>音乐</strong>描述</p>');
        expect($result)->toBe('这是一个音乐描述');

        // 测试空字符串
        $result = $method->invoke($this->controller, '');
        expect($result)->toBe('');

        // 测试纯文本（无HTML）
        $result = $method->invoke($this->controller, '这是纯文本');
        expect($result)->toBe('这是纯文本');
    }
}