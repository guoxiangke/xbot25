<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Plank\Metable\Metable;
use App\Services\Clients\XbotClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WechatBot extends Model
{
    use HasFactory, Metable;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
    protected $dates = ['created_at', 'updated_at', 'deleted_at', 'login_at', 'is_live_at', 'expires_at'];
    protected $casts = [
        'login_at' => 'datetime',
        'is_live_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    use Metable;

    public function xbot($clientId=99){
        // 如果数据中存在，则从数据库中去，如果没有，从参数中取，如果还没有，给一个默认值1
        $clientId = $this->client_id??$clientId??-1;
        $wechatClient = WechatClient::where('id', $this->wechat_client_id)->firstOrFail();
        $winClientUri = $wechatClient->endpoint;
        return new XbotClient($winClientUri, $this->wxid, $clientId, $wechatClient->file_path ?? '');
    }

    public function wechatClient()
    {
        return $this->belongsTo(WechatClient::class);
    }



    // 返回 以wxid为key的联系人数组
    public function handleContacts($data){
        $contacts = $this->getMeta('contacts', []);
        foreach ($data as $contact){
            // 确保 $contact 是数组类型，跳过无效数据
            if (!is_array($contact) || !isset($contact['wxid'])) {
                continue;
            }

            // 确保头像URL使用https协议
            if (isset($contact['avatar'])) {
                $contact['avatar'] = str_replace('http://', 'https://', $contact['avatar']);
            }
            $contacts[$contact['wxid']] = $contact;
        }
        $this->setMeta('contacts', $contacts);
    }

    // 这样 $wechatBot->login_at 直接就是北京时间了。
    public function getLoginAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Asia/Shanghai');
    }

    /**
     * 获取联系人类型标签（通过type数字）
     */
    public static function getContactTypeLabel(int $type): string
    {
        $labels = [
            1 => '微信好友',
            2 => '微信群',
            3 => '微信订阅号'
        ];

        return $labels[$type] ?? '未知';
    }

    /**
     * 获取联系人类型标签（通过消息类型）
     */
    public static function getContactTypeLabelByMsgType(string $msgType): string
    {
        $labels = [
            'MT_DATA_FRIENDS_MSG' => '微信好友',
            'MT_DATA_CHATROOMS_MSG' => '微信群',
            'MT_DATA_PUBLICS_MSG' => '微信订阅号',
            'MT_ROOM_CREATE_NOTIFY_MSG' => '微信群',
        ];

        return $labels[$msgType] ?? '未知';
    }

    /**
     * 获取特殊联系人类型标签
     */
    public static function getSpecialContactLabel(string $type): string
    {
        $labels = [
            'robot' => '机器人微信',
            'stranger' => '群陌生人',
        ];

        return $labels[$type] ?? '未知';
    }

    // $table->timestamp('expires_at')->nullable()->default(now()->addMonth(1));
    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->expires_at)) {
                $model->expires_at = now()->addMonth();
            }
        });
    }

    /**
     * 敏感词过滤
     */
    private static function filterSensitiveWords(string $text): string
    {
        // 敏感词替换映射
        $sensitiveWords = [
            // '基督教' => 'JDJ',
            // 可以继续添加更多敏感词映射
        ];

        foreach ($sensitiveWords as $sensitive => $replacement) {
            $text = str_replace($sensitive, $replacement, $text);
        }

        return $text;
    }

    /**
     * 过滤description并限制字数
     */
    private static function filterDescription(string $description): string
    {
        // 先进行敏感词过滤
        $filtered = self::filterSensitiveWords($description);
        
        // 限制字数不超过30字（中文字符按1个字符计算）
        if (mb_strlen($filtered, 'UTF-8') > 30) {
            $filtered = mb_substr($filtered, 0, 30, 'UTF-8');
        }
        
        return $filtered;
    }

    /**
     * 发送资源消息到指定wxid列表
     */
    public function send(array $tos, array $resource): void
    {
        $xbot = $this->xbot($this->client_id);

        if (!isset($resource['data'])) {
            return;
        }

        $data = $resource['data'];
        $type = $resource['type'] ?? 'text';

        // 如果是关键词响应消息，添加标记
        if (isset($resource['is_keyword_response'])) {
            $data['_keyword_response'] = true;
        }

        foreach ($tos as $to) {
            switch ($type) {
                case 'text':
                    $content = self::filterSensitiveWords($data['content'] ?? '');
                    $xbot->sendTextMessage($to, $content);
                    break;
                case 'image':
                    if (isset($data['url'])) {
                        $xbot->sendImageByUrl($to, $data['url']);
                    }
                    break;
                case 'link':
                    $url = $data['url'] ?? '';
                    // 对包含统计信息的链接添加重定向，但仅限于r2share域名的.mp4链接
                    if (isset($resource['statistics']) &&
                        str_contains($url, 'r2share') &&
                        str_ends_with($url, '.mp4')) {
                        $resource['statistics']['bot'] = $this->id;
                        $tags = http_build_query($resource['statistics'], '', '%26');
                        $url = config('services.xbot.redirect') . urlencode($data['url']) . "?" . $tags . '%26to=' . $to;
                    }
                    $title = self::filterSensitiveWords($data['title'] ?? '');
                    $description = self::filterDescription($data['description'] ?? '');
                    $xbot->sendLink($to, $url, $title, $description, $data['image'] ?? '');
                    break;
                case 'music':
                    $url = $data['url'] ?? '';
                    if (isset($resource['statistics'])) {
                        $resource['statistics']['bot'] = $this->id;
                        $tags = http_build_query($resource['statistics'], '', '%26');
                        $url = config('services.xbot.redirect') . urlencode($data['url']) . "?" . $tags . '%26to=' . $to;
                    }
                    $title = self::filterSensitiveWords($data['title'] ?? '');
                    $description = self::filterDescription($data['description'] ?? '');
                    $xbot->sendMusic($to, $url, $title, $description, $data['image'] ?? null, $data['lrc'] ?? null);
                    break;
                default:
                    Log::warning('Unknown resource type', ['type' => $type]);
            }
        }
    }

    /**
     * 发送资源及其所有附加内容到指定wxid列表
     * 统一处理主要内容和递归附加内容，确保KeywordResponseHandler和TriggerSubscriptionCommand行为一致
     */
    public function sendResourceWithAdditions(array $tos, array $resource): void
    {
        // 标记为关键词响应消息
        $resource['is_keyword_response'] = true;

        // 发送主要内容
        $this->send($tos, $resource);

        // 递归发送所有附加内容
        $this->sendAdditions($tos, $resource);
    }

    /**
     * 递归发送附加内容
     * 从KeywordResponseHandler移动到此处，统一管理
     */
    private function sendAdditions(array $tos, array $resource): void
    {
        if (isset($resource['addition'])) {
            $addition = $resource['addition'];

            // 标记为关键词响应消息
            $addition['is_keyword_response'] = true;

            // 发送当前附加内容
            $this->send($tos, $addition);

            // 递归处理嵌套的附加内容
            $this->sendAdditions($tos, $addition);
        }
    }

    /**
     * 获取关键词对应的资源
     * 从KeywordResponseHandler移动到此处，以便订阅系统复用
     */
    public function getResouce($keyword){
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
     * 订阅关联关系
     */
    public function subscriptions()
    {
        return $this->hasMany(XbotSubscription::class);
    }

    /**
     * 检查机器人是否在线
     */
    public function isLive(): bool
    {
        $this->xbot()->getSelfInfo();
        sleep(5);
        $this->refresh();

        if ($this->is_live_at && $this->is_live_at->diffInMinutes() > 1) {
            Log::error(__CLASS__, [
                'function' => __FUNCTION__,
                'message' => 'XbotIsLive 程序崩溃时,已下线！',
                'wxid' => $this->wxid,
                'client_id' => $this->client_id,
                'name' => $this->name
            ]);

            $this->login_at = null;
            $this->is_live_at = null;
            $this->client_id = null;
            $this->save();

            return false;
        }

        return true;
    }

}
