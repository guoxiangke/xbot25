<?php

namespace App\Pipelines\Xbot\State;

use App\Pipelines\Xbot\BaseXbotHandler;
use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

/**
 * 僵尸粉检测处理器
 * 处理 MT_ZOMBIE_CHECK_MSG 类型的僵尸粉检测消息
 * 参考 XbotCallbackController.php 第105-121行的逻辑
 */
class ZombieCheckHandler extends BaseXbotHandler
{
    public function handle(XbotMessageContext $context, Closure $next)
    {
        if (!$this->shouldProcess($context) ||
            !$this->isMessageType($context, 'MT_ZOMBIE_CHECK_MSG')) {
            return $next($context);
        }

        $data = $context->requestRawData;
        
        // 保存原始消息类型
        $context->setMetadata('origin_msg_type', $context->msgType);

        // 处理僵尸粉检测结果
        $this->handleZombieCheckResult($context, $data);

        $context->markAsProcessed(static::class);
        return $context;
    }

    /**
     * 处理僵尸粉检测结果
     * 参考 XbotCallbackController.php 第106-120行的逻辑
     */
    private function handleZombieCheckResult(XbotMessageContext $context, array $data): void
    {
        $status = $data['status'] ?? -1;
        $wxid = $data['wxid'] ?? '';

        $this->log('Zombie check result received', [
            'wxid' => $wxid,
            'status' => $status,
            'status_description' => $this->getStatusDescription($status)
        ]);

        switch ($status) {
            case 0:
                // 0 正常状态(不是僵尸粉) 勿打扰提醒
                break;
                
            case 1:
                // 1 检测为僵尸粉(对方把我拉黑了)
                $this->handleZombieCase($context, $wxid, $status);
                break;
                
            case 2:
                // 2 检测为僵尸粉(对方把我从他的好友列表中删除了)
                $this->handleZombieCase($context, $wxid, $status);
                break;
                
            case 3:
                // 3 检测为僵尸粉(原因未知,如遇到3请反馈给我)
                $this->handleZombieCase($context, $wxid, $status);
                break;
                
            default:
                $this->logError('Unknown zombie check status', [
                    'wxid' => $wxid,
                    'status' => $status
                ]);
                break;
        }
    }

    /**
     * 处理僵尸粉情况
     */
    private function handleZombieCase(XbotMessageContext $context, string $wxid, int $status): void
    {
        // 发送联系人卡片给文件助手
        // 参考 XbotCallbackController.php 第114行的逻辑
        $context->wechatBot->xbot()->sendContactCard('filehelper', $wxid);
        
        $statusDesc = $this->getStatusDescription($status);
        
        $this->log('Zombie detected and contact card sent', [
            'wxid' => $wxid,
            'status' => $status,
            'description' => $statusDesc
        ]);
    }

    /**
     * 获取状态描述
     */
    private function getStatusDescription(int $status): string
    {
        $descriptions = [
            0 => '正常状态(不是僵尸粉)',
            1 => '检测为僵尸粉(对方把我拉黑了)',
            2 => '检测为僵尸粉(对方把我从他的好友列表中删除了)',
            3 => '检测为僵尸粉(原因未知)'
        ];
        
        return $descriptions[$status] ?? '未知状态';
    }
}