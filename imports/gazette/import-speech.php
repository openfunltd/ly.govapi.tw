<?php
/**
 * 公報發言索引（第二階段）：把各會議「公報發言紀錄」指到的公報子文件切成「一次發言」一筆，寫進 ES 的 speech index。
 * 切分與欄位定義在 GazetteSpeechParser.php；設計見 PLAN.md「規劃中：會議屬性欄位與公報發言索引」。
 *
 * 用法：
 *   php import-speech.php                       # 全部會議；只處理來源 md5 或 parser 版本變過的文件（狀態在 speech-state/）
 *   meet_id=委員會-11-5-20-18 php import-speech.php   # 單場
 *   term=11 php import-speech.php               # 只處理某屆
 *   meet_type=委員會 php import-speech.php       # 只處理某種會議
 *   force=1 php import-speech.php               # 不看狀態檔，全部重做
 *   dry=1 php import-speech.php                 # 只解析、印統計，不寫 ES、不寫狀態檔
 *   limit=200 php import-speech.php             # 最多處理 N 份文件（背景分批回填用）
 */
include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');
putenv('https_proxy'); // 只打 ES 和本機／lydata 檔案

$dry = (bool)getenv('dry');
$force = (bool)getenv('force');
$limit = intval(getenv('limit')) ?: PHP_INT_MAX;
$only_meet = getenv('meet_id') ?: null;
$only_term = getenv('term') ? intval(getenv('term')) : null;
$state_dir = __DIR__ . '/speech-state';
$html_dir = __DIR__ . '/agenda-tikahtml';
if (!$dry and !file_exists($state_dir)) {
    mkdir($state_dir, 0775, true);
}

// ---- index 不存在就建（text 用 standard analyzer，中文逐字切，match_phrase 可用）----
if (!$dry) {
    try {
        Elastic::dbQuery('/{prefix}speech/_mapping');
    } catch (Exception $e) {
        $kw = ['type' => 'keyword'];
        $int = ['type' => 'integer'];
        Elastic::createIndex('speech', ['properties' => [
            'id' => $kw, 'lcidc_id' => $kw, 'gazette_id' => $kw, 'agenda_id' => $kw, 'meet_id' => $kw, 'meet_type' => $kw,
            'term' => $int, 'sessionPeriod' => $int, 'committees' => $int, 'date' => ['type' => 'date'],
            'seq' => $int, 'block_line' => $int, 'kind' => $kw,
            'section' => ['type' => 'text', 'fields' => ['keyword' => ['type' => 'keyword', 'ignore_above' => 256]]],
            'speaker_raw' => $kw, 'speaker_name' => $kw, 'speaker_role' => $kw, 'speaker_title' => $kw,
            'turn_seq' => $int, 'turn_speaker' => $kw,
            'text' => ['type' => 'text'], 'chars' => $int,
            'time_start' => ['type' => 'date'], 'time_source' => $kw, 'clip_id' => $int,
            'video' => ['properties' => ['ivod_id' => $int, 'offset' => $int]],
            'page_start' => $int, 'page_end' => $int, 'source_url' => $kw, 'imported_at' => ['type' => 'date'],
        ]]);
        error_log('created speech index');
    }
}

