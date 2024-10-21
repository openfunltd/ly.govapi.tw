<?php

$func_move_bill = function(){
    if (file_exists(__DIR__ . '/bill/output.jsonl')) {
        rename(__DIR__ . '/bill/output.jsonl', __DIR__ . '/bill/output.jsonl.' . date('Ymd', filemtime(__DIR__ . '/bill/output.jsonl')));
    }
};

putenv('year=' . date('Y') - 1);
putenv('term=' . (floor((date('Y') - 2024) / 4) + 11));

$cmds = [
    /*
     */
    // 會議相關
    ['php meet/crawl-meet.php', '抓取會議資料'],
    ['php meet/parse-meet-from-gazette.php', '從公報抓取院會議事錄'],
    ['php meet/crawl-meet-proceeding.php', '抓取委員會議事錄並轉檔成 HTML'],
    ['php meet/parse-meet-proceeding.php', '將委員會議事錄匯入資料庫'],
    ['php meet/crawl-meet-speechlist.php', '從opendata抓取發言紀錄'],
    ['php meet/parse-speech-from-gazette.php', '從公報抓取發言紀錄'],
    ["php meet/crawl-ppg-page.php", "抓取公報網會議網頁"],
    ['php meet/link-meet.php', '更新會議資料'],
    // ivod 相關
    ['php ivod/crawl-html.php', '抓取 ivod HTML'],
    ['php ivod/import-ivod.php', '匯入 ivod'],
    // 質詢相關
    ['php interpellation/crawl-interpellation.php', '抓取匯入質詢資料'],
];

$total = count($cmds);
foreach ($cmds as $idx => $cmd) {
    list($file, $desc) = $cmd;
    if (is_callable($file)) {
        error_log(sprintf("(%d/%d) 執行 %s", $idx + 1, $total, $desc));
        $file();
        continue;
    }
    error_log(sprintf("(%d/%d) 執行 %s %s", $idx + 1, $total, $file, $desc));
    system(sprintf("%s", $file), $ret);
    if ($ret) {
        error_log("執行 $file 失敗");
        exit(1);
    }
}
