<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\WechatSendRequest;
use App\Http\Requests\WechatAddFriendRequest;
use App\Models\WechatBot;
use App\Services\Clients\XbotClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * WeChat API 控制器
 * 
 * 提供微信自动化相关的API接口，支持：
 * - 发送多种类型消息（文本、@消息、链接、名片、图片、音乐）
 * - 添加好友
 * - 获取好友列表
 * 
 * 所有接口都需要 auth:sanctum 身份验证
 * 用户必须绑定在线的WeChat设备才能使用
 * 
 * @package App\Http\Controllers
 */
class WechatController extends Controller
{
    /**
     * 统一的成功响应格式
     */
    private function successResponse(string $message = '操作成功', array $data = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    /**
     * 统一的错误响应格式
     */
    private function errorResponse(string $message, int $code = 400, array $data = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => $data
        ], $code >= 500 ? 500 : 200); // 5xx错误返回HTTP错误状态码
    }

    /**
     * 获取用户绑定的在线机器人
     */
    private function getOnlineWechatBot(): ?WechatBot
    {
        $bindUserId = auth()->id();
        if (!$bindUserId) {
            return null;
        }

        return WechatBot::where('user_id', $bindUserId)
            ->whereNotNull('client_id')
            ->whereNotNull('is_live_at')
            ->first();
    }

    /**
     * 发送消息的统一处理方法
     */
    private function sendMessage(XbotClient $xbot, string $type, array $data, string $to): void
    {
        switch ($type) {
            case 'text':
                $xbot->sendTextMessage($to, $data['content']);
                break;
                
            case 'at':
                $xbot->sendAtMessage($to, $data['content'], $data['at']);
                break;
                
            case 'link':
                $xbot->sendLink(
                    $to,
                    $data['url'],
                    $data['title'] ?? '',
                    $data['description'] ?? '',
                    $data['image'] ?? ''
                );
                break;
                
            case 'card':
                $xbot->sendContactCard($to, $data['wxid']);
                break;
                
            case 'image':
                $xbot->sendImageByUrl($to, $data['url']);
                break;
                
            case 'music':
                $xbot->sendMusic(
                    $to,
                    $data['url'],
                    $this->cleanHtmlTags($data['title'] ?? ''),
                    $this->cleanHtmlTags($data['description'] ?? ''),
                    $data['coverUrl'] ?? null,
                    $data['lyrics'] ?? null
                );
                break;

            // 朋友圈发布操作
            case 'postLink':
                $xbot->publishLinkToMoments(
                    $data['title'],
                    $data['url'],
                    $data['comment'] ?? ''
                );
                break;

            case 'postImages':
                $xbot->publishImagesToMoments(
                    $data['title'],
                    $data['urls']
                );
                break;

            case 'postVideo':
                $xbot->publishVideoToMoments(
                    $data['title'],
                    $data['url'],
                    $data['thumbnailUrl'] ?? null
                );
                break;

            case 'postMusic':
                $xbot->publishMusicToMoments(
                    $this->cleanHtmlTags($data['title']),
                    $data['url'],
                    $this->cleanHtmlTags($data['description']),
                    $this->cleanHtmlTags($data['comment'] ?? ''),
                    $data['thumbImgUrl'] ?? null
                );
                break;

            case 'postQQMusic':
                $xbot->publishQQMusicToMoments(
                    $this->cleanHtmlTags($data['title']),
                    $data['url'],
                    $data['musicUrl'],
                    $data['appInfo'] ?? null
                );
                break;
                
            default:
                throw new \InvalidArgumentException("不支持的消息类型: {$type}");
        }
    }

    /**
     * 发送微信消息
     * 
     * 支持发送多种类型的消息：
     * - text: 文本消息
     * - at: @消息（群聊中@指定成员）
     * - link: 链接消息
     * - card: 名片消息
     * - image: 图片消息
     * - music: 音乐消息
     * - postLink: 发布链接到朋友圈
     * - postImages: 发布图片到朋友圈（支持九宫格）
     * - postVideo: 发布视频到朋友圈
     * - postMusic: 发布音乐到朋友圈
     * - postQQMusic: 发布QQ音乐到朋友圈
     * 
     * 可选的 addition 字段支持发送附加消息
     * 
     * @param WechatSendRequest $request 验证过的请求数据
     * @return JsonResponse
     * 
     * @example 
     * POST /api/wechat/send
     * {
     *   "type": "text",
     *   "to": "friend_wxid",
     *   "data": {"content": "Hello"}
     * }
     */
    public function send(WechatSendRequest $request): JsonResponse
    {
        try {
            $wechatBot = $this->getOnlineWechatBot();
            
            if (!$wechatBot) {
                if (!auth()->id()) {
                    return $this->errorResponse('用户绑定数据认证失败！请检查/重新生成token', 401);
                }
                return $this->errorResponse('设备不在线,或改用户未绑定设备', 400);
            }

            $xbot = $wechatBot->xbot();
            
            // 发送主消息
            $this->sendMessage($xbot, $request['type'], $request['data'], $request['to']);

            // 发送附加消息（如果存在）
            if (isset($request['addition'])) {
                $this->sendMessage(
                    $xbot, 
                    $request['addition']['type'], 
                    $request['addition']['data'], 
                    $request['to']
                );
            }

            return $this->successResponse('已提交设备发送');
            
        } catch (\Exception $e) {
            Log::error('WeChat send message failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
                'user_id' => auth()->id()
            ]);
            
            return $this->errorResponse('消息发送失败，请稍后重试', 500);
        }
    }

    /**
     * 添加微信好友
     * 
     * 通过手机号搜索并添加好友
     * 支持自定义验证消息
     * 
     * @param WechatAddFriendRequest $request 验证过的请求数据
     * @return JsonResponse
     * 
     * @example
     * POST /api/wechat/add
     * {
     *   "telephone": "13800138000",
     *   "message": "Hello, nice to meet you"
     * }
     */
    public function add(WechatAddFriendRequest $request): JsonResponse
    {
        try {
            $wechatBot = WechatBot::where('user_id', auth()->id())
                ->whereNotNull('client_id')
                ->whereNotNull('login_at')
                ->first();

            if (!$wechatBot) {
                return $this->errorResponse('设备不在线', 400);
            }

            $xbot = $wechatBot->xbot();
            
            // 先搜索联系人
            $searchResult = $xbot->searchContact($request['telephone']);
            
            // 然后添加为好友
            $result = $xbot->addSearchedContactAsFriend(
                $request['telephone'],
                $request['message'] ?? "Hi"
            );
            
            // 检查XbotClient返回结果
            if (is_array($result) && isset($result['success']) && !$result['success']) {
                return $this->errorResponse($result['message'] ?? '添加好友失败', 400);
            }
            
            return $this->successResponse('好友添加请求已发送');
            
        } catch (\Exception $e) {
            Log::error('WeChat add friend failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
                'user_id' => auth()->id()
            ]);
            
            return $this->errorResponse('添加好友失败，请稍后重试', 500);
        }
    }

    /**
     * 获取好友列表
     * 
     * 返回当前用户绑定机器人的所有好友联系人
     * 只返回类型为好友的联系人（type=1），不包含群聊
     * 
     * @return JsonResponse
     * 
     * @example
     * GET /api/wechat/friends
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "获取好友列表成功",
     *   "data": [
     *     {
     *       "wxid": "friend_wxid_1",
     *       "nickname": "好友1",
     *       "remark": "备注1",
     *       "type": 1
     *     }
     *   ]
     * }
     */
    public function getFriends(): JsonResponse
    {
        try {
            $wechatBot = WechatBot::where('user_id', auth()->id())->first();
            
            if (!$wechatBot) {
                return $this->errorResponse('用户未绑定设备', 400);
            }

            $contacts = $wechatBot->getMeta('contacts', []);
            
            // 过滤出好友联系人（假设type=1是好友）
            $friends = array_filter($contacts, function($contact) {
                return ($contact['type'] ?? 0) == 1;
            });

            return $this->successResponse('获取好友列表成功', array_values($friends));
            
        } catch (\Exception $e) {
            Log::error('WeChat get friends failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);
            
            return $this->errorResponse('获取好友列表失败，请稍后重试', 500);
        }
    }

    /**
     * 清理HTML标签，移除样式标签和其他HTML元素
     * 
     * @param string $text
     * @return string
     */
    private function cleanHtmlTags(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // 移除所有HTML标签，包括样式标签
        $cleanText = strip_tags($text);
        
        // 解码HTML实体（在strip_tags之后，避免将&lt;&gt;解码后被当作标签移除）
        $cleanText = html_entity_decode($cleanText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 去除多余的空白字符（包括换行符、制表符、不间断空格等）
        $cleanText = preg_replace('/[\s\x{00A0}]+/u', ' ', $cleanText);
        
        // 去除首尾空格
        return trim($cleanText);
    }
}