<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\WeChat\CustomerServiceMessage;

class EasyWeChatKeywordHandler
{
    /**
     * 处理文本消息中的关键词
     */
    public function __invoke($message, \Closure $next)
    {
        Log::info('EasyWeChatKeywordHandler invoked', [
            'msg_type' => $message->MsgType ?? 'unknown',
            'content' => $message->Content ?? 'no_content',
            'from_user' => $message->FromUserName ?? 'unknown',
        ]);

        // 只处理文本消息
        if ($message->MsgType !== 'text') {
            Log::debug('Not a text message, passing to next handler');
            return $next($message);
        }

        $keyword = trim($message->Content);
        Log::info('Processing keyword', ['keyword' => $keyword]);
        
        // 特殊关键词：发送多条消息示例
        if ($keyword === '多条消息' || $keyword === 'multi') {
            $this->sendMultipleMessages($message->FromUserName);
            return '正在为您发送多条消息...';
        }
        
        // 获取关键词对应的资源
        $resource = $this->getResource($keyword);
        
        Log::info('Resource lookup result', [
            'keyword' => $keyword,
            'found_resource' => !empty($resource),
            'resource_type' => $resource['type'] ?? 'unknown',
        ]);
        
        if (!$resource) {
            // 没有找到资源，继续传递给下一个处理器
            Log::debug('No resource found, passing to next handler');
            return $next($message);
        }

        // 根据资源类型返回相应的消息格式
        $response = $this->buildResponse($resource, $message);
        Log::info('Built response for keyword', [
            'keyword' => $keyword,
            'response_type' => gettype($response),
        ]);
        return $response;
    }

    /**
     * 获取关键词对应的资源
     * 从KeywordResponseHandler移动到此处，以便订阅系统复用
     */
    public function getResource($keyword)
    {
        $cacheKey = "resources.{$keyword}";
        
        // 先检查缓存是否存在
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }
        
        // 发起API请求
        $response = Http::get(config('services.xbot.resource_endpoint')."{$keyword}");
        
        if($response->ok() && $data = $response->json()){
            // 只有成功获取到资源时才缓存
            $secondsUntilTomorrow = Carbon::tomorrow('Asia/Shanghai')->timestamp - now()->timestamp;
            Cache::put($cacheKey, $data, $secondsUntilTomorrow);
            return $data;
        }
        
