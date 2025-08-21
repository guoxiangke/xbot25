<?php

namespace App\Pipelines\Xbot\Contracts;

use App\Pipelines\Xbot\XbotMessageContext;
use Closure;

interface XbotHandlerInterface
{
    /**
     * 处理Xbot消息
     */
    public function handle(XbotMessageContext $context, Closure $next);
}
