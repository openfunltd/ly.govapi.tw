<?php

$func_move_bill = function(){
    if (file_exists(__DIR__ . '/bill/output.jsonl')) {
        rename(__DIR__ . '/bill/output.jsonl', __DIR__ . '/bill/output.jsonl.' . date('Ymd', filemtime(__DIR__ . '/bill/output.jsonl')));
    }
};

$cmds = [
    // 委員相關
    //['php committee.php', '委員會資料'],
    //['php legislator/legislators.php', '立委資料'],
    // 議案相關
    //[$func_move_bill, '搬移舊議案列表'],
    //['php bill/crawl-list.php > bill/output.jsonl', '抓取議案列表'],
    //['php bill/check-updated-bill.php bill/output.jsonl', '檢查變更議案'],
    ['php bill/crawl-entry.php bill/output.jsonl', '抓取議案資料'],
    // 會議相關
    ['php meet/crawl-meet.php', '抓取會議資料'],
    ['php meet/crawl-meet-proceeding.php', '抓取會議議事錄並轉檔成 HTML'],
    ['php meet/parse-meet-proceeding.php', '將議事錄匯入資料庫'],
    //['php gazette.php', '公報資料'],
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
