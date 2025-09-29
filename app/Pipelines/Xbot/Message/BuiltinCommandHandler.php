<?php

namespace App\Pipelines\Xbot\Message;

use App\Models\XbotSubscription;
use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use App\Services\Managers\ConfigManager;
use App\Services\CheckInPermissionService;
use App\Services\ChatroomMessageFilter;
use Closure;

/**
 * 内置命令处理器
 * 处理 whoami 等内置系统命令
 */
class BuiltinCommandHandler extends BaseXbotHandler
{
    private const COMMANDS = [
        '/help' => ['method' => 'handleHelpCommand', 'description' => '显示帮助信息'],
        '/whoami' => ['method' => 'handleWhoamiCommand', 'description' => '显示当前登录信息'],
        '/get subscriptions' => ['method' => 'handleGetSubscriptionsCommand', 'description' => '查看当前订阅列表'],
        '/get wxid' => ['method' => 'handleGetWxidCommand', 'description' => '获取wxID'],
        '/get chatwoot' => ['method' => 'redirectToSelfHandler', 'description' => '查看Chatwoot配置详情', 'hidden' => true],
        '/get room_alias' => ['method' => 'redirectToSelfHandler', 'description' => '查看群邀请别名配置', 'hidden' => true],
        '/get room_msg' => ['method' => 'redirectToSelfHandler', 'description' => '查看群消息处理配置', 'hidden' => true],
        '/get check_in' => ['method' => 'redirectToSelfHandler', 'description' => '查看群签到配置', 'hidden' => true],
        '/get room_quit' => ['method' => 'redirectToSelfHandler', 'description' => '查看群退出监控配置', 'hidden' => true],
        '/get youtube' => ['method' => 'redirectToSelfHandler', 'description' => '查看YouTube响应配置', 'hidden' => true],
        '/get blacklist' => ['method' => 'redirectToSelfHandler', 'description' => '查看黑名单配置', 'hidden' => true],
        '/get timezone' => ['method' => 'redirectToSelfHandler', 'description' => '查看群时区配置', 'hidden' => true],
        '/sync contacts' => ['method' => 'redirectToSelfHandler', 'description' => '同步联系人列表', 'hidden' => true],
        '/check online' => ['method' => 'redirectToSelfHandler', 'description' => '检查微信在线状态', 'hidden' => true],
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // BuiltinCommandHandler 作为最高优先级处理器，不检查 isProcessed 状态
        // 只检查消息类型，确保命令能够被优先处理
        // 避免对非文本消息进行不必要的命令解析

        if (!$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return $next($context);
        }
        $keyword = trim($context->requestRawData['msg'] ?? '');

        // 处理命令匹配（包括大小写和空格处理）
        $normalizedKeyword = strtolower(trim($keyword));
        $commandFound = false;
        $matchedCommand = null;

        foreach (self::COMMANDS as $command => $method) {
            if (strtolower(trim($command)) === $normalizedKeyword) {
                $commandFound = true;
                $matchedCommand = $command;
                break;
            }
        }

        if ($commandFound && $matchedCommand) {
            $method = self::COMMANDS[$matchedCommand]['method'];
            $this->log(__FUNCTION__, ['message' => 'Executed','command' => $matchedCommand, 'method' => $method, 'originalKeyword' => $keyword]);
            $this->$method($context);
            
            // 继续传递到下游处理器（如ChatwootHandler），让命令也同步到Chatwoot
            return $next($context);
        }



        return $next($context);
    }

    /**
     * 处理 whoami 命令
     */
    private function handleWhoamiCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $winToken = $wechatBot->wechatClient->token;
        $time = optional($wechatBot->login_at)->diffForHumans();
        $port = "{$wechatBot->client_id}@{$winToken}";

        $text = "登陆时长：$time\n"
            . "设备端口: $port\n"
            . "北京时间: {$wechatBot->login_at}";

