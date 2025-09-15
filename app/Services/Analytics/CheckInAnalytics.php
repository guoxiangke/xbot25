<?php

namespace App\Services\Analytics;

use App\Models\CheckIn;
use Carbon\Carbon;

/**
 * 签到数据分析服务
 * 提供签到统计、排行榜等分析功能
 */
class CheckInAnalytics
{
    protected $wxid;
    protected $wxRoom;
    protected $contacts;

    public function __construct(string $wxid, string $wxRoom, array $contacts)
    {
        $this->wxid = $wxid;
        $this->wxRoom = $wxRoom;
        $this->contacts = $contacts;
    }

    public function getPersonalStats(): array
    {
        $dates = CheckIn::where('wxid', $this->wxid)
            ->where('content', $this->wxRoom)
            ->orderBy('check_in_at')
            ->pluck('check_in_at')
            ->map(fn($dt) => Carbon::parse($dt)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return [
                'total_days' => 0,
                'current_streak' => 0,
                'missed_days' => 0,
                'missed_percentage' => '0.00',
                'max_streak' => 0,
                'missed_dates' => [],
                'rank' => 0,
            ];
        }

        $firstDate = Carbon::parse($dates->first());
        $lastDate = Carbon::parse($dates->last());
        $totalRangeDays = $firstDate->diffInDays($lastDate) + 1;
        $totalDays = $dates->count();

        $missedDays = $totalRangeDays - $totalDays;
        $missedPercentage = number_format(($missedDays / $totalRangeDays) * 100, 2);

        $currentStreak = $this->calculateCurrentStreak($dates);
        $maxStreak = $this->calculateMaxStreak($dates);

        return [
            'total_days' => $totalDays,
            'current_streak' => $currentStreak,
            'missed_days' => $missedDays,
            'missed_percentage' => $missedPercentage,
            'max_streak' => $maxStreak,
            'missed_dates' => $this->getMissedDates($dates),
            'rank' => $this->getTodayRank(),
        ];
    }

    public function getTodayRank(): int
    {
        $today = now()->startOfDay();
        return CheckIn::whereDate('check_in_at', $today)
            ->where('content', $this->wxRoom)
            ->count();
    }

    public function getTotalDaysRanking($limit = 10): array
    {
        $contacts = $this->contacts;
        
        return CheckIn::select('wxid')
            ->where('content', $this->wxRoom)
            ->selectRaw('COUNT(DISTINCT DATE(check_in_at)) as total_days')
            ->groupBy('wxid')
            ->orderByDesc('total_days')
            ->limit($limit)
            ->get()
            ->map(function ($item, $index) use ($contacts) {
                $nickname = $this->getNicknameFromContacts($item->wxid, $contacts);
                return [
                    'rank' => $index + 1,
                    'wxid' => $item->wxid,
                    'nickname' => $nickname,
                    'total_days' => $item->total_days,
                ];
            })
            ->toArray();
    }

    public function getCurrentStreakRanking($limit = 10): array
    {
        $contacts = $this->contacts;
        
        $allUsers = CheckIn::select('wxid')
            ->where('content', $this->wxRoom)
            ->groupBy('wxid')
            ->get();

        $userStreaks = [];

        foreach ($allUsers as $user) {
            $currentStreak = $this->calculateUserCurrentStreak($user->wxid);
            if ($currentStreak > 0) {
                $nickname = $this->getNicknameFromContacts($user->wxid, $contacts);
                $userStreaks[] = [
                    'wxid' => $user->wxid,
                    'nickname' => $nickname,
                    'current_streak' => $currentStreak,
                ];
            }
        }

        usort($userStreaks, function ($a, $b) {
            return $b['current_streak'] <=> $a['current_streak'];
        });

        $ranking = array_slice($userStreaks, 0, $limit);
        foreach ($ranking as $index => &$item) {
            $item['rank'] = $index + 1;
        }

        return $ranking;
    }