// ---- 收集要處理的公報子文件：lcidc_id → 會議與公報發言紀錄 ----
$must = [['exists' => ['field' => '公報發言紀錄']]];
if ($only_meet) $must[] = ['term' => ['meet_id.keyword' => $only_meet]];
if ($only_term) $must[] = ['term' => ['term' => $only_term]];
if (getenv('meet_type')) $must[] = ['term' => ['meet_type.keyword' => getenv('meet_type')]];
$targets = [];
$ret = Elastic::dbQuery('/{prefix}meet/_search?scroll=5m', 'POST', json_encode([
    'size' => 500, 'query' => ['bool' => ['must' => $must]], 'sort' => [['meet_id.keyword' => 'desc']],
    '_source' => ['meet_id', 'meet_type', 'term', 'sessionPeriod', 'committees', 'dates',
        '公報發言紀錄.agenda_id', '公報發言紀錄.gazette_id', '公報發言紀錄.agenda_lcidc_ids', '公報發言紀錄.meetingDate',
        '公報發言紀錄.page_start', '公報發言紀錄.page_end'],
]));
while (count($ret->hits->hits)) {
    foreach ($ret->hits->hits as $hit) {
        $meet = $hit->_source;
        foreach ($meet->{'公報發言紀錄'} ?? [] as $record) {
            foreach ($record->agenda_lcidc_ids ?? [] as $lcidc_id) {
                if (!isset($targets[$lcidc_id])) {
                    $targets[$lcidc_id] = ['meets' => [], 'record' => $record];
                }
                $targets[$lcidc_id]['meets'][$meet->meet_id] = $meet;
            }
        }
    }
    $ret = Elastic::dbQuery('/_search/scroll', 'POST', json_encode(['scroll' => '5m', 'scroll_id' => $ret->_scroll_id]));
}
error_log(sprintf("%d 份公報子文件待檢查", count($targets)));

// ---- 每場會議的 Clip／Full（時間錨點用）----
$ivod_cache = [];
$get_ivods = function($meet_id) use (&$ivod_cache) {
    if (isset($ivod_cache[$meet_id])) return $ivod_cache[$meet_id];
    $clips = []; $fulls = [];
    $ret = Elastic::dbQuery('/{prefix}ivod/_search', 'POST', json_encode([
        'size' => 1000, 'query' => ['term' => ['meet.id.keyword' => $meet_id]],
        '_source' => ['id', 'type', '委員名稱', 'start_time', 'end_time'],
    ]));
    foreach ($ret->hits->hits as $hit) {
        $v = $hit->_source;
        if (empty($v->start_time)) continue;
        if ($v->type == 'Full') {
            $fulls[] = ['id' => $v->id, 'start' => $v->start_time, 'end' => $v->end_time];
        } else {
            $clips[] = ['id' => $v->id, 'name' => str_replace(' ', '', $v->{'委員名稱'} ?? ''), 'start' => $v->start_time, 'end' => $v->end_time];
        }
    }
    return $ivod_cache[$meet_id] = [$clips, $fulls];
};

