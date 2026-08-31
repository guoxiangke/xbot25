<?php

use App\Services\Managers\ResourceChannelResolver;

it('maps numeric range channels (含 base+offset)', function (string $keyword, string $channel) {
    expect(ResourceChannelResolver::resolve($keyword))->toBe($channel);
})->with([
    // LyAudio 600 目录 + 601-699
    ['600', 'LyAudio'], ['654', 'LyAudio'], ['699', 'LyAudio'],
    // Febc 700 目录 + 701-714
    ['700', 'Febc'], ['701', 'Febc'], ['714', 'Febc'],
    // Lts 3/5 位、首位 2-5（前 3 位 200-599）
    ['200', 'Lts'], ['599', 'Lts'], ['20101', 'Lts'],
    // PastorLu 801/808（含 offset）
    ['801', 'PastorLu'], ['808', 'PastorLu'], ['80805', 'PastorLu'],
    // Ren 813-839（含 offset）
    ['813', 'Ren'], ['824', 'Ren'], ['830', 'Ren'], ['839', 'Ren'], ['82005', 'Ren'],
]);

it('maps exact-keyword channels', function (string $keyword, string $channel) {
    expect(ResourceChannelResolver::resolve($keyword))->toBe($channel);
})->with([
    ['802', 'PastorTsai'],
    ['805', 'PastorFei'],
    ['900', 'PastorJiang'],
    ['789', 'Fwd'], ['803', 'Fwd'], ['804', 'Fwd'], ['806', 'Fwd'],
    ['791', 'mbc'],
    ['781', 'Tpehoc'], ['793', 'Tpehoc'], ['799', 'Tpehoc'],
    ['783', 'BibleProject'], ['7830', 'BibleProject'], ['bibleproject', 'BibleProject'],
    ['782', 'Hland'],
    ['odb', 'OurDailyBread'],
]);

it('maps prefix channels', function (string $keyword, string $channel) {
    expect(ResourceChannelResolver::resolve($keyword))->toBe($channel);
})->with([
    ['hl46436', 'Hland'],
    ['t001', 'Tingdao'],
    ['t369001', 'Tingdao'],
    ['赞美耶稣', 'Zan'],
    ['赞美诗歌恩典', 'Zan'],
]);

it('returns null for keywords outside any configured channel', function (string $keyword) {
    expect(ResourceChannelResolver::resolve($keyword))->toBeNull();
})->with([
    ['715'],   // Febc 与其它之间的空档
    ['780'],   // 未分配
    ['850'],   // Ren 之外
    [''],      // 空串
    ['xyz'],   // 非受控前缀
]);
