<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WechatClient extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * 关联的微信机器人
     */
    public function wechatBots()
    {
        return $this->hasMany(WechatBot::class);
    }
}
