<?php

namespace App\Pipelines\Xbot\Handlers;

use App\Pipelines\Xbot\XbotMessageContext;
use Illuminate\Support\Str;
use Closure;

/**
 * 自消息处理器
 * 处理机器人发给自己的消息（系统指令）
 */
class SelfMessageHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) || !$context->isSelf) {
            return $next($context);
        }

        if ($this->isMessageType($context, 'MT_RECV_TEXT_MSG')) {
            $msg = $context->requestRawData['msg'] ?? '';

            if (Str::startsWith($msg, '/help')) {
                $this->handleHelpCommand($context);
                $context->markAsProcessed(static::class);
                return $context;
            }

            if (Str::startsWith($msg, '/switch handleRoomMsg')) {
                $this->handleSwitchCommand($context);
                $context->markAsProcessed(static::class);
                return $context;
            }
        }

        return $next($context);
    }

    /**
     * 处理帮助命令
     */
    private function handleHelpCommand(XbotMessageContext $context): void
    {
        $helpText = "Hi，我是一个AI机器人，暂支持以下指令：\n"
            . "/help - 显示帮助信息\n"
            . "/whoami - 显示当前登录信息\n"
            . "/switch handleRoomMsg - 群消息处理开关";

        $this->sendTextMessage($context, $helpText, $context->wechatBot->wxid);
        $this->log('Help command processed');
    }

    /**
     * 处理开关命令
     */
    private function handleSwitchCommand(XbotMessageContext $context): void
    {
        $wechatBot = $context->wechatBot;
        $isHandleRoomMsg = $wechatBot->getMeta('handleRoomMsg', false);
        $wechatBot->setMeta('handleRoomMsg', !$isHandleRoomMsg);

        $status = $isHandleRoomMsg ? '已禁用' : '已启用';
        $msg = "/handleRoomMsg $status";

        $this->sendTextMessage($context, $msg, $wechatBot->wxid);
        $this->log('Switch command processed', ['status' => $status]);
    }
}
