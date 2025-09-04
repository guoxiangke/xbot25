# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based WeChat automation bot system called "Xbot". It provides a comprehensive API for WeChat automation including:
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
- 这是一个laravel 重构项目，旧的部分代码放在了old.app中以供参考，不要改动app.old 下的内容
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
- 在clear和compact时， •结束会话前：总是告诉Claude：“请更新.claude/activeContext.md和./claude/progress.md，总结完成的工作并概述后续步骤。

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

- MT_RECV_VOICE_MSG 消息体： {"data":{"from_wxid":"bluesky_still","is_pc":0,"mp3_file":"C:\\Users\\win11\\Documents\\WeChat Files\\wxid_t36o5djpivk312\\mp3\\1598934079525226.mp3","msgid":"7762958499218113803","raw_msg":"<msg><voicemsg endflag=\"1\" cancelflag=\"0\" forwardflag=\"0\" voiceformat=\"4\" voicelength=\"1560\" length=\"2933\" bufid=\"0\" aeskey=\"7e889c457f301d8b4118a8a07eb3e1ff\" voiceurl=\"3052020100044b304902010002043ac6d79302030f5efb02040c90af2b020468b51f48042466333261363331662d323161342d343164622d383638322d6538326231393632353837630204011c000f0201000400c27235ca\" voicemd5=\"\" clientmsgid=\"493474d1b0bfa9ac2fb4bd883b4c3891wxid_t36o5djpivk312_3370_1756700486\" fromusername=\"bluesky_still\" /></msg>","room_wxid":"","silk_file":"","timestamp":1756700488,"to_wxid":"wxid_t36o5djpivk312","wx_type":34},"type":"MT_RECV_VOICE_MSG","client_id":12}

## MCP Context7 技术文档需求

为支持MCP context7集成，需要以下技术文档：

- **Laravel 12.x 官方文档** - 项目基于Laravel 12.x框架
- **Chatwoot API 文档** - 客服系统集成
- ** plank/laravel-metable 文档 ** https://github.com/plank/laravel-metable
