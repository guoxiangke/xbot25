<?php

namespace App\Helpers;

class FriendSourceAnalyzer
{
    /**
     * 好友来源类型常量
     */
    const FRIEND_SOURCE_TYPES = [
        'group' => '群聊添加',
        'search_phone' => '搜索手机号',
        'search_wechat' => '搜索微信号',
        'search_general' => '一般搜索',
        'qr_code' => '扫一扫',
        'contact_card' => '名片分享',
        'nearby' => '附近的人',
        'shake' => '摇一摇',
        'unknown' => '未知来源'
    ];

    /**
     * 根据好友请求消息分析好友来源类型
     *
     * @param array $messageData MT_RECV_FRIEND_MSG消息的data字段
     * @return array ['source_type' => string, 'source_desc' => string, 'details' => array]
     */
    public static function analyze(array $messageData): array
    {
        $rawMsg = $messageData['raw_msg'] ?? '';
        
        // 解析XML格式的raw_msg
        $parsedData = self::parseRawMessage($rawMsg);
        
        // 提取关键字段
        $scene = $parsedData['scene'] ?? '';
        $content = $parsedData['content'] ?? '';
        $chatroomUsername = $parsedData['chatroomusername'] ?? '';
        $fromnickname = $parsedData['fromnickname'] ?? '';
        
        // 分析来源类型
        $sourceType = self::determineSourceType($scene, $content, $chatroomUsername);
        
        return [
            'source_type' => $sourceType,
            'source_desc' => self::FRIEND_SOURCE_TYPES[$sourceType],
            'details' => [
                'scene' => $scene,
                'content' => $content,
                'from_nickname' => $fromnickname,
                'chatroom_username' => $chatroomUsername,
                'parsed_fields' => $parsedData
            ]
        ];
    }

    /**
     * 解析raw_msg中的XML数据
     *
     * @param string $rawMsg
     * @return array
     */
    private static function parseRawMessage(string $rawMsg): array
    {
        $result = [];
        
        // 使用正则表达式提取XML中的关键属性
        $attributes = [
            'scene', 'content', 'chatroomusername', 'fromnickname',
            'fullpy', 'shortpy', 'country', 'province', 'city'
        ];
        
        foreach ($attributes as $attr) {
            if (preg_match('/' . $attr . '="([^"]*)"/', $rawMsg, $matches)) {
                $result[$attr] = $matches[1];
            }
        }
        
        return $result;
    }

    /**
     * 根据关键字段确定好友来源类型
     *
     * @param string $scene
     * @param string $content
     * @param string $chatroomUsername
     * @return string
     */
    private static function determineSourceType(string $scene, string $content, string $chatroomUsername): string
    {
        // 1. 群聊添加：scene="14" 且 content包含"我是群聊...的"
        if ($scene === '14' && !empty($chatroomUsername) && preg_match('/我是群聊.*的/', $content)) {
            return 'group';
        }
        
        // 2. 扫一扫：scene="8"
        if ($scene === '8') {
            return 'qr_code';
        }
        
        // 3. 名片分享：scene="13"
        if ($scene === '13') {
            return 'contact_card';
        }
        
        // 4. 附近的人：scene="25"
        if ($scene === '25') {
            return 'nearby';
        }
        
        // 5. 摇一摇：scene="17"
        if ($scene === '17') {
            return 'shake';
        }
        
        // 6. 手机号搜索：scene="1"
        if ($scene === '1') {
            return 'search_phone';
        }
        
        // 7. 微信号搜索：scene="2"
        if ($scene === '2') {
            return 'search_wechat';
        }
        
        // 8. 一般搜索：scene="30" 且 content以"我是"开头
        if ($scene === '30' && preg_match('/^我是/', $content)) {
            // 可以进一步推测是手机号还是微信号搜索
            return self::guessSearchType($content);
        }
        
        // 9. 其他scene="30"的情况
        if ($scene === '30') {
            return 'search_general';
        }
        
        // 默认情况
        return 'unknown';
    }

    /**
     * 推测具体的搜索类型（手机号或微信号）
     *
     * @param string $content
     * @return string
     */
    private static function guessSearchType(string $content): string
    {
        // 如果内容包含英文字母，可能是微信号搜索（优先检查）
        if (preg_match('/[a-zA-Z]/', $content)) {
            return 'search_wechat';
        }
        
        // 如果内容包含较多连续数字，可能是手机号搜索
        if (preg_match('/\d{3,}/', $content)) {
            return 'search_phone';
        }
        
        // 默认为一般搜索
        return 'search_general';
    }

    /**
     * 获取所有支持的来源类型
     *
     * @return array
     */
    public static function getSupportedSourceTypes(): array
    {
        return self::FRIEND_SOURCE_TYPES;
    }
}