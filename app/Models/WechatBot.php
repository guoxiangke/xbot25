<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Plank\Metable\Metable;
use App\Services\Xbot;
use Carbon\Carbon;//这样 $wechatBot->login_at 直接就是北京时间了。

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

    public function initContacts(){
        $xbot = $this->xbot();

        $xbot->getFriendsList();
        sleep(1);
        $xbot->getChatroomsList();
        sleep(1);
        $xbot->getPublicAccountsList();
    }


    public function handleContactsInit($data){
        $contacts = $this->getMeta('contacts', []);
        foreach ($data as $contact){
            $contacts[$contact['wxid']] = $contact;
        }
        $this->setMeta('contacts', $contacts);
    }

    public function getLoginAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Asia/Shanghai');
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
}
