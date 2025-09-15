<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 错误响应资源
 * 统一错误响应格式
 */
class ErrorResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray($request): array
    {
        return [
            'error' => [
                'message' => $this->resource,
                'timestamp' => now()->toISOString(),
            ]
        ];
    }

    /**
     * Customize the response format
     */
    public function withResponse($request, $response)
    {
        $response->header('Content-Type', 'application/json; charset=utf-8')
                 ->setEncodingOptions(JSON_UNESCAPED_UNICODE)
                 ->setStatusCode(500);
    }
}