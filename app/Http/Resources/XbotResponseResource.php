<?php

namespace App\Http\Resources;

use Illuminate\Http\Response;

/**
 * Xbot 响应资源
 * 返回简洁的纯文本响应
 */
class XbotResponseResource
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * 创建纯文本响应
     */
    public function toResponse($request): Response
    {
        $content = $this->data ?? 'ok';
        
        return new Response(
            $content,
            200,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }
}