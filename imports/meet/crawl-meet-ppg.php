<?php

// 從立法院議事暨公報資訊網（ppg.ly.gov.tw）的開會日曆 API 抓會議清單，
// 補足／取代 open data 42（會議資料）更新太慢的問題：
//   open data 42 常要等到週末才列出下週會議，PPG 的日曆在議程排定當下就有。
//
// 兩支 API（網站內部 API，非正式開放資料，格式沒有承諾）：
//   sitting-date?date=115/10&sittingType=ALL&sittingUnit=-1      → 該月有開會的日期清單（含未來已排定）
//   all-sittings?size=-1&page=1&sortCode=-11&meetingDate=115/10/14 → 該日所有會議（id 即 open data 42 的 meetingNo）
//
// 輸出：
//   cache/ppg-meet.jsonl  — PPG 的持久化清單，一場會議一天一列，schema 跟 42-meet.jsonl 完全相同
//   cache/42-meet.jsonl   — 重寫成「PPG 列 + open data 42 裡 PPG 沒有的列」（例如沒有 meetingNo 的
//                           委員自辦公聽會），下游 crawl-ppg-page / link-meet 等腳本不用改
//
// 實測（115/05 整月）PPG 是 42 的超集合：42 有 meetingNo 的 90 場 PPG 全有，PPG 另多出程序委員會。
// PPG 沒有屆／會期欄位：院會、委員會從會議名稱解析；考察、黨團協商、經費稽核等名稱裡沒有的，
// 用同一天（否則最近一天）其他會議的屆／會期補。
//
// 使用：
//   php crawl-meet-ppg.php                       # 本月＋下月（月初 7 天內也補上個月）
//   months=115/09,115/10 php crawl-meet-ppg.php  # 指定月份
//   backfill=1 php crawl-meet-ppg.php            # 第 8 屆（101/02）起逐月全部重抓（約三千多個請求）
//   skip_merge=1 ...                             # 只更新 ppg-meet.jsonl，不重寫 42-meet.jsonl

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');

$store_path = __DIR__ . '/../../cache/ppg-meet.jsonl';
$od_path    = __DIR__ . '/../../cache/42-meet.jsonl';
$REQUEST_GAP_US = 300000; // 每個請求間隔 0.3 秒，不要打太快

// ── 要抓哪些月份 ──────────────────────────────────────────────
function roc_month($ts) { return sprintf('%d/%02d', date('Y', $ts) - 1911, date('m', $ts)); }
$months = [];
if (getenv('months')) {
    $months = array_map('trim', explode(',', getenv('months')));
} elseif (getenv('backfill')) {
    for ($ts = mktime(0, 0, 0, 2, 1, 2012); $ts <= strtotime('+1 month'); $ts = strtotime('+1 month', $ts)) {
        $months[] = roc_month($ts);
    }
} else {
    if (intval(date('j')) <= 7) $months[] = roc_month(strtotime('first day of last month'));
    $months[] = roc_month(time());
    $months[] = roc_month(strtotime('first day of next month'));
}

// ── PPG API ─────────────────────────────────────────────────
function ppg_json($url) {
    global $REQUEST_GAP_US;
    usleep($REQUEST_GAP_US);
    $content = Importer::getURL($url, 30);
    $json = json_decode($content);
    if (!$json) throw new Exception("PPG 回傳非 JSON: $url");
    return $json;
}

function ppg_sitting_dates($month) {
    $json = ppg_json("https://ppg.ly.gov.tw/ppg/api/v1/sitting-date?date={$month}&sittingType=ALL&sittingUnit=-1");
    $dates = array_values(array_unique($json->items ?? []));
    sort($dates);
    return $dates;
}

function ppg_sittings($date) {
    $json = ppg_json("https://ppg.ly.gov.tw/ppg/api/v1/all-sittings?size=-1&page=1&sortCode=-11&meetingDate={$date}");
    return $json->items ?? [];
}

