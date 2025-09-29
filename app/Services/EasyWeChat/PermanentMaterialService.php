<?php

namespace App\Services\EasyWeChat;

use EasyWeChat\OfficialAccount\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

/**
 * 微信永久素材管理服务
 * 基于 EasyWeChat 6.x 直接调用微信API
 * @see https://developers.weixin.qq.com/doc/service/api/material/permanent/api_getmaterial.html
 */
class PermanentMaterialService
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * 获取永久素材
     * @param string $mediaId 媒体文件ID
     * @return array
     */
    public function getMaterial(string $mediaId): array
    {
        try {
            $client = $this->app->createClient();
            $response = $client->postJson('cgi-bin/material/get_material', [
                'media_id' => $mediaId
            ]);
            
            $result = $response->toArray();
            
            Log::info('获取永久素材成功', [
                'media_id' => $mediaId,
                'response_keys' => array_keys($result)
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('获取永久素材失败', [
                'media_id' => $mediaId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取永久素材总数
     * @return array
     */
    public function getMaterialCount(): array
    {
        try {
            $client = $this->app->createClient();
            $response = $client->get('cgi-bin/material/get_materialcount');
            $result = $response->toArray();
            
            Log::info('获取永久素材总数成功', $result);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('获取永久素材总数失败', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取永久素材列表
     * @param string $type 素材的类型，图片（image）、视频（video）、语音 （voice）、图文（news）
     * @param int $offset 从全部素材中的该偏移位置开始返回，0表示从第一个素材
     * @param int $count 返回素材的数量，取值在1到20之间
     * @return array
     */
    public function getMaterialList(string $type, int $offset = 0, int $count = 20): array
    {
        try {
            $client = $this->app->createClient();
            $response = $client->postJson('cgi-bin/material/batchget_material', [
                'type' => $type,
                'offset' => $offset,
                'count' => $count
            ]);
            
            $result = $response->toArray();
            
            Log::info('获取永久素材列表成功', [
                'type' => $type,
                'offset' => $offset,
                'count' => $count,
                'total_count' => $result['total_count'] ?? 0
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('获取永久素材列表失败', [
                'type' => $type,
                'offset' => $offset,
                'count' => $count,
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 上传永久素材
     * @param string $type 媒体文件类型：image、voice、video、thumb
     * @param string|UploadedFile $media 媒体文件路径或UploadedFile对象
     * @param array $description 视频素材的描述（仅video类型需要title和description）
     * @return array
     */
    public function uploadMaterial(string $type, $media, array $description = []): array
    {
        try {
            // 验证参数
            if (!in_array($type, ['image', 'voice', 'video', 'thumb'])) {
                return [
                    'errcode' => -1,
                    'errmsg' => '不支持的素材类型，只支持: image, voice, video, thumb'
                ];
            }

            // 处理UploadedFile对象
            $filePath = $media instanceof UploadedFile ? $media->getPathname() : $media;
            
            $client = $this->app->createClient();
            
            // 构建请求数据
            $formData = [
                'type' => $type,
                'media' => fopen($filePath, 'r')
            ];
            
            // 视频类型需要额外的description参数
            if ($type === 'video') {
                if (empty($description['title']) || empty($description['description'])) {
                    return [
                        'errcode' => -1,
                        'errmsg' => '视频素材必须提供title和description'
                    ];
                }
                $formData['description'] = json_encode([
                    'title' => $description['title'],
                    'introduction' => $description['description']
                ]);
            }
            
            $response = $client->post('cgi-bin/material/add_material', [
                'body' => $formData
            ]);
            
            $result = $response->toArray();
            
            Log::info('上传永久素材成功', [
                'type' => $type,
                'media_id' => $result['media_id'] ?? null,
                'url' => $result['url'] ?? null
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('上传永久素材失败', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 上传图文消息内的图片
     * @param string|UploadedFile $image 图片文件
     * @return array
     */
    public function uploadArticleImage($image): array
    {
        try {
            $filePath = $image instanceof UploadedFile ? $image->getPathname() : $image;
            
            $client = $this->app->createClient();
            $response = $client->post('cgi-bin/media/uploadimg', [
                'body' => [
                    'media' => fopen($filePath, 'r')
                ]
            ]);
            
            $result = $response->toArray();
            
            Log::info('上传图文消息图片成功', [
                'url' => $result['url'] ?? null
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('上传图文消息图片失败', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 新增永久图文素材
     * @param array $articles 图文消息数组
     * @return array
     */
    public function uploadNews(array $articles): array
    {
        try {
            // 验证文章数量
            if (count($articles) > 8) {
                return [
                    'errcode' => -1,
                    'errmsg' => '图文消息最多支持8篇文章'
                ];
            }

            $client = $this->app->createClient();
            $response = $client->postJson('cgi-bin/material/add_news', [
                'articles' => $articles
            ]);
            
            $result = $response->toArray();
            
            Log::info('上传永久图文素材成功', [
                'article_count' => count($articles),
                'media_id' => $result['media_id'] ?? null
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('上传永久图文素材失败', [
                'article_count' => count($articles),
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 修改永久图文素材
     * @param string $mediaId 媒体文件ID
     * @param array $article 文章内容
     * @param int|null $index 要更新的文章在图文消息中的位置（多图文消息时使用，第一篇为0）
     * @return array
     */
    public function updateNews(string $mediaId, array $article, ?int $index = null): array
    {
        try {
            $client = $this->app->createClient();
            
            $requestData = [
                'media_id' => $mediaId,
                'index' => $index ?? 0,
                'articles' => $article
            ];
            
            $response = $client->postJson('cgi-bin/material/update_news', $requestData);
            $result = $response->toArray();
            
            Log::info('修改永久图文素材成功', [
                'media_id' => $mediaId,
                'index' => $index
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('修改永久图文素材失败', [
                'media_id' => $mediaId,
                'index' => $index,
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 删除永久素材
     * @param string $mediaId 媒体文件ID
     * @return array
     */
    public function deleteMaterial(string $mediaId): array
    {
        try {
            $client = $this->app->createClient();
            $response = $client->postJson('cgi-bin/material/del_material', [
                'media_id' => $mediaId
            ]);
            
            $result = $response->toArray();
            
            Log::info('删除永久素材成功', [
                'media_id' => $mediaId,
                'result' => $result
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('删除永久素材失败', [
                'media_id' => $mediaId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'errcode' => -1,
                'errmsg' => $e->getMessage()
            ];
        }
    }
}