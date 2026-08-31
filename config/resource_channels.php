<?php

/**
 * 资源频道 → 关键词规则映射（本地表，用于「请求 xlaravel 前」判断关键词属于哪个频道）
 *
 * - 键为频道名，须与 xlaravel handler 响应的 statistics.metric 一致。
 * - 每个频道可配置 keywords（整串精确）、ranges（前 3 位数值区间）、prefixes（整串前缀）。
 * - 命中且该频道对当前 bot 被禁用（/set keyword_resources_{频道} 0）时，直接不发起资源请求。
 *
 * 说明：ranges 按关键词前 3 位匹配，可兼容 base+offset 关键词（如 PastorLu 80805 → 808、
 *       Ren 82005 → 820、Lts 20101 → 201）。各频道关键词空间互斥，无重叠。
 */
return [
    // 良友电台节目：600 目录 + 601-699 节目
    'LyAudio' => ['ranges' => [[600, 699]]],

    // FEBC 节目：700 目录 + 701-714 节目
    'Febc' => ['ranges' => [[700, 714]]],

    // 良友圣经学院：3/5 位、首位 2-5（前 3 位 200-599）
    'Lts' => ['ranges' => [[200, 599]]],

    // 卢牧师：801 每日金句、808 读新约（含 offset）
    'PastorLu' => ['ranges' => [[801, 801], [808, 808]]],

    // 任牧师：813-824 每日节目、830-839 系列（含 offset）
    'Ren' => ['ranges' => [[813, 839]]],

    // 蔡牧师 802 / 费牧师 805 / 江牧师 900
    'PastorTsai' => ['keywords' => ['802']],
    'PastorFei' => ['keywords' => ['805']],
    'PastorJiang' => ['keywords' => ['900']],

    // 复兴华人：789/803/804/806
    'Fwd' => ['keywords' => ['789', '803', '804', '806']],

    // 慕安德烈每日灵修签到（Beta 内 metric=mbc）
    'mbc' => ['keywords' => ['791']],

    // 台北灵粮堂系列（Beta 内 metric=Tpehoc）
    'Tpehoc' => ['keywords' => ['781', '785', '792', '793', '794', '795', '797', '798', '799']],

    // BibleProject：783 / 7830 / bibleproject
    'BibleProject' => ['keywords' => ['783', '7830', 'bibleproject']],

    // 恒河（Hland）：hl 前缀 + 782
    'Hland' => ['keywords' => ['782'], 'prefixes' => ['hl']],

    // 听道（Tingdao）：t 前缀（t001…）
    'Tingdao' => ['prefixes' => ['t']],

    // 赞美诗网（Zan）：赞美诗网/赞美诗歌/赞美诗/赞美/赞
    'Zan' => ['prefixes' => ['赞']],

    // 每日灵粮（OurDailyBread）
    'OurDailyBread' => ['keywords' => ['odb']],

    // 注：YouTube 链接由 KeywordResponseHandler 的 YouTube 分支单独按频道名 'youtube' 门控，
    //     不经此表（URL/@频道 不在此匹配）。
];
