# 微信客服消息服务使用说明

## 功能概述

`CustomerServiceMessage` 类提供了完整的微信客服消息发送功能，支持发送多条消息，符合微信官方文档的所有消息类型格式。
https://developers.weixin.qq.com/doc/service/api/customer/message/api_sendcustommessage.html
https://easywechat.com/6.x/official-account/message.html

## 支持的消息类型

### 1. 文本消息
```php
$customerService->sendText($openid, '这是一条文本消息');
```

### 2. 图片消息
```php
$customerService->sendImage($openid, 'MEDIA_ID');
```

### 3. 语音消息
```php
$customerService->sendVoice($openid, 'MEDIA_ID');
```

### 4. 视频消息
```php
$customerService->sendVideo(
    $openid, 
    'VIDEO_MEDIA_ID', 
    'THUMB_MEDIA_ID', 
    '视频标题', 
    '视频描述'
);
```

### 5. 音乐消息
```php
$customerService->sendMusic(
    $openid,
    '音乐标题',
    '音乐描述', 
    'http://music.url',
    'http://hq.music.url',
    'THUMB_MEDIA_ID'
);
```

### 6. 图文消息
```php
// 单条图文
$customerService->sendSingleNews(
    $openid,
    '标题',
    '描述',
    'http://link.url',
    'http://pic.url'
);

// 多条图文
$articles = [
    [
        'title' => '标题1',
        'description' => '描述1',
        'url' => 'http://link1.url',
        'picurl' => 'http://pic1.url',
    ],
    [
        'title' => '标题2', 
        'description' => '描述2',
        'url' => 'http://link2.url',
        'picurl' => 'http://pic2.url',
    ],
];
$customerService->sendNews($openid, $articles);
```

### 7. 菜单消息
```php
$menuList = [
    ['id' => '101', 'content' => '满意'],
    ['id' => '102', 'content' => '不满意'],
];
$customerService->sendMenu(
    $openid, 
    '您对本次服务是否满意呢?', 
    $menuList, 
    '欢迎再次光临'
);
```

### 8. 卡券消息
```php
$customerService->sendCard($openid, 'CARD_ID');
```

## 发送多条消息

### 方法1：使用 sendMultiple
```php
$messages = [
    [
        'type' => 'text',
        'content' => '欢迎！',
    ],
    [
        'type' => 'image',
        'media_id' => 'MEDIA_ID',
    ],
    [
        'type' => 'news',
        'title' => '图文标题',
        'description' => '图文描述',
        'url' => 'http://link.url',
        'pic_url' => 'http://pic.url',
    ],
];

$results = $customerService->sendMultiple($openid, $messages);
```

### 方法2：使用 sendByType
```php
// 发送单条消息
$messageData = [
    'type' => 'text',
    'content' => '这是文本消息',
];
$result = $customerService->sendByType($openid, $messageData);
```

## 在 KeywordHandler 中的应用

### 特殊关键词触发多条消息
用户发送 `多条消息` 或 `multi` 时，会自动发送5条不同类型的消息作为演示。

### 扩展资源支持多条消息
如果您的资源API返回包含 `multiple_messages` 字段的数据，系统会自动使用客服消息发送多条内容：

```json
{
  "type": "multiple",
  "multiple_messages": [
    {
      "type": "text",
      "content": "第一条消息"
    },
    {
      "type": "text", 
      "content": "第二条消息"
    }
  ]
}
```

## 错误处理

所有方法都会返回包含成功状态的数组：
```php
[
    'success' => true|false,
    'result' => [...],  // 成功时的微信API响应
    'error' => '...',   // 失败时的错误信息
    'errcode' => 0      // 微信错误代码
]
```

## 注意事项

1. **频率限制**：发送多条消息时自动添加100ms延迟避免频率限制
2. **MediaID**：图片、语音、视频等需要先上传到微信服务器获取MediaID
3. **OPENID**：只能向关注了公众号的用户发送客服消息
4. **日志记录**：所有发送操作都会记录详细日志便于调试

## 获取服务实例

```php
// 在控制器中
$controller = new EasyWeChatController();
$customerService = $controller->getCustomerService();

// 或者直接创建
$app = new EasyWeChat\OfficialAccount\Application($config);
$customerService = new CustomerServiceMessage($app);
```