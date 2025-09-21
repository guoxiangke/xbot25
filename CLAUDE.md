# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based (Laravel 12) WeChat automation bot system called "Xbot". It provides a comprehensive API for WeChat automation including:
- User management (login, logout, friend operations)
- Message sending (text, images, files, links)
- Group management (create, invite, remove members)
- Moments (WeChat朋友圈) operations
- Payment handling (transfers)
- **防封号好友请求处理** - 智能延迟队列，支持1000+好友安全处理
- **好友来源识别** - 9种来源类型自动分类和追踪
- **群级别配置管理** - 灵活的群个性化配置覆盖系统
- **Chatwoot集成** - 完整的客服系统同步和管理

## Architecture

The system consists of:

### Core Architecture (2025 Restructured)
- **XbotClient** (`app/Services/Clients/XbotClient.php`): Core API client for WeChat automation
- **Xbot Controller** (`app/Http/Controllers/XbotController.php`): Main webhook controller with integrated message dispatch logic
- **Chatwoot Controller** (`app/Http/Controllers/ChatwootController.php`): Chatwoot webhook handler
- **Request Validation** (`app/Http/Requests/XbotWebhookRequest.php`): Integrated request validation and data preparation
- **Configuration Manager** (`app/Services/Managers/ConfigManager.php`): 统一配置管理系统

### Data Models
- **WechatBot Model** (`app/Models/WechatBot.php`): Represents individual WeChat bot instances with Metable support
- **WechatClient Model** (`app/Models/WechatClient.php`): Represents Windows machines running WeChat clients

### Processing Components
- **Message Pipelines** (`app/Pipelines/Xbot/`): Message processing handlers
- **Queue Jobs** (`app/Jobs/`): Background message processing
- **Analytics** (`app/Services/Analytics/FriendSourceAnalyzer.php`): 好友来源智能识别
- **Chatwoot Client** (`app/Services/Clients/ChatwootClient.php`): 客服系统集成服务

### Supporting Services
- **Permission Guards** (`app/Services/Guards/`): Access control and permission checking
- **State Handlers** (`app/Services/StateHandlers/`): Bot state management
- **Message Filters** (`app/Services/ChatroomMessageFilter.php`, `CheckInPermissionService.php`): Room-level configuration filters

## Key Commands

### Development
```bash
# Start development server with all services (server + queue + logs + vite)
composer run dev

# Start development server manually
php artisan serve

# Queue processing (run in separate terminal)
php artisan queue:listen --tries=1

# Real-time logs (run in separate terminal)  
php artisan pail --timeout=0

# Run tests
composer run test
# or
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

系统使用双队列架构，分离联系人同步和消息处理：

```bash
# 处理默认队列（消息处理、其他任务）
php artisan queue:work --queue=default

# 处理联系人同步队列（独立运行，避免阻塞消息处理）
php artisan queue:work --queue=contacts

# 同时处理两个队列（推荐用于开发环境）
php artisan queue:work --queue=default,contacts

# 监听所有队列
php artisan queue:listen
```

**生产环境建议**：
- 分别启动两个 worker 进程处理不同队列
- contacts 队列可以使用较少的 worker 数量
- default 队列使用更多 worker 确保消息及时处理

## API Endpoints

### Core Endpoints
- `POST /xbot/{winToken}` - Main webhook endpoint for WeChat client communication (handled by `XbotController`)
- `POST /xbot/login` - License validation endpoint  
- `POST /xbot/heartbeat` - License heartbeat endpoint
- `POST /xbot/license/info` - License information endpoint

### WeChat API Endpoints (Authenticated)
- `POST /wechat/send` - Send messages (text/at/link/image/video) to friends/groups
- `POST /wechat/add` - Add friends or group members
- `GET /wechat/friends` - Get friends list

### Chatwoot Integration
- `POST /chatwoot/{wechatBot}` - Chatwoot webhook endpoint for bidirectional messaging

## Configuration

Key environment variables in `.env`:
```env
# Xbot License（必需）
XBOT_LICENSE=your_license_key_here

