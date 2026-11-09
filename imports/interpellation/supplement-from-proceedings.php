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
            // 「本院全院委員會報告審查…」「本院○○委員會報告審查…」是下一節的條目，不是質詢
            if ($legislators and preg_match('/委員會|報告/u', implode('', $legislators))) $legislators = null;
            if ($legislators) $items[] = (object)['legislators' => $legislators, 'reason' => $reason];
        } elseif (count($items) and !preg_match('/^[（(]/u', $x)) {
            // 案由跨行時接續
            $items[count($items) - 1]->reason .= $x;
        }
    }
    return $items;
}

function norm_names($names) { return array_map(fn($n) => preg_replace('/[^\p{L}\p{N}]+/u', '', $n), (array)$names); }
// 姓名相同，或只差一個字（議事錄的 tikahtml 會掉罕用字：「傅崐萁」→「傅萁」）
function same_names($a, $b)
{
    $a = norm_names($a); $b = norm_names($b);
    if ($a == $b) return true;
    if (count($a) != count($b)) return false;
    foreach ($a as $i => $x) {
        $y = $b[$i];
        if ($x === $y) continue;
        [$long, $short] = mb_strlen($x) > mb_strlen($y) ? [$x, $y] : [$y, $x];
        if (mb_strlen($long) - mb_strlen($short) != 1) return false;
        $ok = false;
        for ($k = 0; $k < mb_strlen($long); $k++) if (mb_substr($long, 0, $k) . mb_substr($long, $k + 1) === $short) { $ok = true; break; }
        if (!$ok) return false;
    }
    return true;
}
function same_item($rec, $item)
{
    if (!same_names($rec->legislators ?? [], $item->legislators)) return 0;
    // 以兩邊較短者為準比前段（議事錄有時多接一段「對行政院…」，關係文書有時案由較短）
    $a = norm_text($rec->reason); $b = norm_text($item->reason);
    $len = min(mb_strlen($a), mb_strlen($b), 60);
    if ($len < 10) return 0;
    $a = mb_substr($a, 0, $len); $b = mb_substr($b, 0, $len);
    if ($a === $b or char_diff_le1($a, $b)) return 2; // 只差一個字（鑑／鑒等異體字、tikahtml 掉字）也算同一則
    similar_text($a, $b, $pct);
    return $pct >= 80 ? 1 : 0;
}
// 兩字串是否只差一個字元（替換、或多／少一個字）
function char_diff_le1($a, $b)
{
    $la = mb_strlen($a); $lb = mb_strlen($b);
    if (abs($la - $lb) > 1) return false;
    $ca = mb_str_split($a); $cb = mb_str_split($b);
    $i = 0; while ($i < min($la, $lb) and $ca[$i] === $cb[$i]) $i++;
    if ($i >= min($la, $lb)) return true;
    if ($la == $lb) return array_slice($ca, $i + 1) === array_slice($cb, $i + 1);
    return $la > $lb ? array_slice($ca, $i + 1) === array_slice($cb, $i) : array_slice($ca, $i) === array_slice($cb, $i + 1);
}
// 最長嚴格遞增子序列，回傳被選上的索引（null 不參與）
function lis_indices($vals)
{
    $idx = array_keys(array_filter($vals, fn($v) => !is_null($v)));
    $n = count($idx); if (!$n) return [];
    $len = array_fill(0, $n, 1); $prev = array_fill(0, $n, -1);
    for ($i = 1; $i < $n; $i++) for ($j = 0; $j < $i; $j++) {
        if ($vals[$idx[$j]] < $vals[$idx[$i]] and $len[$j] + 1 > $len[$i]) { $len[$i] = $len[$j] + 1; $prev[$i] = $j; }
    }
    $best = array_search(max($len), $len);
    $out = [];
    for ($i = $best; $i >= 0; $i = $prev[$i]) $out[$idx[$i]] = true;
    return $out;
}

putenv('https_proxy'); // 只打 ES
$r = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode([
    'size' => 10000,
    'query' => ['query_string' => ['query' => 'meet_type.keyword:院會 AND _exists_:議事錄.agenda_lcidc_id AND term:>=' . $min_term]],
    '_source' => ['meet_id', 'term', 'sessionPeriod', 'sessionTimes', '議事錄.agenda_lcidc_id'],
]));
$meets = array_map(fn($h) => $h->_source, $r->hits->hits);
// 臨時會的會次另起算，排在該會期一般院會之後
$sort_key = fn($m) => [$m->term, $m->sessionPeriod, str_starts_with($m->meet_id, '臨時會') ? 1 : 0, $m->sessionTimes];
usort($meets, fn($a, $b) => $sort_key($a) <=> $sort_key($b));

// 同會期已知的紀錄（含先前補錄的）
$by_session = [];
foreach (glob($data_dir . '/*') as $f) {
    $rec = json_decode(file_get_contents($f));
    if (!isset($rec->no)) continue;
    $by_session["{$rec->term}-{$rec->sessionPeriod}"][] = $rec;
}