// ── 轉成 42-meet.jsonl 的 schema ─────────────────────────────
function full_unit($unit) {
    // PPG 的單位是短名（內政、程序、朝野黨團協商(教育及文化)），42 是全名（內政委員會、程序委員會、
    // 朝野黨團協商(教育及文化委員會)）
    $unit = trim($unit);
    if ($unit === '' || $unit === '院會') return $unit;
    if ($unit === '全院') return '全院委員會';
    if (preg_match('#^(朝野黨團協商)\((.+)\)$#u', $unit, $m)) {
        return $m[1] . '(' . full_unit($m[2]) . ')';
    }
    if (preg_match('#委員會$#u', $unit) || preg_match('#黨團協商#u', $unit)) return $unit;
    return $unit . '委員會';
}

function time_from_tags($tags, $date) {
    // 院會的 meetingTime 是空的，時間在 tags：「115年10月13日 (二) 09:00-18:00」
    if (!preg_match('#^(\d+)/(\d+)/(\d+)$#', $date, $d)) return null;
    $needle = sprintf('%d年%02d月%02d日', $d[1], $d[2], $d[3]);
    foreach ($tags ?? [] as $tag) {
        if (strpos($tag, $needle) === 0 && preg_match('#(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})#', $tag, $m)) {
            return "{$m[1]}-{$m[2]}";
        }
    }
    return null;
}

function to_row($it) {
    $name = trim($it->meetingName ?? '');
    $date = trim($it->meetingDate ?? '');
    if ($name === '' || $date === '') return null;

    $time = trim($it->meetingTime ?? '');
    if (!preg_match('#^\d{1,2}:\d{2}#', $time)) $time = time_from_tags($it->tags ?? [], $date);
    if (!$time) {
        // LYLib::filterMeetData 要求 meetingDateDesc 一定有時間，沒有就給整天
        error_log("crawl-meet-ppg: {$it->id} {$date} 沒有時間，暫以 00:00-23:59 代替：{$name}");
        $time = '00:00-23:59';
    }

    $term = $session = $times = null;
    if (preg_match('#第(\d+)屆第(\d+)會期#u', $name, $m)) {
        $term = sprintf('%02d', $m[1]);
        $session = sprintf('%02d', $m[2]);
    }
    // 院會「第11屆第6會期第2次會議」／全院委員會「第11屆第5會期第2次全院委員會」的會次
    if (preg_match('#第\d+屆第\d+會期第(\d+)次(會議|全院委員會)$#u', $name, $m)) {
        $times = sprintf('%02d', $m[1]);
    }

    $chair = trim($it->content2 ?? '');
    $chair = preg_replace('#^召集人[：:]\s*#u', '', $chair);

    return [
        'sessionTimes'    => $times ?? 'null',
        'jointCommittee'  => null,
        'sessionPeriod'   => $session,
        'meetingContent'  => trim($it->title ?? '') ?: null,
        'meetingNo'       => (string)$it->id,
        'selectTerm'      => ($term && $session) ? $term . $session : null,
        'meetingName'     => $name,
        'term'            => $term,
        'meetingDateDesc' => "{$date} {$time}",
        'coChairman'      => $chair ?: null,
        'meetingUnit'     => full_unit($it->meetingUnit ?? ''),
        'meetingTimes'    => 'null',
        'meetingRoom'     => trim($it->location ?? '') ?: null,
        'attendLegislator'=> null,
        '_source'         => 'ppg',
    ];
}