# Chatwoot集成配置（已改为通过配置命令设置）
# 使用 /set chatwoot_endpoint_url https://your-chatwoot-instance.com 命令设置

# 微信支付识别文本
WECHAT_PAY_TXT="#付款:AI天空蔚蓝(bluesky_still)/支持我们/001"

# 资源端点
XBOT_RESOURCE_ENDPOINT="https://x-resources.vercel.app/resources/"

# 推送通知
BARK_NOTIFY=https://api.day.app/your-bark-token/

# Octane服务器
OCTANE_SERVER=frankenphp
```

## Data Structure

### WechatClients Table
- `id` - Primary key
- `token` - Windows machine identifier (winToken)
- `endpoint` - Windows机器 xbot api 接口地址:8001
- `file_url` - Windows机器暴露的Wechat Files文件夹:8004
- `file_path` - Windows file storage path (nullable)
- `created_at` / `updated_at` - Timestamps

### WechatBots Table  
- `id` - Primary key
- `wechat_client_id` - Foreign key to WechatClient
- `user_id` - 绑定的管理员user_id (nullable, unique)
- `wxid` - WeChat ID (unique, indexed)
- `name` - Bot名字remark描述 (nullable)
- `client_id` - 动态变换的客户端ID (unsigned integer, nullable)
- `login_at` - Last login timestamp (nullable, 下线时为null)
- `is_live_at` - Last active timestamp (用于检测崩溃离线)
- `expires_at` - License expiration (默认1个月内有效)
- `created_at` / `updated_at` - Timestamps

## Message Types

The system handles various WeChat message types including:
- `MT_RECV_TEXT_MSG` - Text messages
- `MT_RECV_QRCODE_MSG` - QR code messages
- `MT_USER_LOGIN` - User login events
- `MT_USER_LOGOUT` - User logout events
- `MT_DATA_OWNER_MSG` - Bot information updates
- `MT_RECV_FRIEND_MSG` - Friend request messages (with source analysis)
- `MT_CONTACT_ADD_NOITFY_MSG` - Friend addition notifications
- `MT_CONTACT_DEL_NOTIFY_MSG` - Friend deletion notifications
- `MT_SEARCH_CONTACT_MSG` - Contact search messages
- And many more specialized message types

## Development Notes

- **Framework**: Laravel 12.x with Jetstream for authentication
- **Database**: MySQL primary, SQLite fallback support
- **Metadata Storage**: Uses plank/laravel-metable for flexible WechatBot metadata
- **Queue System**: Database-based queue processing with dual-queue architecture
- **Cache**: Database-based cache storage
- **File Handling**: Windows format paths (`C:\Users\...`)
- **Server**: FrankenPHP with Laravel Octane for high performance
- **Real-time Logs**: Laravel Pail for development monitoring
- **Testing**: PHPUnit with Pest framework
- **Asset Building**: Vite for frontend assets
- **Message Processing**: Three-stage Pipeline architecture
- **Timezone**: Asia/Shanghai for WeChat operations compatibility

## Guidelines for Claude Code by Developers
- 这是一个 laravel 重构项目，旧的部分代码放在了 /Users/guo/Herd/xbot/ 目录下，不要改动其中的内容
- 请你先帮我分析一下需求，找出并描述bug后，提出修改方案，得到我的允许后再改动代码
- 设计到删除文件的，先不删除，继续执行下一步，任务结束后列出需要手动删除的文件

### 重构经验教训 (2025-09-14)

**重构时的关键原则 - 事实驱动 vs 假设驱动**：

#### ❌ 危险的假设驱动开发模式：
1. **数据结构假设错误**
   - 假设API响应数据在 `$data['data']` 中，实际直接是 `$data` 本身
   - 创造不存在的字段名如 `$data['friends']`, `$data['room_list']`
   - 基于"常见API模式"假设，而不是查看真实数据格式

2. **接口契约理解错误**
   - 假设队列Job接收数组，实际接收单个对象
   - 传递 `dispatch($job, $array)` 而不是 `foreach` 逐个分发
   - 没有检查构造函数参数要求

3. **端到端理解缺失**
   - 没有跟踪完整数据流：`API响应 → 处理器 → 队列Job → 目标服务`
   - 各组件间的接口约定理解错误

#### ✅ 正确的重构方法：
1. **先调试，后编码**
   - 添加调试日志查看真实数据结构 
   - 验证假设，不要基于经验猜测
   - 示例：`Log::info('真实数据结构', ['keys' => array_keys($data), 'sample' => array_slice($data, 0, 3)])`

2. **接口兼容性验证**
   - 重构前检查所有调用方的参数传递方式
   - 检查构造函数、方法签名的实际要求
   - 确保新实现与原有调用方兼容

3. **端到端测试思维** 
   - 跟踪数据从源头到终点的完整流程
   - 验证每个环节的数据格式转换正确性
   - 确保重构后整个调用链正常工作

#### 具体避免的错误模式：
- ❌ `$requestRawData['data']` (假设的字段) → ✅ `$requestRawData` (实际数据结构)
- ❌ `dispatch($wechatBot, $friendsArray, 'label')` → ✅ `foreach($friends as $friend) { dispatch($wechatBot, $friend, 'label') }`
- ❌ 基于经验创造字段名 → ✅ 先调试查看真实字段名

这些教训避免了：联系人同步显示0个、队列Job参数错误、类型声明冲突等系统性问题。
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

## 消息处理流程 (重要)

### 群消息过滤机制

系统在两个地方进行群消息过滤：

1. **XbotController::processMessage()** - 主要过滤逻辑（第178-207行）
2. **XbotController::routeMessage()** - Pipeline前的二次检查（第270-296行）

#### 群消息过滤逻辑：
```php
// 1. 基础过滤：检查全局room_msg配置和群级别room_msg配置
$basicFilterPassed = $filter->shouldProcess($roomWxid, $messageContent);

