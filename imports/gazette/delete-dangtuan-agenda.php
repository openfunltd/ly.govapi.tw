<?php
/**
 * 清除所有 import_source=黨團協商 的 gazette_agenda 紀錄。
 * 這些紀錄是由 import-dangtuan-agenda.php 從 ppg SittingCappInfo 連結建立，
 * 但 doc URL 使用 communique1/work 路徑，與 SittingCappInfo 系統命名空間不同，
 * 大多數下載到的是錯誤的委員會文件而非黨團協商紀錄。
 *
 * Usage:
 *   php delete-dangtuan-agenda.php --dry-run
 *   php delete-dangtuan-agenda.php
 */

include(__DIR__ . '/../../init.inc.php');

$dry_run    = in_array('--dry-run', $argv);
$meta_dir   = __DIR__ . '/gazette-agenda-data';
$anchor_dir = __DIR__ . '/agenda-anchor';

$ids = [];
foreach (glob("{$meta_dir}/*.json") as $f) {
    $m = json_decode(file_get_contents($f), true);
    if (($m['import_source'] ?? '') !== '黨團協商') continue;
    $ids[] = $m['agenda_id'];
}

echo "共 " . count($ids) . " 筆 gazette_agenda 待刪除\n";

$deleted = $skipped = $anchor_removed = 0;

foreach ($ids as $agenda_id) {
    // 刪 ES
    if (!$dry_run) {
        Elastic::dbQuery('/{prefix}gazette_agenda/_doc/' . $agenda_id, 'DELETE');
    }

    // 刪 gazette-agenda-data JSON
    $meta_file = "{$meta_dir}/{$agenda_id}.json";
    if (file_exists($meta_file)) {
        if (!$dry_run) unlink($meta_file);
        $deleted++;
    } else {
        $skipped++;
    }

    // 刪 agenda-anchor（若存在且 meeting_type=dangtuan）
    $anchor_file = "{$anchor_dir}/{$agenda_id}.json";
    if (file_exists($anchor_file)) {
        $a = json_decode(file_get_contents($anchor_file), true);
        if (($a['meeting_type'] ?? '') === 'dangtuan') {
            if (!$dry_run) unlink($anchor_file);
            $anchor_removed++;
        }
    }
}

if (!$dry_run) Elastic::dbBulkCommit();

$label = $dry_run ? '[dry-run] ' : '';
echo "{$label}meta 刪除: {$deleted}，anchor 刪除: {$anchor_removed}\n";