// ── 讀舊的 store ────────────────────────────────────────────
$store = []; // key: meetingNo|date
if (file_exists($store_path)) {
    foreach (file($store_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        if ($row) $store[$row['meetingNo'] . '|' . substr($row['meetingDateDesc'], 0, 9)] = $row;
    }
}

// ── 抓指定月份，整月覆蓋（會議取消／改期才會反映）────────────
$fetched_dates = 0; $fetched_rows = 0;
foreach ($months as $month) {
    try {
        $dates = ppg_sitting_dates($month);
    } catch (Exception $e) {
        error_log("crawl-meet-ppg: {$month} sitting-date 失敗：" . $e->getMessage());
        continue;
    }
    $month_rows = [];
    $month_ok = true;
    foreach ($dates as $date) {
        try {
            foreach (ppg_sittings($date) as $it) {
                $row = to_row($it);
                if ($row) $month_rows[$row['meetingNo'] . '|' . $date] = $row;
            }
            $fetched_dates++;
        } catch (Exception $e) {
            error_log("crawl-meet-ppg: {$date} all-sittings 失敗：" . $e->getMessage());
            $month_ok = false; // 這個月有一天抓失敗，就不要把舊資料清掉
        }
    }
    if ($month_ok) {
        foreach ($store as $key => $row) {
            if (strpos(substr($row['meetingDateDesc'], 0, 9), $month . '/') === 0) unset($store[$key]);
        }
    }
    $store = array_merge($store, $month_rows);
    $fetched_rows += count($month_rows);
    error_log("crawl-meet-ppg: {$month} 開會日 " . count($dates) . " 天，" . count($month_rows) . " 列");
}

// ── 補名稱裡沒有屆／會期的列（考察、黨團協商、經費稽核…）────────
$known_by_date = [];
foreach ($store as $row) {
    if ($row['term'] && $row['sessionPeriod']) {
        $known_by_date[substr($row['meetingDateDesc'], 0, 9)] = [$row['term'], $row['sessionPeriod']];
    }
}
$known_dates = array_keys($known_by_date);
sort($known_dates);
$unresolved = 0;
foreach ($store as $key => $row) {
    if ($row['term'] && $row['sessionPeriod']) continue;
    $date = substr($row['meetingDateDesc'], 0, 9);
    $best = null; $best_gap = PHP_INT_MAX;
    $ts = strtotime(str_replace('/', '-', (intval(substr($date, 0, 3)) + 1911) . substr($date, 3)));
    foreach ($known_dates as $kd) {
        $kts = strtotime(str_replace('/', '-', (intval(substr($kd, 0, 3)) + 1911) . substr($kd, 3)));
        $gap = abs($kts - $ts) + ($kts > $ts ? 86400 * 10 : 0); // 同一天最好，其次往前找，往後找要多罰 10 天
        if ($gap < $best_gap) { $best_gap = $gap; $best = $known_by_date[$kd]; }
    }
    if ($best && $best_gap <= 86400 * 120) {
        [$store[$key]['term'], $store[$key]['sessionPeriod']] = $best;
        $store[$key]['selectTerm'] = $best[0] . $best[1];
    } else {
        $unresolved++;
    }
}

uksort($store, fn($a, $b) => substr($a, strpos($a, '|') + 1) <=> substr($b, strpos($b, '|') + 1) ?: $a <=> $b);
file_put_contents($store_path, implode('', array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE) . "\n", $store)));
error_log("crawl-meet-ppg: store " . count($store) . " 列（本次抓 {$fetched_dates} 天 {$fetched_rows} 列，屆／會期補不出來 {$unresolved} 列）");

if (getenv('skip_merge')) exit;

// ── 合併寫回 42-meet.jsonl：PPG 優先，open data 只補 PPG 沒有的 ──
$out = [];
$seen = [];
foreach ($store as $row) {
    unset($row['_source']);
    $out[] = $row;
    $seen[$row['meetingNo'] . '|' . substr($row['meetingDateDesc'], 0, 9)] = true;
}
$od_total = $od_added = 0;
if (file_exists($od_path)) {
    foreach (file($od_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        if (!$row) continue;
        $od_total++;
        $key = ($row['meetingNo'] ?? '') . '|' . substr($row['meetingDateDesc'] ?? '', 0, 9);
        if (!empty($row['meetingNo']) && isset($seen[$key])) continue;
        $out[] = $row;
        $od_added++;
    }
}
file_put_contents($od_path, implode('', array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE) . "\n", $out)));
error_log("crawl-meet-ppg: 42-meet.jsonl 重寫為 " . count($out) . " 列（PPG " . count($store) . " + open data 補 {$od_added}/{$od_total}）");