// 2. 特殊消息放行检查（即使基础过滤不通过）
if (!$basicFilterPassed) {
    // 群级别配置命令始终放行
    $isGroupConfigCommand = $this->isGroupConfigCommand($messageContent);
    
    // 签到消息在该群开启签到时放行  
    $isCheckInMessage = $this->isCheckInMessage($messageContent);
    $canCheckIn = $checkInService->canCheckIn($roomWxid);
    
    // 放行条件：是群配置命令 或 (是签到消息且该群可以签到)
    if ($isGroupConfigCommand || ($isCheckInMessage && $canCheckIn)) {
        // 允许处理
    } else {
        return null; // 忽略消息
    }
}
```

### 联系人同步处理

联系人同步消息类型在 `processMessage()` 中专门处理（第172-176行）：
```php
$contactSyncTypes = [
    'MT_DATA_FRIENDS_MSG',        // 好友列表
    'MT_DATA_CHATROOMS_MSG',      // 群聊列表
    'MT_DATA_PUBLICS_MSG',        // 公众号列表
    'MT_ROOM_CREATE_NOTIFY_MSG',  // 群创建通知
    'MT_DATA_CHATROOM_MEMBERS_MSG', // 群成员列表
    'MT_DATA_WXID_MSG',           // 单个联系人信息
];
```

这些消息直接调用 `ContactSyncProcessor` 处理，不进入Pipeline流程。

### 假掉线恢复机制

系统在 `XbotController::processMessage()` 中实现了假掉线恢复机制（第409-446行）：

```php
// 如果找不到WechatBot，尝试从假掉线状态恢复
if(!$wechatBot){
    $recoveredBot = $this->recoverFromFakeDisconnection($wechatClient, $clientId, $requestRawData, $msgType);
    if ($recoveredBot) {
        $wechatBot = $recoveredBot;
        // 更新关键字段：client_id, login_at, is_live_at
    }
}
```

恢复逻辑会从消息数据中提取wxid，然后更新WechatBot的连接信息。

### 消息验证机制

系统在处理消息前进行多重验证（第99-170行）：

1. **时间戳验证** - 忽略超过1小时的消息
2. **必要字段验证** - 检查`from_wxid`和`to_wxid`（联系人同步消息除外）
3. **消息ID验证** - 大部分消息类型必须有`msgid`
4. **特殊消息类型处理** - `MT_TRANS_VOICE_MSG`需要同时有`msgid`和`text`

#### 无需msgid的消息类型：
```php
$messagesWithoutMsgid = [
    // 联系人同步消息
    'MT_DATA_FRIENDS_MSG', 'MT_DATA_CHATROOMS_MSG', 'MT_DATA_PUBLICS_MSG',
    'MT_DATA_CHATROOM_MEMBERS_MSG', 'MT_ROOM_CREATE_NOTIFY_MSG', 'MT_DATA_WXID_MSG',
    // 通知消息
    'MT_ROOM_ADD_MEMBER_NOTIFY_MSG', 'MT_ROOM_DEL_MEMBER_NOTIFY_MSG',
    'MT_CONTACT_ADD_NOITFY_MSG', 'MT_CONTACT_DEL_NOTIFY_MSG',
    'MT_ROOM_MEMBER_DISPLAY_UPDATE_NOTIFY_MSG',
    // 特殊操作消息
    'MT_ZOMBIE_CHECK_MSG', 'MT_SEARCH_CONTACT_MSG',
];
```

## MCP Context7 技术文档需求

为支持MCP context7集成，需要以下技术文档：

- **Laravel 12.x 官方文档** - 项目基于Laravel 12.x框架
- **Chatwoot API 文档** - 客服系统集成
- ** plank/laravel-metable 文档 ** https://github.com/plank/laravel-metable

## 开发指导原则

### 代码规范
- 优先编辑现有文件而不是创建新文件
- 不使用emoji，除非用户明确要求
- 避免不必要的try-catch，除非特别需要
- 注重代码可读性、可维护性和架构设计
- 使用依赖注入和服务容器模式

### 工作流程
- 先分析需求，描述问题，提出修改方案，得到用户确认后再改动代码
- 涉及删除文件时，先继续执行任务，最后列出需要手动删除的文件
- 完成任务后更新 `.claude/done/{date}.md` 总结完成的工作

## Pipeline 架构详解 (重要)

### 三阶段 Pipeline 处理流程

系统使用 Laravel Pipeline 将消息处理分为三个独立的阶段，每个阶段都有明确的职责：

1. **第一阶段：状态管理 Pipeline (State)**
   - 处理系统状态相关的消息
   - 包含处理器：`ZombieCheckHandler`
   - 作用：管理机器人在线状态、系统级别的检查

2. **第二阶段：联系人管理 Pipeline (Contact)**
   - 处理联系人和关系相关的消息
   - 包含处理器：`NotificationHandler`, `FriendRequestHandler`, `SearchContactHandler`
   - 作用：处理好友请求、群成员变化、联系人通知、联系人搜索等

3. **第三阶段：消息内容处理 Pipeline (Message)**
   - 处理具体消息内容
   - 核心处理器链（按实际代码顺序）：
     ```php
     BuiltinCommandHandler::class,        // 内置命令处理
     SelfMessageHandler::class,           // 自己发送的消息（配置命令）
     PaymentMessageHandler::class,        // 支付消息
     
     // 消息类型转换处理器（将各种格式转为文本）
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
     ChatwootHandler::class,              // Chatwoot集成（最后同步）
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

