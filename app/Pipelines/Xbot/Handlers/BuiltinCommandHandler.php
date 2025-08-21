<?php

namespace App\Pipelines\Xbot\Handlers;

use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 内置命令处理器
 * 处理 whoami 等内置系统命令
 */
class BuiltinCommandHandler extends BaseXbotHandler
{
    private const COMMANDS = [
        'whoami' => 'handleWhoamiCommand',
    ];

    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            return $next($context);
        }

        $keyword = trim($context->requestRawData['msg'] ?? '');

        if (isset(self::COMMANDS[$keyword])) {
            $method = self::COMMANDS[$keyword];
            $this->$method($context);
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
        $time = optional($wechatBot->login_at)->diffForHumans();
        $text = "已登陆 $time\n时间: {$wechatBot->login_at}\n设备: {$wechatBot->client_id}号端口@Windows{$wechatBot->wechat_client_id}";

        $this->sendTextMessage($context, $text);
        $this->markAsReplied($context);

        $this->log('Whoami command processed', [
            'wxid' => $context->requestRawData['from_wxid'] ?? ''
        ]);
    }
}
