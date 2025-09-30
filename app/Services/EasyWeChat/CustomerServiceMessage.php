<?php

namespace App\Services\EasyWeChat;

use EasyWeChat\OfficialAccount\Application;
use Illuminate\Support\Facades\Log;

class CustomerServiceMessage
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * 发送文本消息
     */
    public function sendText(string $openid, string $content): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'text',
            'text' => [
                'content' => $content,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送图片消息
     */
    public function sendImage(string $openid, string $mediaId): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'image',
            'image' => [
                'media_id' => $mediaId,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送语音消息
     */
    public function sendVoice(string $openid, string $mediaId): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'voice',
            'voice' => [
                'media_id' => $mediaId,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送视频消息
     */
    public function sendVideo(string $openid, string $mediaId, string $thumbMediaId, string $title = '', string $description = ''): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'video',
            'video' => [
                'media_id' => $mediaId,
                'thumb_media_id' => $thumbMediaId,
                'title' => $title,
                'description' => $description,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送音乐消息
     */
    public function sendMusic(string $openid, string $title, string $description, string $musicUrl, string $hqMusicUrl = '', string $thumbMediaId = ''): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'music',
            'music' => [
                'title' => $title,
                'description' => $description,
                'musicurl' => $musicUrl,
                'hqmusicurl' => $hqMusicUrl ?: $musicUrl,
                'thumb_media_id' => $thumbMediaId,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送图文消息（点击跳转到外链）
     */
    public function sendNews(string $openid, array $articles): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'news',
            'news' => [
                'articles' => array_map(function ($article) {
                    return [
                        'title' => $article['title'] ?? '',
                        'description' => $article['description'] ?? '',
                        'url' => $article['url'] ?? '',
                        'picurl' => $article['picurl'] ?? '',
                    ];
                }, $articles),
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送单条图文消息（便捷方法）
     */
    public function sendSingleNews(string $openid, string $title, string $description, string $url, string $picUrl): array
    {
        return $this->sendNews($openid, [
            [
                'title' => $title,
                'description' => $description,
                'url' => $url,
                'picurl' => $picUrl,
            ],
        ]);
    }

    /**
     * 发送图文消息（点击跳转到图文消息页面）
     */
    public function sendMpNews(string $openid, string $mediaId): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'mpnews',
            'mpnews' => [
                'media_id' => $mediaId,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送菜单消息
     */
    public function sendMenu(string $openid, string $headContent, array $menuList, string $tailContent = ''): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'msgmenu',
            'msgmenu' => [
                'head_content' => $headContent,
                'list' => array_map(function ($menu) {
                    return [
                        'id' => $menu['id'],
                        'content' => $menu['content'],
                    ];
                }, $menuList),
                'tail_content' => $tailContent,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送卡券消息
     */
    public function sendCard(string $openid, string $cardId): array
    {
        $message = [
            'touser' => $openid,
            'msgtype' => 'wxcard',
            'wxcard' => [
                'card_id' => $cardId,
            ],
        ];

        return $this->send($message);
    }

    /**
     * 发送多条消息
     */
    public function sendMultiple(string $openid, array $messages): array
    {
        $results = [];
        
        foreach ($messages as $index => $message) {
            $result = $this->sendByType($openid, $message);
            $results[] = $result;
            
            // 记录发送结果
            Log::info("Multiple message {$index} sent", [
                'openid' => $openid,
                'message_type' => $message['type'] ?? 'unknown',
                'success' => $result['success'] ?? false,
            ]);
            
            // 添加延迟避免频率限制
            if ($index < count($messages) - 1) {
                usleep(100000); // 100ms延迟
            }
        }
        
        return $results;
    }

    /**
     * 根据消息类型发送消息（便捷方法）
     */
    public function sendByType(string $openid, array $messageData): array
    {
        $type = $messageData['type'] ?? '';
        
        switch ($type) {
            case 'text':
                return $this->sendText($openid, $messageData['content'] ?? '');
                
            case 'image':
                return $this->sendImage($openid, $messageData['media_id'] ?? '');
                
            case 'voice':
                return $this->sendVoice($openid, $messageData['media_id'] ?? '');
                
            case 'video':
                return $this->sendVideo(
                    $openid,
                    $messageData['media_id'] ?? '',
                    $messageData['thumb_media_id'] ?? '',
                    $messageData['title'] ?? '',
                    $messageData['description'] ?? ''
                );
                
            case 'music':
                return $this->sendMusic(
                    $openid,
                    $messageData['title'] ?? '',
                    $messageData['description'] ?? '',
                    $messageData['musicurl'] ?? $messageData['music_url'] ?? '',
                    $messageData['hqmusicurl'] ?? $messageData['hq_music_url'] ?? '',
                    $messageData['thumb_media_id'] ?? ''
                );
                
            case 'news':
                if (isset($messageData['articles'])) {
                    return $this->sendNews($openid, $messageData['articles']);
                } else {
                    return $this->sendSingleNews(
                        $openid,
                        $messageData['title'] ?? '',
                        $messageData['description'] ?? '',
                        $messageData['url'] ?? '',
                        $messageData['picurl'] ?? $messageData['pic_url'] ?? ''
                    );
                }
                
            case 'mpnews':
                return $this->sendMpNews($openid, $messageData['media_id'] ?? '');
                
            case 'menu':
                return $this->sendMenu(
                    $openid,
                    $messageData['head_content'] ?? '',
                    $messageData['list'] ?? [],
                    $messageData['tail_content'] ?? ''
                );
                
            case 'card':
                return $this->sendCard($openid, $messageData['card_id'] ?? '');
                
            default:
                return [
                    'success' => false,
                    'error' => "Unsupported message type: {$type}",
                ];
        }
    }

    /**
     * 发送客服消息的核心方法
     */
    protected function send(array $message): array
    {
        try {
            Log::info('Sending customer service message', $message);
            
            $response = $this->app->getClient()->postJson('cgi-bin/message/custom/send', $message);
            
            if ($response->getStatusCode() === 200) {
                $result = $response->toArray();
                
                if (($result['errcode'] ?? 0) === 0) {
                    Log::info('Customer service message sent successfully', $result);
                    return [
                        'success' => true,
                        'result' => $result,
                    ];
                } else {
                    Log::error('Customer service message failed', $result);
                    return [
                        'success' => false,
                        'error' => $result['errmsg'] ?? 'Unknown error',
                        'errcode' => $result['errcode'] ?? -1,
                    ];
                }
            } else {
                Log::error('HTTP request failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $response->getContent(),
                ]);
                return [
                    'success' => false,
                    'error' => 'HTTP request failed',
                    'status_code' => $response->getStatusCode(),
                ];
            }
        } catch (\Exception $e) {
            Log::error('Exception occurred while sending customer service message', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

}