**全局布尔配置项（XbotConfigManager::CONFIGS）：**
```php
'chatwoot' => 'Chatwoot同步',
'room_msg' => '群消息处理', 
'keyword_resources' => '关键词资源响应',
'payment_auto' => '自动收款',
'check_in' => '签到系统',
'friend_auto_accept' => '自动同意好友请求',
'friend_welcome' => '新好友欢迎消息',
```

**Chatwoot专用配置项（XbotConfigManager::CHATWOOT_CONFIGS）：**
```php
'chatwoot_account_id' => 'Chatwoot账户ID',
'chatwoot_inbox_id' => 'Chatwoot收件箱ID', 
'chatwoot_token' => 'ChatwootAPI令牌',
```

**好友请求配置项（XbotConfigManager::FRIEND_CONFIGS）：**
```php
'friend_daily_limit' => '每日好友请求处理上限',
'welcome_msg' => '好友欢迎消息模板',
'room_welcome_msg' => '群聊欢迎消息模板',
```

**群级别配置项（SelfMessageHandler::GROUP_LEVEL_CONFIGS）：**
```php
'room_msg' => '群消息处理',
'check_in' => '群签到系统',
'youtube_room' => 'YouTube链接响应',
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
- `/get subscriptions` - 查看订阅
- `/get room_id` - 获取群ID

#### SelfMessageHandler（配置命令）：
- `/set <key> <value>` - 设置配置项
- `/config <key> <value>` - 设置配置项（等效格式）

### 已移除的复杂性

1. **force_chatwoot_sync 标记**：不再需要特殊标记，所有消息按统一规则同步
2. **复杂的同步判断逻辑**：ChatwootHandler 简化为直接同步所有传递过来的消息
3. **硬编码的配置项列表**：改为动态从 XbotConfigManager 获取

## 重构差异 (从旧版本迁移)

### 已移除的模型和功能
- `WechatContact` → 改用 `$wechatBot->setMeta('contacts', $contacts)` + Chatwoot同步
- `WechatBotContact` → 不再需要，联系人关系通过metadata管理
- `WechatContent` → 消息内容不再存储，直接转换为文本同步到Chatwoot
- `WechatMessage` / `WechatMessageFile` / `WechatMessageVoice` → 消息不再持久化存储
- `SilkConvertQueue` → 语音文件转换队列已移除
- 语音文件 silk → mp3 转换功能
- `force_chatwoot_sync` 强制同步标记

### 已简化的系统
- **自动回复系统** → Chatwoot内置自动回复功能替代
- **复杂消息存储** → 统一转换为文本格式处理
- **多重配置系统** → 统一的XbotConfigManager管理
- **复杂同步逻辑** → 简化的Pipeline直接同步模式

### 新增的架构特性
- **三阶段Pipeline处理** → State、Contact、Message分离
- **双队列架构** → default和contacts队列分离
- **群级别配置覆盖** → 灵活的群个性化设置
- **防封号好友请求处理** → 智能延迟和限制机制
- **好友来源识别** → 9种来源类型自动分类
- **假掉线恢复** → 自动检测和恢复机制



## 逻辑是：

  1. room_msg (群消息处理) 全局配置和群级别配置的关系：
    - 如果全局 room_msg 关闭，但某个群的 room_msg 开启 → 该群要处理消息
    - 如果全局 room_msg 开启，但某个群的 room_msg 关闭 → 该群不处理消息
  2. check_in (全局签到) 和群级别签到的关系也类似：
    - 如果全局 check_in 关闭，但某个群的 check_in 开启 → 该群可以签到
    - 如果全局 check_in 开启，但某个群的 check_in 关闭 → 该群不能签到

  这个逻辑实际上是一个"例外列表"的概念：
  - 全局配置作为默认值
  - 群级别配置作为例外/覆盖

## 防封号好友请求处理系统 (2025-09-12 完成)

### 核心特性
- **智能延迟处理**：使用队列延迟机制，根据每日处理量动态调整间隔（10-120分钟）
- **每日限制控制**：默认50个/天，超出限制自动延期到次日随机时间
- **好友来源识别**：9种来源类型自动识别和分类
- **欢迎消息系统**：支持@nickname变量替换，延迟5-15分钟发送

### 相关文件
- `app/Jobs/ProcessFriendRequestJob.php` - 核心防封号处理任务
- `app/Jobs/SendWelcomeMessageJob.php` - 欢迎消息发送任务
- `app/Helpers/FriendSourceAnalyzer.php` - 好友来源分析器
- `app/Pipelines/Xbot/Contact/FriendRequestHandler.php` - 好友请求处理器
- `app/Pipelines/Xbot/Contact/NotificationHandler.php` - 好友通知处理器
- `app/Services/XbotConfigManager.php` - 好友配置管理

### 配置命令
```bash
# 基础开关
/set friend_auto_accept 1           # 开启自动同意好友请求

