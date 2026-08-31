<?php

namespace App\Services\Managers;

/**
 * 资源频道解析器
 * 依据本地 config/resource_channels.php 的关键词规则，把关键词映射到所属频道，
 * 用于在请求 xlaravel 资源「之前」判断频道级开关，避免无谓的网络请求。
 *
 * 每个频道支持三类匹配规则（任一命中即归属该频道）：
 * - keywords：整串精确匹配（如 '900'、'odb'、'bibleproject'）。
 * - ranges：数字区间 [min,max]，按关键词「前 3 位」的数值匹配（兼容带 offset 的关键词，
 *   如 '80805' 前 3 位为 808；对应 xlaravel 各 handler substr(0,3) 的解析方式）。
 * - prefixes：整串前缀匹配（如 'hl'、't'、'赞'）。
 */
class ResourceChannelResolver
{
    /**
     * 解析关键词所属频道，未命中返回 null（表示不在本地受控频道内，按原流程正常请求）
     */
    public static function resolve(string $keyword): ?string
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return null;
        }

        $isDigit = ctype_digit($keyword);
        $base = $isDigit ? (int) substr($keyword, 0, 3) : null;

        foreach ((array) config('resource_channels', []) as $channel => $rules) {
            foreach ($rules['keywords'] ?? [] as $kw) {
                if ($keyword === (string) $kw) {
                    return (string) $channel;
                }
            }

            if ($isDigit) {
                foreach ($rules['ranges'] ?? [] as $range) {
                    [$min, $max] = $range;
                    if ($base >= $min && $base <= $max) {
                        return (string) $channel;
                    }
                }
            }

            foreach ($rules['prefixes'] ?? [] as $prefix) {
                if ($prefix !== '' && str_starts_with($keyword, (string) $prefix)) {
                    return (string) $channel;
                }
            }
        }

        return null;
    }
}