    /**
     * 从联系人数据中获取昵称
     */
    protected function getNicknameFromContacts(string $wxid, array $contacts): string
    {
        if (isset($contacts[$wxid])) {
            $contact = $contacts[$wxid];
            return $contact['nickname'] ?? $contact['remark'] ?? $wxid;
        }
        return $wxid; // 如果在联系人中找不到，使用wxid作为昵称
    }

    protected function calculateCurrentStreak($dates): int
    {
        $currentStreak = 0;
        $tempStreak = 0;
        $prevDate = null;

        foreach ($dates as $date) {
            $dateObj = Carbon::parse($date);
            if ($prevDate && $prevDate->copy()->addDay()->isSameDay($dateObj)) {
                $tempStreak++;
            } else {
                $tempStreak = 1;
            }
            $currentStreak = $tempStreak;
            $prevDate = $dateObj;
        }

        return $currentStreak;
    }

    protected function calculateMaxStreak($dates): int
    {
        $maxStreak = 0;
        $currentStreak = 0;
        $prevDate = null;

        foreach ($dates as $date) {
            $dateObj = Carbon::parse($date);
            if ($prevDate && $prevDate->copy()->addDay()->isSameDay($dateObj)) {
                $currentStreak++;
            } else {
                $currentStreak = 1;
            }
            if ($currentStreak > $maxStreak) {
                $maxStreak = $currentStreak;
            }
            $prevDate = $dateObj;
        }

        return $maxStreak;
    }

    protected function calculateUserCurrentStreak(string $wxid): int
    {
        $dates = CheckIn::where('wxid', $wxid)
            ->where('content', $this->wxRoom)
            ->orderBy('check_in_at')
            ->pluck('check_in_at')
            ->map(fn($dt) => Carbon::parse($dt)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) return 0;

        return $this->calculateCurrentStreak($dates);
    }

    protected function getMissedDates($dates): array
    {
        if ($dates->isEmpty()) return [];

        $firstDate = Carbon::parse($dates->first());
        $lastDate = Carbon::parse($dates->last());
        $allDates = collect();

        for ($date = $firstDate->copy(); $date->lte($lastDate); $date->addDay()) {
            $allDates->push($date->toDateString());
        }

        $missedDates = $allDates->diff($dates)->values();

        return $missedDates->all();
    }

    public function getStatusComment($stats): string
    {
        $currentStreak = $stats['current_streak'];
        $missedPercentage = floatval($stats['missed_percentage']);

        // 连续天数评语
        if ($currentStreak >= 30) {
            $streakComment = "🌟 坚持王者！连续打卡超过30天！";
        } elseif ($currentStreak >= 14) {
            $streakComment = "🚀 习惯养成中！连续打卡超过2周！";
        } elseif ($currentStreak >= 7) {
            $streakComment = "📈 状态不错！连续打卡1周了！";
        } elseif ($currentStreak >= 3) {
            $streakComment = "💪 继续加油！保持连续打卡！";
        } elseif ($currentStreak >= 1) {
            $streakComment = "🌱 刚刚开始，加油坚持！";
        } else {
            $streakComment = "😴 今天还没打卡哦~";
        }

        // 出勤率评语
        if ($missedPercentage == 0) {
            $attendanceComment = "完美全勤！";
        } elseif ($missedPercentage <= 10) {
            $attendanceComment = "出勤率很棒！";
        } elseif ($missedPercentage <= 20) {
            $attendanceComment = "出勤率良好~";
        } elseif ($missedPercentage <= 30) {
            $attendanceComment = "还有提升空间哦~";
        } else {
            $attendanceComment = "要更加努力坚持打卡！";
        }

        return $streakComment . " " . $attendanceComment;
    }

    public function getRankIcon($rank): string
    {
        switch ($rank) {
            case 1:
                return '🥇';
            case 2:
                return '🥈';
            case 3:
                return '🥉';
            default:
                return '🏅';
        }
    }
}