$added = 0; $unresolved = 0; $meets_checked = 0;
$by_sk_meets = [];
foreach ($meets as $meet) $by_sk_meets["{$meet->term}-{$meet->sessionPeriod}"][] = $meet;
foreach ($by_sk_meets as $sk => $sk_meets) {
    $session = $by_session[$sk] ?? [];
    // 整個會期各場議事錄的條目依會議順序串成一條序列：編號是會期連續的，這樣連續幾場都沒關係文書
    // （例如 9-7-1、9-7-2 各 4 則、前面空號 1～8）也能一起推
    $seq = [];
    foreach ($sk_meets as $meet) {
        $items = proceeding_items($meet->{'議事錄'}->agenda_lcidc_id);
        if (!$items) continue;
        $meets_checked++;
        foreach ($items as $item) $seq[] = (object)['meet' => $meet, 'item' => $item, 'no' => null, 'matched' => false, 'exact' => false];
    }
    if (!$seq) continue;
    foreach ($seq as $e) {
        foreach ($session as $rec) {
            if ($m = same_item($rec, $e->item)) { $e->no = $rec->no; $e->matched = true; $e->exact = ($m == 2); break; }
        }
    }
    if (!array_filter($seq, fn($e) => !$e->matched)) continue; // 全部都有了
    // 錨點只取編號遞增的那一串（最長遞增子序列）：配到更早編號的條目（重複刊登、或文字相近誤配）
    // 不當錨點，否則一個倒退的編號就讓後面整段推不出來（8-3-15、8-7-16 各百餘則）
    // 不在遞增串上的：文字完全相同是重複刊登（8-3-15 再列一次 8-3-14 的 425），略過；只是相近的當成另一則待推號
    // （8-7-16 邱志偉「促請教育部」與 631 號「促請外交部」是兩則）
    $anchors = lis_indices(array_map(fn($e) => $e->matched ? $e->no : null, $seq));
    $work = [];
    foreach ($seq as $i => $e) {
        if ($e->matched and !isset($anchors[$i])) {
            if ($e->exact) continue;
            $e->matched = false; $e->no = null;
        }
        $work[] = $e;
    }
    $used = [];
    foreach ($session as $rec) $used[$rec->no] = true;
    $lo = 0; $n = count($work); $i = 0;
    while ($i < $n) {
        if ($work[$i]->matched) { $lo = $work[$i]->no; $i++; continue; }
        $j = $i; while ($j < $n and !$work[$j]->matched) $j++;
        $hi = $j < $n ? $work[$j]->no : PHP_INT_MAX; // 後一個錨點；會期最後沒有邊界就直接往後接
        $count = $j - $i;
        $free = [];
        if ($hi !== PHP_INT_MAX) {
            for ($x = $lo + 1; $x < $hi; $x++) if (empty($used[$x])) $free[] = $x;
        } else {
            for ($x = $lo + 1; $x <= $lo + $count; $x++) if (empty($used[$x])) $free[] = $x;
        }
        if (count($free) === $count) {
            for ($k = 0; $k < $count; $k++) $work[$i + $k]->no = $free[$k];
        } else {
            $run_meets = array_unique(array_map(fn($e) => $e->meet->meet_id, array_slice($work, $i, $count)));
            error_log(sprintf("%s: 議事錄 %d 條推不出編號（區間 %d～%s 有 %d 個空號）", implode(',', $run_meets), $count, $lo, $hi === PHP_INT_MAX ? '∞' : $hi, count($free)));
            $unresolved += $count;
        }
        $i = $j;
    }
    foreach ($work as $e) {
        if ($e->matched or is_null($e->no)) continue;
        $meet = $e->meet; $item = $e->item; $no = $e->no;
        $id = "{$meet->term}-{$meet->sessionPeriod}-{$no}";
        $html_url = 'https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_' . $meet->{'議事錄'}->agenda_lcidc_id . '.doc.html';
        $record = (object)[
            'id' => $id, 'term' => $meet->term, 'sessionPeriod' => $meet->sessionPeriod, 'no' => $no,
            'legislators' => $item->legislators, 'reason' => $item->reason, 'description' => null,
            'source' => '議事錄', 'no_inferred' => true,
            'listings' => [(object)[
                'meet_id' => $meet->meet_id, 'sessionTimes' => $meet->sessionTimes, 'meetingNo' => null, 'printed_at' => null,
                'page_start' => null, 'page_end' => null, 'source_page' => null, 'source_pdf' => $html_url,
            ]],
            'meet_ids' => [$meet->meet_id],
            'meet_id' => $meet->meet_id, 'sessionTimes' => $meet->sessionTimes, 'meetingNo' => null, 'printed_at' => null,
            'page_start' => null, 'page_end' => null, 'source_page' => null, 'source_pdf' => $html_url,
        ];
        if (!$dry) file_put_contents("{$data_dir}/{$id}", json_encode($record, JSON_UNESCAPED_UNICODE));
        $by_session[$sk][] = $record;
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
