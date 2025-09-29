<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\EasyWeChatController;
use App\Services\WeChat\PermanentMaterialService;
use Illuminate\Support\Facades\Validator;

/**
 * 微信永久素材管理控制器
 */
class MaterialController extends Controller
{
    /**
     * 获取永久素材服务实例
     */
    private function getMaterialService(): PermanentMaterialService
    {
        $wechatController = new EasyWeChatController();
        $app = $wechatController->getApp();
        return new PermanentMaterialService($app);
    }

    /**
     * 获取永久素材
     * GET /api/materials/{mediaId}
     */
    public function show(string $mediaId): JsonResponse
    {
        $service = $this->getMaterialService();
        $result = $service->getMaterial($mediaId);

        return response()->json($result);
    }

    /**
     * 获取永久素材总数
     * GET /api/materials/stats
     */
    public function stats(): JsonResponse
    {
        $service = $this->getMaterialService();
        $result = $service->getMaterialCount();

        return response()->json($result);
    }

    /**
     * 获取永久素材列表
     * GET /api/materials?type=image&offset=0&count=20
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:image,voice,video,news',
            'offset' => 'integer|min:0',
            'count' => 'integer|min:1|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errcode' => -1,
                'errmsg' => '参数错误: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $type = $request->get('type');
        $offset = $request->get('offset', 0);
        $count = $request->get('count', 20);

        $service = $this->getMaterialService();
        $result = $service->getMaterialList($type, $offset, $count);

        return response()->json($result);
    }

    /**
     * 上传永久素材
     * POST /api/materials
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:image,voice,video,thumb',
            'media' => 'required|file',
            'title' => 'required_if:type,video|string|max:255',
            'description' => 'required_if:type,video|string|max:1000'  // 修正字段名
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errcode' => -1,
                'errmsg' => '参数错误: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $type = $request->get('type');
        $media = $request->file('media');
        
        $description = [];
        if ($type === 'video') {
            $description = [
                'title' => $request->get('title'),
                'description' => $request->get('description')  // 修正字段名
            ];
        }

        $service = $this->getMaterialService();
        $result = $service->uploadMaterial($type, $media, $description);

        return response()->json($result);
    }

    /**
     * 上传图文消息内的图片
     * POST /api/materials/article-image
     */
    public function uploadArticleImage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|file|image|max:10240' // 最大10MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errcode' => -1,
                'errmsg' => '参数错误: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $image = $request->file('image');
        $service = $this->getMaterialService();
        $result = $service->uploadArticleImage($image);

        return response()->json($result);
    }

    /**
     * 新增永久图文素材
     * POST /api/materials/news
     */
    public function storeNews(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'articles' => 'required|array|min:1|max:8',
            'articles.*.title' => 'required|string|max:64',
            'articles.*.author' => 'string|max:64',
            'articles.*.digest' => 'string|max:120',
            'articles.*.content' => 'required|string',
            'articles.*.content_source_url' => 'string|url',
            'articles.*.thumb_media_id' => 'required|string',
            'articles.*.show_cover_pic' => 'boolean',
            'articles.*.need_open_comment' => 'boolean',
            'articles.*.only_fans_can_comment' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errcode' => -1,
                'errmsg' => '参数错误: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $articles = $request->get('articles');
        $service = $this->getMaterialService();
        $result = $service->uploadNews($articles);

        return response()->json($result);
    }

    /**
     * 修改永久图文素材
     * PUT /api/materials/news/{mediaId}
     */
    public function updateNews(Request $request, string $mediaId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'article' => 'required|array',
            'article.title' => 'required|string|max:64',
            'article.author' => 'string|max:64',
            'article.digest' => 'string|max:120',
            'article.content' => 'required|string',
            'article.content_source_url' => 'string|url',
            'article.thumb_media_id' => 'required|string',
            'article.show_cover_pic' => 'boolean',
            'article.need_open_comment' => 'boolean',
            'article.only_fans_can_comment' => 'boolean',
            'index' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errcode' => -1,
                'errmsg' => '参数错误: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 400);
        }

        $article = $request->get('article');
        $index = $request->get('index'); // 现在是可选的
        
        $service = $this->getMaterialService();
        $result = $service->updateNews($mediaId, $article, $index);

        return response()->json($result);
    }

    /**
     * 删除永久素材
     * DELETE /api/materials/{mediaId}
     */
    public function destroy(string $mediaId): JsonResponse
    {
        $service = $this->getMaterialService();
        $result = $service->deleteMaterial($mediaId);

        return response()->json($result);
    }
}