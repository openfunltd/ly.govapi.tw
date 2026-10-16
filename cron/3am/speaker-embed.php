<?php
include(__DIR__ . '/../../init.inc.php');

// 語者身分相關的每日維護（順序有意義）：
//   1. upsert-speaker-embed.py   近 50 天有公報對齊的 Clip → 委員／官員聲紋
//   2. upsert-reader-embed.py    全部 Full 的「請宣讀→完畢」視窗 → 宣讀人員聲紋（往既有群靠，只新增 >=2 場的新群）
//   3. build-chair-index.php     公報標頭 → chair-index.json（公報約會後 30 天才到，所以要每天重建）
//   4. recompute-speaker-identity.php since=近 90 天：用 ES 既有 embedding 重新 kNN，讓新聲紋與新到的主席名單
//      套用到最近的 Full 影片（補 is_chair、補宣讀人員），不重跑 ASR
$log = sys_get_temp_dir() . '/speaker-embed.log';
$steps = [
    sprintf('python3 %s --days 50', escapeshellarg(__DIR__ . '/../../imports/ivod/upsert-speaker-embed.py')),
    sprintf('python3 %s', escapeshellarg(__DIR__ . '/../../imports/ivod/upsert-reader-embed.py')),
    sprintf('php %s', escapeshellarg(__DIR__ . '/../../imports/gazette/build-chair-index.php')),
    sprintf('since=%s php %s', date('Y-m-d', strtotime('-90 days')), escapeshellarg(__DIR__ . '/../../imports/ivod/recompute-speaker-identity.php')),
];
foreach ($steps as $cmd) {
    error_log("speaker-embed: start {$cmd}");
    system("{$cmd} >> " . escapeshellarg($log) . ' 2>&1', $ret);
    error_log(sprintf('speaker-embed: done (exit=%d)', $ret));
}
