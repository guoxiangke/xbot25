<?php

use App\Http\Controllers\EasyWeChatKeywordHandler;
use Tests\TestCase;
use ReflectionClass;

class EasyWeChatKeywordHandlerTest extends TestCase
{
    protected EasyWeChatKeywordHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EasyWeChatKeywordHandler();
    }

    /** @test */
    public function it_cleans_html_tags_from_text()
    {
        // 使用反射来测试私有方法
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试基本HTML标签清理
        $result = $method->invoke($this->handler, '<p style="text-align: left;">测试标题</p>');
        expect($result)->toBe('测试标题');

        // 测试复杂HTML结构
        $result = $method->invoke($this->handler, '<p style="text-align: center;">这是一个<strong>音乐</strong>描述</p>');
        expect($result)->toBe('这是一个音乐描述');

        // 测试HTML实体
        $result = $method->invoke($this->handler, '&lt;音乐&gt; &amp; &quot;标题&quot;');
        expect($result)->toBe('<音乐> & "标题"');

        // 测试空白符处理（&nbsp;被解码为不间断空格，然后被正则表达式归一化为单个空格）
        $result = $method->invoke($this->handler, '描述&nbsp;内容');
        expect($result)->toBe('描述 内容');

        // 测试换行和多空格
        $result = $method->invoke($this->handler, '<div>新闻<br>   描述  </div>');
        expect($result)->toBe('新闻 描述');
    }

    /** @test */
    public function it_handles_empty_and_null_values()
    {
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试空字符串
        $result = $method->invoke($this->handler, '');
        expect($result)->toBe('');

        // 测试只有空格的字符串
        $result = $method->invoke($this->handler, '   ');
        expect($result)->toBe('');

        // 测试只有HTML标签的字符串
        $result = $method->invoke($this->handler, '<p></p>');
        expect($result)->toBe('');
    }

    /** @test */
    public function it_preserves_text_without_html_tags()
    {
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试纯文本
        $result = $method->invoke($this->handler, '这是纯文本');
        expect($result)->toBe('这是纯文本');

        // 测试包含特殊字符但不是HTML的文本
        $result = $method->invoke($this->handler, '这是<测试>文本');
        expect($result)->toBe('这是文本');
    }

    /** @test */
    public function it_handles_complex_html_structures()
    {
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试嵌套HTML标签
        $html = '<div class="container"><p style="color: red;">标题</p><span>描述<strong>重要</strong>内容</span></div>';
        $result = $method->invoke($this->handler, $html);
        expect($result)->toBe('标题描述重要内容');

        // 测试包含属性的HTML标签
        $html = '<p style="text-align: left; color: blue; font-size: 16px;">样式化文本</p>';
        $result = $method->invoke($this->handler, $html);
        expect($result)->toBe('样式化文本');
    }

    /** @test */
    public function it_handles_special_characters_and_unicode()
    {
        $reflection = new ReflectionClass($this->handler);
        $method = $reflection->getMethod('cleanHtmlTags');
        $method->setAccessible(true);

        // 测试Unicode字符
        $result = $method->invoke($this->handler, '<p>中文 🎵 ♪ ♫ 音乐</p>');
        expect($result)->toBe('中文 🎵 ♪ ♫ 音乐');

        // 测试混合内容
        $result = $method->invoke($this->handler, '<h1>Song: "Amazing Grace" ♪ </h1>');
        expect($result)->toBe('Song: "Amazing Grace" ♪');
    }
}