# 欢迎消息配置
/set welcome_msg "@nickname 你好，欢迎你！"      # 好友欢迎消息模板（私聊中设置）

# 群欢迎消息配置（在对应群聊中设置）
/set room_msg 1                              # 先开启群消息处理
/set welcome_msg "@nickname 欢迎加入我们的大家庭！"  # 设置该群的欢迎消息

# 高级参数
/set friend_daily_limit 30          # 设置每日处理上限
```

## 好友来源识别系统 (2025-09-13 完成)

### 来源类型分类
| 来源类型 | Scene值 | 识别特征 | 描述 |
|---------|---------|----------|------|
| `group` | 14 | content包含"我是群聊...的" + chatroomusername存在 | 群聊添加 |
| `search_phone` | 1 | scene=1 或 scene=30且content含数字 | 搜索手机号 |
| `search_wechat` | 2 | scene=2 或 scene=30且content含英文 | 搜索微信号 |
| `search_general` | 30 | scene=30 + content="我是..." | 一般搜索 |
| `qr_code` | 8 | scene=8 | 扫一扫 |
| `contact_card` | 13 | scene=13 | 名片分享 |
| `nearby` | 25 | scene=25 | 附近的人 |
| `shake` | 17 | scene=17 | 摇一摇 |
| `unknown` | 其他 | 无法识别的情况 | 未知来源 |

### 核心实现
```php
// 分析好友来源
$sourceAnalysis = FriendSourceAnalyzer::analyze($data);
// 返回：['source_type' => 'group', 'source_desc' => '群聊添加', 'details' => [...]]

