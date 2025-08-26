<?php

namespace App\Pipelines\Xbot;

use Closure;

/**
 * 内置命令处理器
 * 处理 whoami 等内置系统命令
 */
class BuiltinCommandHandler extends BaseXbotHandler
{
    private const COMMANDS = [
        'whoami' => 'handleWhoamiCommand',
        '/help' => 'handleHelpCommand',
        '/whoami' => 'handleWhoamiCommand',
        '/check online' => 'handleCheckOnlineCommand',
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        // BuiltinCommandHandler 作为最高优先级处理器，不检查 isProcessed 状态
        // 只检查消息类型，确保命令能够被优先处理
        // 避免对非文本消息进行不必要的命令解析

        // 调试日志：记录收到的消息类型和内容
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
            $method = self::COMMANDS[$matchedCommand];
            $this->log('Executing command', ['command' => $matchedCommand, 'method' => $method, 'originalKeyword' => $keyword]);
            $this->$method($context);
            $context->markAsProcessed(static::class);
            return $context;
        }

        // 处理 /set 开头的命令（权限检查）
        if (str_starts_with($keyword, '/set ') && !$context->isFromBot) {
            $this->handleSetCommandHint($context);
            $context->markAsProcessed(static::class);
            return $context;
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
     * 处理 set 命令提示
     */
    private function handleSetCommandHint(XbotMessageContext $context): void
    {
        $this->sendTextMessage($context, "⚠️ 权限不足\n设置命令需要使用机器人自己来发送");
        $this->markAsReplied($context);
    }

    /**
     * 处理帮助命令
     */
    private function handleHelpCommand(XbotMessageContext $context): void
    {
        $helpText = "Hi，我是一个AI机器人，暂支持以下指令：\n"
            . "/help - 显示帮助信息\n"
            . "/whoami - 显示当前登录信息\n"
            . "/check online - 检查微信在线状态\n"
            . "-========系统设置=======- \n"
            . "/set room_msg 0/1 - 群消息处理开关\n"
            . "/set chatwoot 0/1 - Chatwoot同步开关";

        $this->sendTextMessage($context, $helpText);
        $this->markAsReplied($context);
    }

    /**
     * 处理 /check online 命令
     * 发送 xbot->getSelfInfo() 检查在线状态
     */
    private function handleCheckOnlineCommand(XbotMessageContext $context): void
    {
        $context->wechatBot->xbot()->getSelfInfo();
//        $this->sendTextMessage($context, "已发送状态检查请求");
    }


}