        $this->sendTextMessage($context, $text);
        $this->markAsReplied($context);
    }


    /**
     * 处理帮助命令
     */
    private function handleHelpCommand(XbotMessageContext $context): void
    {
        $helpText = "Hi，我是AI，支持以下指令：\n";

        // 显示基础命令
        $helpText .= "\n🔍 基础查询命令：\n";
        foreach (self::COMMANDS as $command => $config) {
            // 跳过隐藏的命令或空描述的命令
            if (!empty($config['hidden']) || empty($config['description'])) {
                continue;
            }
            $helpText .= "{$command} - {$config['description']}\n";
        }

        // 显示配置管理命令
        $helpText .= "\n🔧 配置管理命令：\n";
        $helpText .= "/config - 查看所有配置状态\n";
        $helpText .= "/set <key> <value> - 设置配置项\n";
        $helpText .= "/config <key> <value> - 设置配置项(等效)\n";
        
        // 显示特殊查询命令
        $helpText .= "\n📊 配置查询命令：\n";
        $helpText .= "/get chatwoot - 查看Chatwoot配置详情\n";
        $helpText .= "/get room_alias - 查看群邀请别名配置\n";
        $helpText .= "/get room_msg - 查看群消息处理配置\n";
        $helpText .= "/get check_in - 查看群签到配置\n";
        $helpText .= "/get room_quit - 查看群退出监控配置\n";
        $helpText .= "/get youtube - 查看YouTube响应配置\n";
        $helpText .= "/get blacklist - 查看黑名单配置\n";
        $helpText .= "/get timezone - 查看时区配置\n";
        
        // 显示黑名单管理命令
        $helpText .= "\n🚫 黑名单管理命令：\n";
        $helpText .= "/set blacklist <wxid> - 添加用户到黑名单\n";
        $helpText .= "/set blacklist -<wxid> - 从黑名单移除用户\n";
        
        // 显示系统管理命令
        $helpText .= "\n⚙️ 系统管理命令：\n";
        $helpText .= "/sync contacts - 同步联系人列表\n";
        $helpText .= "/check online - 检查微信在线状态\n";

        $this->sendTextMessage($context, $helpText);
        $this->markAsReplied($context);
    }




    /**
     * 处理查看订阅列表命令
     */
    private function handleGetSubscriptionsCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $wxid = $context->wxid;

        // 获取当前联系人的所有订阅
        $subscriptions = XbotSubscription::query()
            ->where('wechat_bot_id', $wechatBot->id)
            ->where('wxid', $wxid)
            ->get();

        if ($subscriptions->isEmpty()) {
            $this->sendTextMessage($context, '暂无订阅');
            $this->markAsReplied($context);
            return;
        }

        // 构建订阅列表消息
        $subscriptionList = "当前订阅列表：\n";
        foreach ($subscriptions as $index => $subscription) {
            $hour = $this->getHourFromCron($subscription->cron);
            $subscriptionList .= ($index + 1) . ". {$subscription->keyword} (每天{$hour}点)\n";
        }

        $this->sendTextMessage($context, $subscriptionList);
        $this->markAsReplied($context);
    }

    /**
     * 从cron表达式中提取小时
     */
    private function getHourFromCron(string $cron): int
    {
        $parts = explode(' ', $cron);
        return isset($parts[1]) ? intval($parts[1]) : 7;
    }

    /**
     * 处理获取wxID命令
     */
    private function handleGetWxidCommand(XbotMessageContext $context): void
    {
        if ($context->isRoom) {
            // 在群聊中，返回群ID
            $roomWxid = $context->requestRawData['room_wxid'] ?? '';
            $this->sendTextMessage($context, $roomWxid);
        } else {
            // 在私聊中，返回对方的wxid
            $fromWxid = $context->requestRawData['from_wxid'] ?? '';
            $this->sendTextMessage($context, $fromWxid);
        }
        
        $this->markAsReplied($context);
    }

    /**
     * 重定向到 SelfMessageHandler
     * 某些命令在 BuiltinCommandHandler 中注册用于帮助显示，
     * 但实际处理逻辑在 SelfMessageHandler 中
     */
    private function redirectToSelfHandler(XbotMessageContext $context): void
    {
        // 这些命令的实际处理在 SelfMessageHandler 中进行
        // 这里只是为了在帮助中显示，不做实际处理
        // 让消息继续传递到下游的 SelfMessageHandler
    }



}
