<?php

use App\Services\Clients\XbotClient;

/**
 * HTML 清理逻辑已从 EasyWeChatKeywordHandler 迁移到 XbotClient，
 * 发送音乐、链接、朋友圈内容前都会用它清洗标题与描述
 */
function xbotCleanHtmlTags(string $text): string
{
    $client = new XbotClient('http://localhost:8001');
    $method = new ReflectionMethod($client, 'cleanHtmlTags');

    return $method->invoke($client, $text);
}

test('清理HTML标签', function () {
    expect(xbotCleanHtmlTags('<p style="text-align: left;">测试标题</p>'))->toBe('测试标题');
    expect(xbotCleanHtmlTags('<p style="text-align: center;">这是一个<strong>音乐</strong>描述</p>'))->toBe('这是一个音乐描述');

    // HTML 实体在 strip_tags 之后解码，不会被当作标签移除
    expect(xbotCleanHtmlTags('&lt;音乐&gt; &amp; &quot;标题&quot;'))->toBe('<音乐> & "标题"');

    // &nbsp; 解码为不间断空格后归一化为普通空格
    expect(xbotCleanHtmlTags('描述&nbsp;内容'))->toBe('描述 内容');
    expect(xbotCleanHtmlTags('<div>新闻<br>   描述  </div>'))->toBe('新闻 描述');
});

test('处理空值与纯标签', function () {
    expect(xbotCleanHtmlTags(''))->toBe('');
    expect(xbotCleanHtmlTags('   '))->toBe('');
    expect(xbotCleanHtmlTags('<p></p>'))->toBe('');
});

test('保留不含HTML的文本', function () {
    expect(xbotCleanHtmlTags('这是纯文本'))->toBe('这是纯文本');

    // 尖括号包裹的内容会被 strip_tags 当作标签移除
    expect(xbotCleanHtmlTags('这是<测试>文本'))->toBe('这是文本');
});

test('处理复杂HTML结构', function () {
    $html = '<div class="container"><p style="color: red;">标题</p><span>描述<strong>重要</strong>内容</span></div>';
    expect(xbotCleanHtmlTags($html))->toBe('标题描述重要内容');

    $html = '<p style="text-align: left; color: blue; font-size: 16px;">样式化文本</p>';
    expect(xbotCleanHtmlTags($html))->toBe('样式化文本');
});

test('处理特殊字符与unicode', function () {
    expect(xbotCleanHtmlTags('<p>中文 🎵 ♪ ♫ 音乐</p>'))->toBe('中文 🎵 ♪ ♫ 音乐');
    expect(xbotCleanHtmlTags('<h1>Song: "Amazing Grace" ♪ </h1>'))->toBe('Song: "Amazing Grace" ♪');
});
