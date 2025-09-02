<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class XbotSubscription extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    protected $dates = ['deleted_at'];

    /**
     * 所属的微信机器人
     */
    public function wechatBot()
    {
        return $this->belongsTo(WechatBot::class);
    }

    /**
     * 根据微信机器人ID和联系人wxid查找订阅
     */
    public static function findByBotAndWxid($wechatBotId, $wxid, $keyword)
    {
        return static::query()
            ->where('wechat_bot_id', $wechatBotId)
            ->where('wxid', $wxid)
            ->where('keyword', $keyword)
            ->first();
    }

    /**
     * 根据微信机器人ID和联系人wxid查找订阅（包含已删除的）
     */
    public static function findByBotAndWxidWithTrashed($wechatBotId, $wxid, $keyword)
    {
        return static::withTrashed()
            ->where('wechat_bot_id', $wechatBotId)
            ->where('wxid', $wxid)
            ->where('keyword', $keyword)
            ->first();
    }

    /**
     * 创建或恢复订阅
     */
    public static function createOrRestore($wechatBotId, $wxid, $keyword, $cron = '0 7 * * *')
    {
        $subscription = static::withTrashed()->firstOrCreate(
            [
                'wechat_bot_id' => $wechatBotId,
                'wxid' => $wxid,
                'keyword' => $keyword,
            ],
            [
                'cron' => $cron
            ]
        );

        // 如果是软删除的记录，恢复它
        if ($subscription->trashed()) {
            $subscription->restore();
        }

        return $subscription;
    }
}
