# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based (Laravel 12) WeChat automation bot system called "Xbot". It provides a comprehensive API for WeChat automation including:
- User management (login, logout, friend operations)
- Message sending (text, images, files, links)
- Group management (create, invite, remove members)
- Moments (WeChat朋友圈) operations
- Payment handling (transfers)

## Architecture

The system consists of:
- **Xbot Service** (`app/Services/Xbot.php`): Core API client for WeChat automation
- **Xbot Controller** (`app/Http/Controllers/XbotController.php`): Handles incoming webhook requests from WeChat clients
- **WechatBot Model** (`app/Models/WechatBot.php`): Represents individual WeChat bot instances with Metable support
- **WechatClient Model** (`app/Models/WechatClient.php`): Represents Windows machines running WeChat clients
- **Message Pipelines** (`app/Pipelines/Xbot/`): Message processing handlers
- **Queue Jobs** (`app/Jobs/`): Background message processing

## Key Commands

### Development
```bash
# Start development server with all services
composer run dev

# Run tests
composer run test
php artisan test

# Run specific test file
php artisan test --filter=TestClassName

# Code formatting
php artisan pint
```

### Database
```bash
# Run migrations
php artisan migrate

# Run migrations with seed
php artisan migrate --seed

# Create migration
php artisan make:migration create_table_name
```

### Queue Processing
```bash
# Process queued messages
php artisan queue:listen
php artisan queue:work
```

## API Endpoints

- `POST /xbot/{winToken}` - Main webhook endpoint for WeChat client communication
- `POST /xbot/login` - License validation endpoint
- `POST /xbot/license/info` - License information endpoint

## Configuration

