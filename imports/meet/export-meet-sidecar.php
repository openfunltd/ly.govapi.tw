<?php
// 匯出單場會議的補充資料（給 research-ly-meet 的會議頁面 prototype 用）：
// 會議資訊、議事錄、關係文書與對照表、附件、Full/Clip 影片時間。
// 用法：php export-meet-sidecar.php 委員會-11-3-15-12 /path/to/out.json
include(__DIR__ . '/../../init.inc.php');
putenv('https_proxy');
$id = $argv[1] ?? null; $out_path = $argv[2] ?? null;
if (!$id || !$out_path) { fwrite(STDERR, "usage: php export-meet-sidecar.php <meet_id> <out.json>\n"); exit(1); }
$m = Elastic::dbQuery("/{prefix}meet/_doc/" . rawurlencode($id), 'GET')->_source ?? null;
if (!$m) { fwrite(STDERR, "meet not found: $id\n"); exit(1); }
$out = [
    'meet_id' => $id, 'name' => $m->name, 'title' => $m->title, 'dates' => $m->dates, 'meet_type' => $m->meet_type,
    'committees' => $m->committees ?? null, 'term' => $m->term, 'sessionPeriod' => $m->sessionPeriod,
    'meet_data' => array_map(fn($x) => array_intersect_key((array)$x, array_flip(['meetingNo', 'meetingUnit', 'meetingRoom', 'coChairman', 'meetingDateDesc', 'meetingContent', 'meetingContent2', 'date', 'startTime', 'endTime'])), $m->meet_data ?? []),
    '議事錄' => array_intersect_key((array)($m->議事錄 ?? []), array_flip(['title', '時間', '地點', '主席', '出席委員', '請假委員', '列席委員', '列席官員', '專門委員', '主任秘書'])),
    'attachments' => $m->ppg_data[0]->attachments ?? [],
    '公報' => array_intersect_key((array)($m->公報發言紀錄[0] ?? []), array_flip(['pdf_url', 'doc_url', 'agendarec_url', 'gazette_id', 'page_start', 'page_end', 'meetingDate'])),
    'ppg_url' => isset($m->meet_data[0]->meetingNo) ? "https://ppg.ly.gov.tw/ppg/sittings/{$m->meet_data[0]->meetingNo}/details?meetingDate=" . ($m->meet_data[0]->meetingDateDesc ?? '') : null,
    'bills' => [], 'ivod' => ['full' => null, 'clips' => []],
];
foreach ($m->ppg_data[0]->關係文書->bills ?? [] as $b) {
    try { $d = Elastic::dbQuery("/{prefix}bill/_doc/{$b->billNo}", 'GET')->_source ?? null; } catch (Exception $e) { $d = null; }
    if (!$d) continue;
    $out['bills'][] = ['billNo' => $b->billNo, 'title' => $b->title, '議案名稱' => $d->議案名稱 ?? '', '提案' => $d->{'提案單位/提案委員'} ?? '', '議案狀態' => $d->議案狀態 ?? '', '案由' => $d->案由 ?? '', 'laws' => $d->laws ?? [], '對照表' => $d->對照表 ?? [], '相關附件' => $d->相關附件 ?? [],
        '議案流程' => array_map(fn($x) => array_intersect_key((array)$x, array_flip(['日期', '狀態', '會期', '院會/委員會'])), $d->議案流程 ?? [])];
}
$r = Elastic::dbQuery("/{prefix}ivod/_search", 'POST', json_encode(['size' => 5, 'query' => ['bool' => ['must' => [['term' => ['meet.id.keyword' => $id]], ['term' => ['type.keyword' => 'Full']]]]], 'sort' => [['start_time' => 'asc']], '_source' => ['id', 'url', 'video_url', 'start_time', 'end_time', 'duration', 'date']]));
if ($r->hits->hits) { $out['ivod']['full'] = $r->hits->hits[0]->_source; $out['ivod']['fulls'] = array_map(fn($h) => $h->_source, $r->hits->hits); }
$r = Elastic::dbQuery("/{prefix}ivod/_search", 'POST', json_encode(['size' => 200, 'query' => ['bool' => ['must' => [['term' => ['meet.id.keyword' => $id]], ['term' => ['type.keyword' => 'Clip']]]]], 'sort' => [['start_time' => 'asc']], '_source' => ['id', 'url', 'video_url', '委員名稱', 'start_time', 'end_time', 'duration']]));
foreach ($r->hits->hits as $h) $out['ivod']['clips'][] = $h->_source;
file_put_contents($out_path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDERR, sprintf("wrote %s: bills=%d clips=%d full=%s attachments=%d\n", $out_path, count($out['bills']), count($out['ivod']['clips']), $out['ivod']['full']->id ?? '-', count($out['attachments'])));