$done = 0; $skipped = 0; $docs_total = 0; $errors = 0; $now = date('c');
$kinds_total = []; $time_total = [];
foreach ($targets as $lcidc_id => $target) {
    if ($done >= $limit) break;
    $path = sprintf('%s/LCIDC01_%s.doc.html', $html_dir, $lcidc_id);
    $html = file_exists($path) ? file_get_contents($path) : false;
    if ($html === false or strlen($html) < 100) {
        $html = @file_get_contents(sprintf('https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_%s.doc.html', $lcidc_id));
    }
    if ($html === false or strlen($html) < 100) {
        error_log("{$lcidc_id}: 找不到 tikahtml");
        $errors ++;
        continue;
    }
    $md5 = md5($html);
    $state_file = "{$state_dir}/{$lcidc_id}.json";
    $meet_ids = array_keys($target['meets']);
    sort($meet_ids);
    if (!$force and file_exists($state_file)) {
        $state = json_decode(file_get_contents($state_file), true);
        if (($state['md5'] ?? '') === $md5 and ($state['version'] ?? 0) === GazetteSpeechParser::VERSION
            and ($state['meets'] ?? []) === $meet_ids) {
            $skipped ++;
            continue;
        }
    }

    $meet = $target['meets'][$meet_ids[0]];
    $record = $target['record'];
    $clips = []; $fulls = [];
    foreach ($meet_ids as $mid) {
        [$c, $f] = $get_ivods($mid);
        $clips = array_merge($clips, $c); $fulls = array_merge($fulls, $f);
    }
    $meta = [
        'lcidc_id' => $lcidc_id,
        'gazette_id' => $record->gazette_id ?? explode('_', $lcidc_id)[0],
        'agenda_id' => $record->agenda_id ?? null,
        'meet_id' => count($meet_ids) == 1 ? $meet_ids[0] : $meet_ids,
        'meet_type' => $meet->meet_type ?? null,
        'term' => isset($meet->term) ? intval($meet->term) : null,
        'sessionPeriod' => isset($meet->sessionPeriod) ? intval($meet->sessionPeriod) : null,
        'committees' => array_map('intval', (array)($meet->committees ?? [])),
        'page_start' => $record->page_start ?? null,
        'page_end' => $record->page_end ?? null,
        'source_url' => sprintf('https://lydata.ronny-s3.click/agenda-tikahtml/LCIDC01_%s.doc.html', $lcidc_id),
        'clips' => $clips,
        'fulls' => $fulls,
    ];
    try {
        [$docs, $info] = GazetteSpeechParser::build($html, $meta);
    } catch (Throwable $e) {
        error_log("{$lcidc_id}: 解析失敗 " . $e->getMessage());
        $errors ++;
        if (!$dry) {
            file_put_contents($state_file, json_encode(['md5' => $md5, 'version' => GazetteSpeechParser::VERSION, 'meets' => $meet_ids, 'error' => $e->getMessage(), 'imported_at' => $now]));
        }
        continue;
    }
    if (!$info['date']) {
        // 公報文件沒有時間行時用公報發言紀錄的日期
        $info['date'] = $record->meetingDate[0] ?? ($meet->dates[0] ?? null);
        foreach ($docs as &$d) $d['date'] = $info['date'];
        unset($d);
    }
    foreach ($info['kinds'] as $k => $v) $kinds_total[$k] = ($kinds_total[$k] ?? 0) + $v;
    foreach ($info['time'] as $k => $v) $time_total[$k] = ($time_total[$k] ?? 0) + $v;

    if ($dry) {
        printf("%s %s %s %s: %d blocks, %d turns (%d with clip), kinds=%s, time=%s, clips=%d fulls=%d\n", $lcidc_id, implode(',', $meet_ids), $info['date'], $info['chair'],
            $info['blocks'], $info['turns'], $info['turns_with_clip'], json_encode($info['kinds'], JSON_UNESCAPED_UNICODE), json_encode($info['time']), count($clips), count($fulls));
    } else {
        Elastic::dbQuery('/{prefix}speech/_delete_by_query?conflicts=proceed&refresh=true', 'POST', json_encode(['query' => ['term' => ['lcidc_id' => $lcidc_id]]]));
        Elastic::$_bulk_errors = 0;
        foreach ($docs as $doc) {
            $doc['imported_at'] = $now;
            Elastic::dbBulkInsert('speech', $doc['id'], $doc);
        }
        Elastic::dbBulkCommit('speech');
        if (Elastic::$_bulk_errors) {
            // 寫入失敗（例如欄位值超出 mapping 範圍）：不留狀態檔，下次執行會重做
            error_log("{$lcidc_id}: 寫入 ES 失敗 " . Elastic::$_bulk_errors . " 筆");
            $errors ++;
            if (file_exists($state_file)) unlink($state_file);
            continue;
        }
        file_put_contents($state_file, json_encode(['md5' => $md5, 'version' => GazetteSpeechParser::VERSION, 'meets' => $meet_ids, 'blocks' => count($docs), 'imported_at' => $now]));
    }
    $docs_total += count($docs);
    $done ++;
}
if (!$dry) {
    Elastic::dbBulkCommit();
    if ($done) {
        Importer::addImportLog([
            'event' => 'speech-import',
            'group' => 'gazette',
            'message' => sprintf("公報發言索引：更新 %d 份文件、%d 筆發言（略過 %d、失敗 %d）", $done, $docs_total, $skipped, $errors),
        ]);
    }
}
error_log(sprintf("處理 %d 份、%d 筆；略過 %d；失敗 %d；kinds=%s；time=%s", $done, $docs_total, $skipped, $errors,
    json_encode($kinds_total, JSON_UNESCAPED_UNICODE), json_encode($time_total)));