Key environment variables in `.env`:
```env
XBOT_LICENSE=your_license_key
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

## Data Structure

### WechatClients Table
- `token` - Windows machine identifier
- `endpoint` - Xbot API endpoint (typically :8001)
- `file_url` - File access endpoint (:8004)
- `file_path` - Windows file storage path
- `voice_url` - Voice file endpoint (:8003)
- `silk_path` - Temporary file path

### WechatBots Table  
- `wxid` - WeChat ID (unique)
- `wechat_client_id` - Foreign key to WechatClient
- `client_id` - Dynamic client identifier
- `login_at` - Last login timestamp
- `is_live_at` - Last active timestamp
- `expires_at` - License expiration

## Message Types

The system handles various WeChat message types including:
- `MT_RECV_TEXT_MSG` - Text messages
- `MT_RECV_QRCODE_MSG` - QR code messages
- `MT_USER_LOGIN` - User login events
- `MT_USER_LOGOUT` - User logout events
- `MT_DATA_OWNER_MSG` - Bot information updates
- And many more specialized message types

## Development Notes

- Uses Laravel Jetstream for authentication
- Implements Metable trait for flexible metadata storage
- File paths use Windows format (`C:\Users\...`)
- QR code management uses Laravel Cache
- Message processing uses Laravel Queues
- Timezone is set to Asia/Shanghai for WeChat operations

## Guidelines for Claude Code by Developers
- 这是一个 laravel 重构项目，旧的部分代码放在了 /Users/guo/Herd/xbot/ 目录下，不要改动其中的内容
- 请你先帮我分析一下需求，找出并描述bug后，提出修改方案，得到我的允许后再改动代码
- 设计到删除文件的，先不删除，继续执行下一步，任务结束后列出需要手动删除的文件
- 重构主要变动：
-   1. 移除的models：
       use App\Models\WechatContact;
       use App\Models\WechatContent;
       use App\Models\WechatBotContact;
       use App\Models\WechatMessage;
       use App\Models\WechatMessageFile;
       use App\Models\WechatMessageVoice;
- WechatContact 用 $wechatBot->setMeta('contacts', $contacts); 代替。 同时同步到 chatwoot上
- WechatBotContact 不再需要。
- WechatContent 暂时不提供指引
- WechatMessage WechatMessageFile WechatMessageVoice等各种消息，不再存数据库，直接通过队列处理后，以文本形式发送到 chatwoot 上
- 不再使用 $wechatBot->getMeta('xbot.config'
- 反馈时使用中文
- 请注意代码的可读性和可维护性
- 我不喜欢try catch，如非特别必要，请不要使用
- 请注意代码的依赖管理
- 请注意代码的架构设计
- 请注意代码的设计模式
- 请注意代码的服务提供者管理
- 请注意代码的依赖注入
- 请注意代码的服务容器
- 请注意代码的性能优化
- 请注意代码的可读性优化

- 不要执行 php artisan lint Bash(./vendor/bin/pint) 等
- 在clear和compact时，请更新 .claude/done/$date.md 总结完成的工作

## WechatBot 查找逻辑 (重要)

在 `XbotRequest::validateAndPrepare()` 中，提取 `$xbotWxid` 的逻辑：

1. **MT_DATA_WXID_MSG**: `data.wxid` 是目标联系人的wxid，不是bot的wxid → 强制 `$xbotWxid = null`，使用 `client_id` 查找
2. **群消息** (有 `room_wxid`): `from_wxid` 是群成员的wxid，不是bot的wxid → 强制 `$xbotWxid = null`，使用 `client_id` 查找  
3. **普通私聊消息**: `from_wxid` 就是bot的wxid → 使用 `from_wxid` 查找

在 `XbotController::getWechatBot()` 中：
- 如果 `$xbotWxid` 不为空：通过 `wxid` 查找
- 如果 `$xbotWxid` 为空：通过 `wechat_client_id` + `client_id` 查找

这样确保所有消息类型都能正确找到对应的 `WechatBot` 实例。

- done：
  1.给 BuiltinCommandHandler 加一个命令，\sync contacts
  调用的是 ：
    $this->xbot->getFriendsList();
    $this->xbot->getChatroomsList();
    $this->xbot->getPublicAccountsList();
    回应文本是：'已请求同步，请稍后确认！'
  2. 在每种消息Handler处理后，发给最后一个TextMessageHandler前，需要保留一个origin_msg_type,以后后来扩展功能时使用。
  3. MT_DATA_WXID_MSG 消息类型处理：已添加到联系人同步流程，能正确处理单个联系人信息同步

## MCP Context7 技术文档需求

为支持MCP context7集成，需要以下技术文档：

- **Laravel 12.x 官方文档** - 项目基于Laravel 12.x框架
- **Chatwoot API 文档** - 客服系统集成
- ** plank/laravel-metable 文档 ** https://github.com/plank/laravel-metable

## 总结 prepend 写入到 .claude/done/{date}.md

## Pipeline 架构详解 (重要)

### 三阶段 Pipeline 处理流程

系统使用 Laravel Pipeline 将消息处理分为三个独立的阶段，每个阶段都有明确的职责：

1. **第一阶段：状态管理 Pipeline (State)**
   - 处理系统状态相关的消息
   - 包含处理器：`ZombieCheckHandler`
   - 作用：管理机器人在线状态、系统级别的检查

2. **第二阶段：联系人管理 Pipeline (Contact)**
   - 处理联系人和关系相关的消息
   - 包含处理器：`NotificationHandler`, `FriendRequestHandler`
   - 作用：处理好友请求、群成员变化、联系人通知等

3. **第三阶段：消息内容处理 Pipeline (Message)**
   - 处理具体消息内容
   - 核心处理器链：
     ```php
     BuiltinCommandHandler::class,        // 内置命令处理
     SelfMessageHandler::class,           // 自己发送的消息
     PaymentMessageHandler::class,        // 支付消息
     SystemMessageHandler::class,         // 系统消息
     LocationMessageHandler::class,       // 位置消息
     ImageMessageHandler::class,          // 图片消息
     FileVideoMessageHandler::class,      // 文件/视频消息
     VoiceMessageHandler::class,          // 语音消息
     VoiceTransMessageHandler::class,     // 语音转文字消息
     EmojiMessageHandler::class,          // 表情消息
     LinkMessageHandler::class,           // 链接消息
     OtherAppMessageHandler::class,       // 其他应用消息
     SubscriptionHandler::class,          // 订阅处理
     CheckInMessageHandler::class,        // 签到处理
     TextMessageHandler::class,           // 文本消息处理
     KeywordResponseHandler::class,       // 关键词响应
     WebhookHandler::class,               // Webhook处理
     ChatwootHandler::class,              // Chatwoot集成
     ```

### Handler 基类架构

所有消息处理器都继承自 `BaseXbotHandler`，实现 `XbotHandlerInterface` 接口：

#### 核心方法和模式：

1. **消息发送**：
   ```php
   // ✅ 正确的消息发送方式
   $this->sendTextMessage($context, $text, $target);
   
   // ❌ 错误的假设方法（不存在）
   $context->addPendingMessage($text); // 这个方法不存在！
   ```

2. **消息处理检查**：
   ```php
   protected function shouldProcess(XbotMessageContext $context): bool
   protected function isMessageType(XbotMessageContext $context, string|array $types): bool
   ```

3. **状态管理**：
   ```php
   $context->markAsProcessed(); // 标记消息已处理，阻止后续处理器执行
   $context->isProcessed();     // 检查是否已被处理
   ```

### XbotMessageContext 上下文管理

`XbotMessageContext` 是消息在整个 Pipeline 中的状态载体：

```php
// 核心属性
$context->wechatBot      // WechatBot 实例
$context->requestRawData // 原始消息数据
$context->msgType        // 消息类型
$context->clientId       // 客户端ID
$context->isRoom         // 是否群消息
$context->roomWxid       // 群微信ID（如果是群消息）