// 增强的通知消息格式
收到好友请求
来自：张三  
来源：群聊添加 (scene:14)
消息：我是群聊"测试群"的张三
```

### Chatwoot集成
- 联系人记录自动包含 `scene` 字段
- 支持后续数据统计和来源分析
- 完整的好友来源追踪体系

## 群级别配置管理系统 (2025-09-13 完成)

### 配置逻辑
群级别配置采用"例外列表"概念：
- **全局配置**作为默认值
- **群级别配置**作为例外/覆盖

#### 支持的群级别配置
```php
'room_msg' => '群消息处理',           // 控制群消息处理（覆盖全局room_msg设置）
'check_in' => '群签到系统',         // 控制群签到功能（覆盖全局check_in设置）  
'youtube_room' => 'YouTube链接响应',     // 控制群YouTube功能
```

### 配置关系逻辑
1. **room_msg (全局) 与 room_msg (群级别)**：
   - 如果群设置了 `room_msg`，则该群按群级别 `room_msg` 的值处理消息
   - 如果群没有设置 `room_msg`，则该群按全局 `room_msg` 的值处理消息

2. **check_in (全局) 与 check_in (群级别)**：
   - 如果群设置了 `check_in`，则该群按群级别 `check_in` 的值处理签到
   - 如果群没有设置 `check_in`，则该群按全局 `check_in` 的值处理签到

### 自动启用机制
- 启用 `check_in` 时自动启用 `room_msg`
- 保证配置项之间的依赖关系正确

### 测试覆盖
- 完整的群级别配置测试套件（`tests/Feature/GroupLevelConfigTest.php`）
- 9个综合测试用例，覆盖所有配置场景
- 权限验证、输入验证、依赖关系测试

## 架构重构完成记录 (2025-01-14)

### Services 目录重构
完成了完整的Services目录结构重构，按职责分离：
- **Clients/** - API客户端 (`XbotClient`, `ChatwootClient`)
- **Managers/** - 协调管理 (`ConfigManager`) 
- **Processors/** - 业务逻辑处理 (`ContactSyncProcessor`)
- **Analytics/** - 数据分析 (`FriendSourceAnalyzer`)
- **Guards/** - 权限控制 (`PermissionGuard`)
- **StateHandlers/** - 状态管理 (`BotStateHandler`)
- **Controllers/** - HTTP控制器 (`XbotController`, `ChatwootController`)

### HTTP 层重构  
完成了HTTP层的架构简化：
- 将MessageDispatcher业务逻辑合并到XbotController中，简化调用链
- 移除Api命名空间层级，统一控制器位置
- 创建专门的中间件 (`XbotAuthentication`, `RateLimitWebhook`)
- 简化响应处理（直接在Controller中处理）
- 职责分离：RequestProcessor处理复杂的验证和数据准备

### 类名变更记录
- `XbotConfigManager` → `ConfigManager`
- `Xbot` → `XbotClient`  
- `Chatwoot` → `ChatwootClient`
- `CheckInStatsService` → `CheckInAnalytics`

### 测试修复
完成了所有测试文件的引用更新，确保重构后的测试通过。

## 重构教训总结 (2025-09-14)

### ⚠️ **重构过度简化警示**

在重构 `ChatwootHandler` 过程中发现了一个关键问题：**过度简化导致核心业务逻辑丢失**。

#### 📋 **问题演进**：
1. **最初版本**：直接同步所有消息到Chatwoot
2. **中期版本**：引入 `shouldSyncToChatwoot()` 方法，检查关键词同步配置
3. **重构版本**：❌ 错误地简化为 `return true`，移除了所有配置检查

#### 🐛 **具体问题**：
```php
// ❌ 重构后的错误实现
private function shouldSyncToChatwoot(...): bool {
    // 所有消息都同步到 Chatwoot
    return true;  // 完全忽略了Chatwoot启用状态！
}
```

这导致即使用户禁用Chatwoot，仍然会：
- 创建不必要的队列任务
- 产生误导性的日志："Message sent to Chatwoot queue"  
- 浪费系统资源

#### ✅ **正确的实现**：
```php
private function shouldSyncToChatwoot(...): bool {
    $configManager = new ConfigManager($context->wechatBot);
    
    // 首先检查Chatwoot是否启用（基础检查）
    if (!$configManager->isEnabled('chatwoot')) {
        return false;
    }
    
    // 然后进行其他业务逻辑检查
    return true;
}
```

### 📚 **重构原则教训**：

#### 🚫 **避免的陷阱**：
1. **过度简化**：不要为了"简洁"而移除核心业务逻辑
2. **假设驱动**：不要假设功能总是启用的
3. **测试盲点**：必须测试功能禁用的场景

#### ✅ **正确的重构方式**：
1. **保留核心逻辑**：基础的功能开关检查必须保留
2. **事实驱动**：基于实际使用场景，不做假设  
3. **全场景测试**：包括启用/禁用的完整测试覆盖
4. **渐进式重构**：分步骤验证，不要一次性大改

### 🔍 **检查清单**：
重构时必须验证：
- [ ] 所有配置开关都被正确检查
- [ ] 没有硬编码的 `return true`  
- [ ] 测试覆盖启用/禁用两种状态
- [ ] 日志和行为与配置状态一致

> **重要**：简化代码是好的，但绝不能以牺牲核心业务逻辑为代价！

## AI已经帮我完成的重构任务在 .claude/done/ 目录下，你可以随时读取参考
