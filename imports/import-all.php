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
    // 委員相關
    ['php legislator/legislators.php', '立委資料'],
    // 議案相關
    [$func_move_bill, '搬移舊議案列表'],
    ['php bill/crawl-list.php > bill/output.jsonl', '抓取議案列表'],
    ['php bill/check-updated-bill.php bill/output.jsonl', '檢查變更議案'],
    ['php bill/crawl-entry.php bill/output.jsonl', '抓取議案資料'],
    ['php bill/crawl-doc.php bill/output.jsonl', '抓取議案 Word'],
    ['php bill/parse-doc.php bill/output.jsonl', '處理議案文件'],
    ['php bill/import.php bill/output.jsonl', '匯入議案文件'],
    // 公報相關
    ['php gazette.php', '抓取公報目錄'],
    ['php gazette/crawl.php', '抓取公報內容並轉換成 txt/HTML'],
    ['php gazette/crawl-doc.php', '抓取各章節 doc 並轉成 txt/HTML'],
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
