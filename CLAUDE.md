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
- 不要改动app.old 文件夹的内容，里面的内容是旧的，可以参考
- 对于每个长的重构需求，请你先帮我分析一下这个需求
- 设计到删除文件的，先不删除，继续执行下一步，任务结束后列出要删除的文件
- 这是一个 laravel 重构项目，重构时请遵循 laravel 的最佳实践
- 原来的laravel 我放在了 app.old 目录下，可以参考
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