// 状态管理
$context->isProcessed()  // 检查是否已处理
$context->markAsProcessed() // 标记为已处理

// 回复目标获取
$context->getReplyTarget() // 获取回复目标（私聊或群）
```

### 消息类型标准化

系统将各种消息类型处理后，统一转换为文本格式传递给后续处理器：

1. **类型转换流程**：各种专门的 Handler（如 `ImageMessageHandler`, `VoiceMessageHandler`）将特殊消息类型转换为标准文本
2. **origin_msg_type 保留**：在转换过程中，保留原始消息类型 `origin_msg_type`，以便后续扩展功能使用
3. **统一处理**：最终所有消息都以文本形式传递给 `TextMessageHandler` 及后续处理器

### 关键架构要点

1. **Pipeline 顺序很重要**：每个阶段必须按顺序执行，前一阶段标记为 `processed` 的消息不会进入下一阶段
2. **Handler 继承关系**：所有 Handler 必须继承 `BaseXbotHandler` 并正确使用其提供的方法
3. **状态传递**：使用 `XbotMessageContext` 在整个处理链中传递状态和数据
4. **消息发送模式**：统一使用 `$this->sendTextMessage()` 方法发送消息，不要假设存在其他发送方法

### 常见架构错误

1. **假设不存在的方法**：
   ```php
   // ❌ 错误：假设 XbotMessageContext 有 addPendingMessage 方法
   $context->addPendingMessage($text);
   
   // ✅ 正确：使用 BaseXbotHandler 提供的发送方法
   $this->sendTextMessage($context, $text);
   ```

2. **忽略 Pipeline 处理状态**：
   ```php
   // ❌ 错误：不检查处理状态，可能重复处理
   public function handle($context, $next) {
       // 直接处理...
   }
   
   // ✅ 正确：检查处理状态
   public function handle($context, $next) {
       if (!$this->shouldProcess($context)) {
           return $next($context);
       }
       // 处理逻辑...
   }
   ```

3. **联系人数据查找错误**：
   ```php
   // ❌ 错误：联系人数据是关联数组，不应该用foreach遍历查找
   foreach ($contacts as $contact) {
       if ($contact['wxid'] === $wxid) {
           return $contact['nickname'];
       }
   }
   
   // ✅ 正确：联系人数据以wxid为键，直接访问
   if (isset($contacts[$wxid])) {
       $contact = $contacts[$wxid];
       return $contact['nickname'] ?? $contact['remark'] ?? $wxid;
   }
   ```
   
   **重要提醒**：`$wechatBot->getMeta('contacts')` 返回的数据结构是：
   ```php
   $contacts = [
       'wxid1' => ['nickname' => '昵称', 'remark' => '备注', ...],
       'wxid2' => ['nickname' => '昵称2', 'remark' => '备注2', ...],
   ];
   ```

## 消息同步架构（2025-09-07 更新）

### Chatwoot 同步策略

系统中所有消息的同步遵循以下简洁规则：

1. **用户消息**：始终同步到 Chatwoot
2. **机器人响应**：始终同步到 Chatwoot  
3. **关键词响应**：根据 `keyword_sync` 配置决定是否同步

### Handler 消息传递策略

**重要架构原则：所有 Handler 处理完消息后都应该继续传递给下游，而不是直接 `markAsProcessed()`**

#### ✅ 正确的 Handler 模式：
```php
public function handle(XbotMessageContext $context, Closure $next) {
    if (!$this->shouldProcess($context)) {
        return $next($context);
    }
    
    // 处理业务逻辑
    $this->processMessage($context);
    
    // 继续传递到下游处理器（重要！）
    return $next($context);
}
```

#### ❌ 错误的 Handler 模式：
```php
public function handle(XbotMessageContext $context, Closure $next) {
    // 处理业务逻辑
    $this->processMessage($context);
    
    // ❌ 错误：直接标记为已处理，阻止同步到 Chatwoot
    $context->markAsProcessed(static::class);
    return $context;
}
```

### 配置管理统一化

所有配置项通过 `XbotConfigManager` 统一管理：

#### 支持的配置项：
```php
'chatwoot' => 'Chatwoot同步',
'room_msg' => '群消息处理',
'keyword_resources' => '关键词资源响应',
'keyword_sync' => 'Chatwoot同步关键词',
'payment_auto' => '自动收款',
'check_in' => '签到系统',
```

#### 配置命令支持（SelfMessageHandler）：
- `/set <key> <value>` - 设置配置项
- `/config <key> <value>` - 设置配置项（与 `/set` 等效）
- `/config` - 查看所有配置状态（BuiltinCommandHandler 处理）

#### 重要：动态配置列表
SelfMessageHandler 使用 `XbotConfigManager::getAvailableCommands()` 动态获取允许的配置项，确保与配置定义保持同步。

### 系统命令架构分离

#### BuiltinCommandHandler（查询命令）：
- `/help` - 显示帮助信息
- `/whoami` - 显示登录信息  
- `/config` - 查看配置状态
- `/sync contacts` - 同步联系人
- `/list subscriptions` - 查看订阅
- `/get room_id` - 获取群ID

#### SelfMessageHandler（配置命令）：
- `/set <key> <value>` - 设置配置项
- `/config <key> <value>` - 设置配置项（等效格式）

### 已移除的复杂性

1. **force_chatwoot_sync 标记**：不再需要特殊标记，所有消息按统一规则同步
2. **复杂的同步判断逻辑**：ChatwootHandler 简化为直接同步所有传递过来的消息
3. **硬编码的配置项列表**：改为动态从 XbotConfigManager 获取

## 重构差异
- 不再需要的功能
  - 自动回复系统，因为chatwoot 系统中可以设置自动回复功能
  - WechatContent 模型支持多种消息类型（文本、图片、链接、音乐等15种） 
  - SilkConvertQueue 队列处理语音文件转换
  - WechatMessageVoice 存储语音转文字结果
  - 语音文件 silk → mp3 转换
  - WechatMessageFile 存储文件路径和URL映射
  - force_chatwoot_sync 强制同步标记（已移除复杂性）



## 逻辑是：

  1. room_msg (群消息处理) 和 room_listen (群级别配置) 的关系：
    - 如果全局 room_msg 关闭，但某个群的 room_listen 开启 → 该群要处理消息
    - 如果全局 room_msg 开启，但某个群的 room_listen 关闭 → 该群不处理消息
  2. check_in (全局签到) 和 check_in_room (群级别签到) 的关系也类似：
    - 如果全局 check_in 关闭，但某个群的 check_in_room 开启 → 该群可以签到
    - 如果全局 check_in 开启，但某个群的 check_in_room 关闭 → 该群不能签到

  这个逻辑实际上是一个"例外列表"的概念：
  - 全局配置作为默认值
  - 群级别配置作为例外/覆盖

