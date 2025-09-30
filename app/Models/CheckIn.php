<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CheckIn extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];
    
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    
    /**
     * 获取打卡时间（复用 created_at 字段）
     */
    public function getCheckInTimeAttribute()
    {
        return $this->created_at;
    }
    
    /**
     * 获取群聊房间ID（新字段名 chatroom）
     */
    public function getRoomWxidAttribute()
    {
        return $this->chatroom;
    }
}