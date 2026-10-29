<?php
// 匯出一段日期範圍內的全部會議清單（給 research-ly-meet 會議網站 prototype 的首頁用）
// 用法：php export-meet-list.php 2026-09-15 2026-11-12 /path/to/out.json
include(__DIR__ . '/../../init.inc.php');
putenv('https_proxy');
[$from, $to, $out_path] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? null];
if (!$from || !$to || !$out_path) { fwrite(STDERR, "usage: php export-meet-list.php <from> <to> <out.json>\n"); exit(1); }
$meets = []; $after = null;
while (true) {
    $body = ['size' => 500, 'query' => ['range' => ['dates' => ['gte' => $from, 'lte' => $to]]], 'sort' => [['meet_id.keyword' => 'asc']],
             '_source' => ['meet_id', 'meet_type', 'dates', 'title', 'name', 'term', 'sessionPeriod', 'committees', 'meet_data.meetingUnit', 'meet_data.meetingRoom', 'meet_data.coChairman', 'meet_data.meetingContent', 'meet_data.date', 'meet_data.meetingDateDesc', '議事錄.主席']];
    if ($after) $body['search_after'] = $after;
    $r = Elastic::dbQuery('/{prefix}meet/_search', 'POST', json_encode($body, JSON_UNESCAPED_UNICODE));
    $hits = $r->hits->hits ?? [];
    if (!$hits) break;
    foreach ($hits as $h) { $after = $h->sort; $meets[$h->_source->meet_id] = $h->_source; }
}
// 每場的 ivod 數（Full / Clip）
$r = Elastic::dbQuery('/{prefix}ivod/_search', 'POST', json_encode(['size' => 0, 'query' => ['range' => ['date' => ['gte' => $from, 'lte' => $to]]],
    'aggs' => ['m' => ['terms' => ['field' => 'meet.id.keyword', 'size' => 5000], 'aggs' => ['t' => ['terms' => ['field' => 'type.keyword']]]]]]));
$ivod = [];
foreach ($r->aggregations->m->buckets ?? [] as $b) { foreach ($b->t->buckets as $t) $ivod[$b->key][$t->key] = $t->doc_count; }
$out = [];
foreach ($meets as $id => $m) {
    $md = $m->meet_data ?? [];
    $out[] = [
        'meet_id' => $id, 'meet_type' => $m->meet_type, 'dates' => array_values(array_filter((array)$m->dates, fn($d) => $d >= $from && $d <= $to)),
        'all_dates' => $m->dates, 'title' => $m->title, 'name' => $m->name, 'term' => $m->term, 'sessionPeriod' => $m->sessionPeriod,
        'committees' => $m->committees ?? [], 'unit' => $md[0]->meetingUnit ?? null, 'room' => $md[0]->meetingRoom ?? null,
        'chair' => $md[0]->coChairman ?? (($m->議事錄->主席 ?? [null])[0] ?? null),
        'content' => $md[0]->meetingContent ?? null,
        'days' => array_values(array_unique(array_map(fn($x) => $x->date ?? null, $md))),
        'full' => $ivod[$id]['Full'] ?? 0, 'clip' => $ivod[$id]['Clip'] ?? 0,
    ];
}
file_put_contents($out_path, json_encode(['from' => $from, 'to' => $to, 'generated' => date('c'), 'meets' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, "wrote $out_path: " . count($out) . " meets\n");