        // 无效结果不进行缓存，直接返回false
        return false;
    }

    /**
     * 根据资源类型构建EasyWeChat响应格式
     * 所有字段名都已根据微信官方文档标准化
     * @see https://developers.weixin.qq.com/doc/service/guide/product/message/Passive_user_reply_message.html
     */
    private function buildResponse($resource, $message)
    {
        Log::info('Building response for resource:', $resource);

        // 处理纯文本资源
        if (isset($resource['type']) && $resource['type'] === 'text') {
            return $resource['content'] ?? $resource['text'] ?? '资源内容获取失败';
        }

        // 处理图片资源
        if (isset($resource['type']) && $resource['type'] === 'image') {
            return [
                'MsgType' => 'image',
                'Image' => [
                    'MediaId' => $resource['media_id'] ?? '',
                ],
            ];
        }

        // 处理语音资源
        if (isset($resource['type']) && $resource['type'] === 'voice') {
            return [
                'MsgType' => 'voice',
                'Voice' => [
                    'MediaId' => $resource['media_id'] ?? '',
                ],
            ];
        }

        // 处理视频资源
        if (isset($resource['type']) && $resource['type'] === 'video') {
            return [
                'MsgType' => 'video',
                'Video' => [
                    'MediaId' => $resource['media_id'] ?? '',
                    'Title' => $resource['title'] ?? '',
                    'Description' => $resource['description'] ?? '',
                ],
            ];
        }

        // 处理音乐资源 - 使用固定ThumbMediaId
        if (isset($resource['type']) && $resource['type'] === 'music') {
            $musicData = $resource['data'] ?? $resource;
            
            // 使用固定的ThumbMediaId
            $thumbMediaId = '0EiLlKqUqHoIZkYcahv0y0-L-gG7i2jJfmCL0OvqHC4';
            
            // 处理URL，添加redirect功能
            $url = $musicData['url'] ?? '';
            $to = $message->FromUserName ?? '';
            
            if (isset($resource['statistics']) && !empty($url)) {
                $resource['statistics']['bot'] = 'EasyWechat'; // 或者其他适当的标识
                $tags = http_build_query($resource['statistics'], '', '%26');
                $url = config('services.xbot.redirect') . urlencode($musicData['url']) . "?" . $tags . '%26to=' . $to;
            }
            
            $musicResponse = [
                'MsgType' => 'music',
                'Music' => [
                    'Title' => $musicData['title'] ?? '音频资源',
                    'Description' => $musicData['description'] ?? '',
                    'MusicUrl' => $url,
                    'HQMusicUrl' => $url,
                    'ThumbMediaId' => $thumbMediaId,
                    'thumburl' => $resource['image'] ?? '',
                    'songalbumurl' => $resource['image'] ?? '',
                    'songlyric' => $resource['lyrics'] ?? '',
                ],
            ];
            return $musicResponse;
        }

        // 处理图文消息（单条）
        if (isset($resource['type']) && $resource['type'] === 'news') {
            return [
                'MsgType' => 'news',
                'ArticleCount' => 1,
                'Articles' => [
                    [
                        'Title' => $resource['title'] ?? '',
                        'Description' => $resource['description'] ?? '',
                        'PicUrl' => $resource['pic_url'] ?? '',
                        'Url' => $resource['url'] ?? '',
                    ],
                ],
            ];
        }

        // 处理多图文消息
        if (isset($resource['type']) && $resource['type'] === 'news_multi' && isset($resource['articles'])) {
            return [
                'MsgType' => 'news',
                'ArticleCount' => count($resource['articles']),
                'Articles' => array_map(function($article) {
                    return [
                        'Title' => $article['title'] ?? '',
                        'Description' => $article['description'] ?? '',
                        'PicUrl' => $article['pic_url'] ?? '',
                        'Url' => $article['url'] ?? '',
                    ];
                }, $resource['articles']),
            ];
        }

        // 处理链接消息（作为图文消息返回）
        if (isset($resource['url'])) {
            return [
                'MsgType' => 'news',
                'ArticleCount' => 1,
                'Articles' => [
                    [
                        'Title' => $resource['title'] ?? '链接分享',
                        'Description' => $resource['description'] ?? '',
                        'PicUrl' => $resource['pic_url'] ?? '',
                        'Url' => $resource['url'],
                    ],
                ],
            ];
        }

        // 默认返回文本消息
        if (isset($resource['content'])) {
            return $resource['content'];
        }

        if (isset($resource['text'])) {
            return $resource['text'];
        }

        if (isset($resource['message'])) {
            return $resource['message'];
        }

        // 如果都没有匹配到，返回JSON字符串（调试用）
        return '资源格式未识别：' . json_encode($resource, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 发送多条消息示例
     */
    private function sendMultipleMessages(string $openid): void
    {
        try {
            // 获取客服消息服务实例
            $controller = new EasyWeChatController();
            $customerService = $controller->getCustomerService();

            // 定义多条消息
            $messages = [
                [
                    'type' => 'text',
                    'content' => '👋 欢迎使用我们的服务！',
                ],
                [
                    'type' => 'text',
                    'content' => '📚 以下是我们为您准备的资源：',
                ],
                [
                    'type' => 'news',
                    'title' => '功能介绍',
                    'description' => '了解我们的主要功能和特色',
                    'url' => 'https://example.com/features',
                    'pic_url' => 'https://example.com/images/features.jpg',
                ],
                [
                    'type' => 'text',
                    'content' => '💡 小提示：发送关键词即可获取相关资源',
                ],
                [
                    'type' => 'text',
                    'content' => '🎯 感谢您的使用，祝您使用愉快！',
                ],
            ];

            // 发送多条消息
            $results = $customerService->sendMultiple($openid, $messages);
            
            Log::info('Multiple messages sent', [
                'openid' => $openid,
                'message_count' => count($messages),
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send multiple messages', [
                'openid' => $openid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 使用客服消息发送资源（支持多条消息）
     */
    public function sendResourceAsCustomerMessage(string $openid, array $resource): void
    {
        try {
            $controller = new EasyWeChatController();
            $customerService = $controller->getCustomerService();

            // 如果资源支持多条消息展示
            if (isset($resource['multiple_messages']) && is_array($resource['multiple_messages'])) {
                $customerService->sendMultiple($openid, $resource['multiple_messages']);
                return;
            }

            // 单条消息发送
            $messageData = $this->convertResourceToCustomerMessage($resource);
            if ($messageData) {
                $customerService->sendByType($openid, $messageData);
            }

        } catch (\Exception $e) {
            Log::error('Failed to send resource as customer message', [
                'openid' => $openid,
                'resource' => $resource,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 将资源转换为客服消息格式
     * 字段名已根据微信官方客服消息API标准调整
     * @see https://developers.weixin.qq.com/doc/service/api/customer/message/api_sendcustommessage.html
     */
    private function convertResourceToCustomerMessage(array $resource): ?array
    {
        $type = $resource['type'] ?? '';

        switch ($type) {
            case 'text':
                return [
                    'type' => 'text',
                    'content' => $resource['content'] ?? $resource['text'] ?? '',
                ];

            case 'image':
                return [
                    'type' => 'image',
                    'media_id' => $resource['media_id'] ?? '',
                ];

            case 'voice':
                return [
                    'type' => 'voice',
                    'media_id' => $resource['media_id'] ?? '',
                ];

            case 'video':
                return [
                    'type' => 'video',
                    'media_id' => $resource['media_id'] ?? '',
                    'thumb_media_id' => $resource['thumb_media_id'] ?? '',
                    'title' => $resource['title'] ?? '',
                    'description' => $resource['description'] ?? '',
                ];

            case 'music':
                return [
                    'type' => 'music',
                    'title' => $resource['title'] ?? '',
                    'description' => $resource['description'] ?? '',
                    'musicurl' => $resource['music_url'] ?? $resource['url'] ?? '',
                    'hqmusicurl' => $resource['hq_music_url'] ?? $resource['url'] ?? '',
                    'thumb_media_id' => $resource['thumb_media_id'] ?? '0EiLlKqUqHoIZkYcahv0y0-L-gG7i2jJfmCL0OvqHC4',
                    'thumburl' => $resource['image'] ?? '',
                    'songalbumurl' => $resource['image'] ?? '',
                    'songlyric' => $resource['lyrics'] ?? '',
                ];

            case 'news':
                if (isset($resource['articles'])) {
                    return [
                        'type' => 'news',
                        'articles' => $resource['articles'],
                    ];
                } else {
                    return [
                        'type' => 'news',
                        'title' => $resource['title'] ?? '',
                        'description' => $resource['description'] ?? '',
                        'url' => $resource['url'] ?? '',
                        'picurl' => $resource['pic_url'] ?? $resource['image'] ?? '',
                    ];
                }

            default:
                // 默认作为文本消息发送
                if (isset($resource['content'])) {
                    return [
                        'type' => 'text',
                        'content' => $resource['content'],
                    ];
                }
                return null;
        }
    }

}