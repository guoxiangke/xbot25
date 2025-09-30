<?php

namespace App\Services;

use App\Services\Managers\ConfigManager;
use Carbon\Carbon;

/**
 * 打卡功能时区处理统一工具类
 * 
 * 新设计原则：
 * 1. 存储时间：使用系统时区（UTC）存储实际打卡时间（created_at字段）
 * 2. 判断逻辑：使用群时区配置判断created_at是否在"今天"范围内
 * 3. 统计排名：使用群时区配置进行日期计算
 */
class TimezoneHelper
{
    /**
     * 根据群时区配置获取今日的时间范围（UTC时区）
     * 用于查询今天是否已经打卡
     * 
     * @param mixed $wechatBot
     * @param string $roomWxid
     * @return array [startOfDay, endOfDay] UTC时区的今日开始和结束时间
     */
    public static function getTodayRangeInUtc($wechatBot, string $roomWxid): array
    {
        $configManager = new ConfigManager($wechatBot);
        
        // 获取群的时区配置，默认为 +8 (Asia/Shanghai)
        $timezoneOffset = $configManager->getGroupConfig('room_timezone_special', $roomWxid, 8);
        
        // 计算群时区的当前时间
        $now = Carbon::now('UTC');
        $groupTimezoneNow = $now->copy()->addHours($timezoneOffset);
        
        // 获取群时区今日的开始和结束时间
        $todayStartInGroupTz = $groupTimezoneNow->copy()->startOfDay(); // 例如：2025-10-01 00:00:00 +08:00
        $todayEndInGroupTz = $groupTimezoneNow->copy()->endOfDay();     // 例如：2025-10-01 23:59:59 +08:00
        
        // 转换为UTC时区用于数据库查询
        $todayStartUtc = $todayStartInGroupTz->copy()->subHours($timezoneOffset);
        $todayEndUtc = $todayEndInGroupTz->copy()->subHours($timezoneOffset);
        
        return [$todayStartUtc, $todayEndUtc];
    }
    
    /**
     * 根据群时区配置获取今日的日期字符串
     * 
     * @param mixed $wechatBot
     * @param string $roomWxid  
     * @return string 群时区的今日日期字符串 YYYY-MM-DD
     */
    public static function getTodayDateString($wechatBot, string $roomWxid): string
    {
        $configManager = new ConfigManager($wechatBot);
        
        // 获取群的时区配置，默认为 +8 (Asia/Shanghai)
        $timezoneOffset = $configManager->getGroupConfig('room_timezone_special', $roomWxid, 8);
        
        // 计算群时区的当前时间和今日日期
        $now = Carbon::now('UTC');
        $groupTimezoneNow = $now->copy()->addHours($timezoneOffset);
        
        return $groupTimezoneNow->toDateString();
    }
    
    /**
     * 将UTC存储的时间转换为群时区的日期字符串
     * 
     * @param Carbon $utcDateTime
     * @param mixed $wechatBot
     * @param string $roomWxid
     * @return string 群时区的日期字符串 YYYY-MM-DD
     */
    public static function utcToGroupTimezoneDate(Carbon $utcDateTime, $wechatBot, string $roomWxid): string
    {
        $configManager = new ConfigManager($wechatBot);
        
        // 获取群的时区配置，默认为 +8 (Asia/Shanghai)  
        $timezoneOffset = $configManager->getGroupConfig('room_timezone_special', $roomWxid, 8);
        
        // 将UTC时间转换为群时区时间
        $groupTimezoneDateTime = $utcDateTime->copy()->addHours($timezoneOffset);
        
        return $groupTimezoneDateTime->toDateString();
    }
    
    /**
     * 检查UTC时间是否在群时区的今日范围内
     * 
     * @param Carbon $utcDateTime
     * @param mixed $wechatBot
     * @param string $roomWxid
     * @return bool
     */
    public static function isTimeInTodayRange(Carbon $utcDateTime, $wechatBot, string $roomWxid): bool
    {
        [$todayStart, $todayEnd] = self::getTodayRangeInUtc($wechatBot, $roomWxid);
        
        return $utcDateTime->between($todayStart, $todayEnd);
    }
    
    /**
     * 获取时区偏移量
     * 
     * @param mixed $wechatBot
     * @param string $roomWxid
     * @return int 时区偏移量（小时）
     */
    public static function getTimezoneOffset($wechatBot, string $roomWxid): int
    {
        $configManager = new ConfigManager($wechatBot);
        return $configManager->getGroupConfig('room_timezone_special', $roomWxid, 8);
    }
}