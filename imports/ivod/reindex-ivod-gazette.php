<?php

// 把已有 transcript.gazette 的 ivod-data/{id}.json 重新 index 進 ES
// 使用方式：
//   php reindex-ivod-gazette.php          # 全量
//   limit=500 php reindex-ivod-gazette.php

include(__DIR__ . '/../../init.inc.php');

$limit   = intval(getenv('limit') ?: 0);
$done    = 0;
$skipped = 0;
$errors  = 0;

$files = glob(__DIR__ . '/ivod-data/*.json');
natsort($files);

foreach ($files as $path) {
    if ($limit && $done >= $limit) break;

    $data = json_decode(file_get_contents($path));
    if (!$data) { $errors++; continue; }

    if (empty($data->transcript->gazette)) { $skipped++; continue; }

    try {
        Elastic::dbBulkInsert('ivod', $data->id, $data);
        $done++;
        if ($done % 500 === 0) {
            Elastic::dbBulkCommit();
            error_log("indexed {$done}...");
        }
    } catch (Exception $e) {
        error_log("error {$data->id}: " . $e->getMessage());
        $errors++;
    }
}

Elastic::dbBulkCommit();
error_log("done={$done} skipped={$skipped} errors={$errors}");
