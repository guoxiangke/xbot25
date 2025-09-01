<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Plank\Metable\Metable;
use App\Services\Xbot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WechatBot extends Model
{
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
        return new Xbot($winClientUri, $this->wxid, $clientId, $wechatClient->file_path);
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

    public function getResouce($keyword){
        $cacheKey = "resources.{$keyword}";
        return Cache::remember($cacheKey, strtotime('tomorrow') - time(), function() use ($keyword) {
            $response = Http::get(config('services.xbot.resource_endpoint')."{$keyword}");
            if($response->ok() && $data = $response->json()){
                if(isset($data['statistics'])){
                    $data['data']['statistics'] = $data['statistics'];
                    unset($data['statistics']);
                }
                return $data;
            }
            return false;
        });
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
        
        foreach ($tos as $to) {
            switch ($type) {
                case 'text':
                    $xbot->sendText($to, $data['content'] ?? '');
                    break;
                case 'image':
                    if (isset($data['url'])) {
                        $xbot->sendImageUrl($to, $data['url']);
                    }
                    break;
                case 'link':
                    $url = $data['url'] ?? '';
                    if (isset($data['statistics'])) {
                        $data['statistics']['bot'] = $this->id;
                        $tags = http_build_query($data['statistics'], '', '%26');
                        $url = config('services.xbot.redirect') . urlencode($data['url']) . "?" . $tags . '%26to=' . $to;
                    }
                    $xbot->sendLink($to, $url, $data['image'] ?? '', $data['title'] ?? '', $data['description'] ?? '');
                    break;
                case 'music':
                    $url = $data['url'] ?? '';
                    if (isset($data['statistics'])) {
                        $data['statistics']['bot'] = $this->id;
                        $tags = http_build_query($data['statistics'], '', '%26');
                        $url = config('services.xbot.redirect') . urlencode($data['url']) . "?" . $tags . '%26to=' . $to;
                    }
                    $xbot->sendMusic($to, $url, $data['title'] ?? '', $data['description'] ?? '', $data['image'] ?? null, $data['lrc'] ?? null);
                    break;
                default:
                    Log::warning('Unknown resource type', ['type' => $type]);
            }
        }
    }

}
