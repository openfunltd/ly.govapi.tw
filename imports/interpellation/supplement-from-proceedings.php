<?php
/**
 * 書面質詢補錄：從公報院會議事錄的「質詢事項／本院委員質詢部分」補 PPG 關係文書缺的質詢
 *
 * PPG 的關係文書 PDF 有時整場沒有（11-1-10 只放議事日程、11-1-22 沒有質詢分頁、11-5-23 至今沒分頁），
 * 公報的院會議事錄一定會列出該次會議的書面質詢案由（只有案由，沒有編號和說明）。
 * 質詢編號是每會期連續編號，所以可以用同會期已知的編號把缺口補上：議事錄的順序就是編號順序，
 * 沒配到關係文書的條目夾在已知編號之間、數量剛好等於缺口時就推得出編號；推不出的只記 log 不建資料。
 * 建出來的紀錄 source=議事錄、no_inferred=true、沒有 description；之後關係文書出現時 crawl-interpellation.php
 * 會用同一個 id 接手（文字相同、listings 補上 PDF 來源），這裡的紀錄就被覆蓋。
 *
 *   min_term=8 dry=1 data_dir=… php supplement-from-proceedings.php
 */
include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');
$dry = (bool)getenv('dry');
$min_term = intval(getenv('min_term') ?: 10);
$quiet = (bool)getenv('quiet'); // quiet=1 不發 import log（第一次大量補錄用）
$data_dir = getenv('data_dir') ?: __DIR__ . '/interpellation-data';
if (trim(@file_get_contents($data_dir . '/.schema')) !== '2') {
    error_log("{$data_dir} 不是新制（.schema != 2），請先跑 rebuild=1 的 crawl-interpellation.php");
    exit(1);
}
$tikahtml_dir = __DIR__ . '/../gazette/agenda-tikahtml';

function norm_text($s) { return preg_replace('/\s+/u', '', (string)$s); }

// 議事錄 → [{legislators, reason}]
function proceeding_items($lcidc_id)
{
    global $tikahtml_dir;
    $f = "{$tikahtml_dir}/LCIDC01_{$lcidc_id}.doc.html";
    if (!file_exists($f)) return null;
    $t = html_entity_decode(strip_tags(preg_replace('#<(p|div|br|tr|h\d)[^>]*>#i', "\n", file_get_contents($f))));
    $lines = array_values(array_filter(array_map('trim', explode("\n", $t)), fn($x) => $x !== ''));
    $items = [];
    // 議事錄的「質詢事項」底下：有行政院答復時分「（甲、）行政院答復部分」「（乙、）本院委員質詢部分」兩段，
    // 只有委員質詢時沒有小標、直接接「一、本院…」。答復部分的條目都是「行政院函送…」開頭，靠這個區分
    $in = false; $section = null;
    foreach ($lines as $x) {
        if (preg_match('/^質詢事項$/u', $x)) { $in = true; $section = '委員'; continue; }
        if (!$in) continue;
        if (preg_match('/^(甲、)?行政院答復部分$/u', $x)) { $section = '答復'; continue; }
        if (preg_match('/^(乙、)?本院委員質詢部分$/u', $x)) { $section = '委員'; continue; }
        if (preg_match('/^(討論事項|丙、|臨時提案|本日會議|散會|報告事項|選舉事項|國是論壇|備註)/u', $x)) break;
        if ($section !== '委員') continue;
        if (preg_match('/^[一二三四五六七八九十○百零]+、(本院.+)$/u', $x, $m)) {
            $reason = $m[1];
            $legislators = null;
            $r = str_replace(' ', '', $reason);
            if (preg_match('#^本院([^，、]+)委員([^，、]+)[，、]#u', $r, $mm)) $legislators = [$mm[1] . $mm[2]];
            elseif (preg_match('#^本院委員(.*?)，#u', $r, $mm)) $legislators = explode('、', $mm[1]);
            elseif (preg_match('#^本院([^，]*黨團)，#u', $r, $mm)) $legislators = [$mm[1]];
            elseif (preg_match('#^本院([^，]*)委員，#u', $r, $mm)) $legislators = [$mm[1]];
            if ($legislators) $items[] = (object)['legislators' => $legislators, 'reason' => $reason];
        } elseif (count($items) and !preg_match('/^[（(]/u', $x)) {
            // 案由跨行時接續
            $items[count($items) - 1]->reason .= $x;
        }
    }
    return $items;
}

function norm_names($names) { return array_map(fn($n) => preg_replace('/[^\p{L}\p{N}]+/u', '', $n), (array)$names); }
function same_item($rec, $item)
{
    if (norm_names($rec->legislators ?? []) != norm_names($item->legislators)) return false;
    $a = mb_substr(norm_text($rec->reason), 0, 60); $b = mb_substr(norm_text($item->reason), 0, 60);
    if ($a === $b) return true;
    similar_text($a, $b, $pct);
    return $pct >= 80;
}

putenv('https_proxy'); // 只打 ES
$r = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode([
    'size' => 10000,
    'query' => ['query_string' => ['query' => 'meet_type.keyword:院會 AND _exists_:議事錄.agenda_lcidc_id AND term:>=' . $min_term]],
    '_source' => ['meet_id', 'term', 'sessionPeriod', 'sessionTimes', '議事錄.agenda_lcidc_id'],
]));
$meets = array_map(fn($h) => $h->_source, $r->hits->hits);
usort($meets, fn($a, $b) => [$a->term, $a->sessionPeriod, $a->sessionTimes] <=> [$b->term, $b->sessionPeriod, $b->sessionTimes]);

