<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WechatClient extends Model
{
    use HasFactory;
    
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * 关联的微信机器人
     */
    public function wechatBots()
    {
        return $this->hasMany(WechatBot::class);
    }
}
