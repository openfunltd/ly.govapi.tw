<?php
// cron/3hr/meet.php 鏈的一環：公報發言索引（import-speech.php）更新後，重跑有變動會議的切分（單元 → 段），
// 寫 ES meet_section。實際工作在 run-segment.py；這支只負責鎖、環境變數與回傳值。
include(__DIR__ . '/../../init.inc.php');

$lock = fopen(__DIR__ . '/.run-segment.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    error_log('run-segment: 上一輪還在跑，略過');
    exit(0);
}

// 切分器抓 v2 API 不走 proxy（proxy 只給立法院網站用）
putenv('https_proxy');
$cmd = sprintf('python3 %s', escapeshellarg(__DIR__ . '/run-segment.py'));
passthru($cmd, $ret);
flock($lock, LOCK_UN);
exit($ret);