// 同會期已知的紀錄（含先前補錄的）
$by_session = [];
foreach (glob($data_dir . '/*') as $f) {
    $rec = json_decode(file_get_contents($f));
    if (!isset($rec->no)) continue;
    $by_session["{$rec->term}-{$rec->sessionPeriod}"][] = $rec;
}

$added = 0; $unresolved = 0; $meets_checked = 0;
foreach ($meets as $meet) {
    $items = proceeding_items($meet->{'議事錄'}->agenda_lcidc_id);
    if (!$items) continue;
    $meets_checked++;
    $sk = "{$meet->term}-{$meet->sessionPeriod}";
    $session = $by_session[$sk] ?? [];
    // 議事錄每一條配到已知紀錄的編號；配不到的待推
    $nos = [];
    foreach ($items as $i => $item) {
        $nos[$i] = null;
        foreach ($session as $rec) {
            if (same_item($rec, $item)) { $nos[$i] = $rec->no; break; }
        }
    }
    if (!in_array(null, $nos, true)) continue; // 全部都有了
    // 推編號：把已知編號當錨點，錨點之間的未知條目數量等於編號缺口就依序填；
    // 開頭／結尾沒有錨點時用同會期「前一次會議的最大編號」「後一次會議的最小編號」當邊界
    $used = []; foreach ($session as $rec) $used[$rec->no] = true;
    $prev_max = 0; $next_min = PHP_INT_MAX;
    foreach ($session as $rec) {
        foreach ($rec->listings ?? [] as $l) {
            if ($l->sessionTimes < $meet->sessionTimes) $prev_max = max($prev_max, $rec->no);
            if ($l->sessionTimes > $meet->sessionTimes) $next_min = min($next_min, $rec->no);
        }
    }
    $anchors = array_filter($nos, fn($n) => !is_null($n));
    $lo = $prev_max; // 前一個錨點
    $i = 0; $n = count($items);
    while ($i < $n) {
        if (!is_null($nos[$i])) { $lo = $nos[$i]; $i++; continue; }
        $j = $i; while ($j < $n and is_null($nos[$j])) $j++;
        $hi = $j < $n ? $nos[$j] : $next_min; // 後一個錨點
        $count = $j - $i;
        $free = [];
        if ($hi !== PHP_INT_MAX) {
            for ($x = $lo + 1; $x < $hi; $x++) if (empty($used[$x])) $free[] = $x;
        } else {
            // 會期最後一次會議之後沒有邊界：只有在這場完全沒有任何已知編號、且前面沒有缺口時才敢直接往後接
            for ($x = $lo + 1; $x <= $lo + $count; $x++) if (empty($used[$x])) $free[] = $x;
        }
        if (count($free) === $count) {
            for ($k = 0; $k < $count; $k++) $nos[$i + $k] = $free[$k];
        } else {
            error_log(sprintf("%s: 議事錄第 %d～%d 條推不出編號（區間 %d～%s 有 %d 個空號、%d 條）", $meet->meet_id, $i + 1, $j, $lo, $hi === PHP_INT_MAX ? '∞' : $hi, count($free), $count));
            $unresolved += $count;
        }
        $i = $j;
    }
    foreach ($items as $i => $item) {
        if (is_null($nos[$i])) continue;
        $already = false;
        foreach ($session as $rec) if ($rec->no == $nos[$i]) { $already = true; break; }
        if ($already) continue;
        $id = "{$meet->term}-{$meet->sessionPeriod}-{$nos[$i]}";
        $record = (object)[
            'id' => $id, 'term' => $meet->term, 'sessionPeriod' => $meet->sessionPeriod, 'no' => $nos[$i],
            'legislators' => $item->legislators, 'reason' => $item->reason, 'description' => null,
            'source' => '議事錄', 'no_inferred' => true,
            'listings' => [(object)[
                'meet_id' => $meet->meet_id, 'sessionTimes' => $meet->sessionTimes, 'meetingNo' => null, 'printed_at' => null,
                'page_start' => null, 'page_end' => null, 'source_page' => null,
                'source_pdf' => 'https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_' . $meet->{'議事錄'}->agenda_lcidc_id . '.doc.html',
            ]],
            'meet_ids' => [$meet->meet_id],
            'meet_id' => $meet->meet_id, 'sessionTimes' => $meet->sessionTimes, 'meetingNo' => null, 'printed_at' => null,
            'page_start' => null, 'page_end' => null, 'source_page' => null,
            'source_pdf' => 'https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_' . $meet->{'議事錄'}->agenda_lcidc_id . '.doc.html',
        ];
        file_put_contents("{$data_dir}/{$id}", json_encode($record, JSON_UNESCAPED_UNICODE));
        $by_session[$sk][] = $record; $session[] = $record;
        $added++;
        error_log("補錄 {$id} ({$meet->meet_id}) " . implode(',', $item->legislators) . ' ' . mb_substr($item->reason, 0, 30));
        if (!$dry) {
            Elastic::dbBulkInsert('interpellation', $id, $record);
            if (!$quiet) Importer::addImportLog([
                'event' => 'interpellation-new',
                'group' => 'interpellation',
                'message' => sprintf("補錄質詢(%s，編號由議事錄推算) %s", $id, mb_substr($item->reason, 0, 60)),
            ], $commit = false);
        }
    }
}
if (!$dry) {
    Elastic::dbBulkCommit();
    Importer::addImportLog(null, $commit = true);
}
error_log("done: 檢查 {$meets_checked} 場議事錄，補錄 {$added} 則，推不出編號 {$unresolved} 則");
