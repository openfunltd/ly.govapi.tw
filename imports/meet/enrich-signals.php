<?php
/**
 * enrich-signals.php：把會議散在其他 index 的「狀態訊號」算好寫回 meet 的 signals 欄位。
 *
 * 網站要畫一筆會議的狀態（已排定／AI 轉錄稿產生中／AI 轉錄稿／公報定稿）、在做什麼（審預算）、表決幾次，
 * 本來要分別查 ivod（有沒有 Full、有沒有 ai-transcript）、speech（有沒有公報發言）、meet_section（切分器、標籤），
 * 列 100 場就要打二十幾次 API。這支每輪用三個 terms agg 一次算完，再 bulk update 寫回 meet。
 *
 * meet 的寫入（link-meet.php）是 update + doc_as_upsert，只會覆蓋它自己有的欄位，signals 不會被洗掉。
 *
 * 用法：
 *   php enrich-signals.php              # 最近 60 天有開的會議（cron/3hr/meet.php 鏈尾）
 *   days=3 php enrich-signals.php       # import-ivod 之後跑，剛上架的影片 5 分鐘內反映在狀態上
 *   all=1 php enrich-signals.php        # 全部會議（第一次回填）
 *
 * signals 欄位（v2 API 對照名稱在 LYAPI/Type/Meet.php）：
 *   full_ivods [int]        Full 影片的 IVOD_ID，依開始時間
 *   ai_transcript bool      任一支 Full 的 features 含 ai-transcript
 *   speech_count int        公報發言索引（speech）的筆數
 *   has_speech bool         speech_count > 0（黨團協商沒有 公報發言紀錄 欄位，網站靠這個判斷公報定稿）
 *   segmenters [string]     meet_section 的切分器（去重；委員會同一場可能預算日與非預算日各一個）
 *   vote_sections int       標籤含「表決」的段數
 *   third_reading_sections  標籤含「三讀」的段數
 *   updated_at              這次計算時間
 */
include(__DIR__ . '/../../init.inc.php');

$days = intval(getenv('days') ?: 60);
$all = (bool)getenv('all');
$batch = 500;

// 1. 要處理哪些會議
if ($all) {
    $query = ['match_all' => new stdClass];
} else {
    $query = ['range' => ['dates' => ['gte' => date('Y-m-d', strtotime("-{$days} days"))]]];
}
$meet_ids = [];
$ret = Elastic::dbQuery('/{prefix}meet/_search?scroll=5m', 'POST', json_encode([
    'size' => 1000,
    'query' => $query,
    '_source' => ['meet_id'],
]));
while (true) {
    foreach ($ret->hits->hits ?? [] as $hit) {
        $meet_ids[] = $hit->_source->meet_id ?? $hit->_id;
    }
    if (!count($ret->hits->hits ?? [])) {
        break;
    }
    $ret = Elastic::dbQuery('/_search/scroll', 'POST', json_encode(['scroll' => '5m', 'scroll_id' => $ret->_scroll_id]));
}
$meet_ids = array_values(array_unique($meet_ids));
error_log(sprintf('enrich-signals: %d meets (%s)', count($meet_ids), $all ? 'all' : "{$days} days"));

$now = date('c');
$updated = 0;
foreach (array_chunk($meet_ids, $batch) as $ids) {
    $signals = [];
    foreach ($ids as $id) {
        $signals[$id] = [
            'full_ivods' => [],
            'ai_transcript' => false,
            'speech_count' => 0,
            'has_speech' => false,
            'segmenters' => [],
            'vote_sections' => 0,
            'third_reading_sections' => 0,
            'updated_at' => $now,
        ];
    }

    // 2a. ivod：Full 影片
    $ret = Elastic::dbQuery('/{prefix}ivod/_search', 'POST', json_encode([
        'size' => 10000,
        'query' => ['bool' => ['filter' => [
            ['term' => ['type.keyword' => 'Full']],
            ['terms' => ['meet.id.keyword' => $ids]],
        ]]],
        '_source' => ['id', 'meet.id', 'features', 'start_time'],
        'sort' => [['start_time' => 'asc']],
    ]));
    foreach ($ret->hits->hits ?? [] as $hit) {
        $s = $hit->_source;
        $mid = $s->meet->id ?? null;
        if (!isset($signals[$mid])) {
            continue;
        }
        $signals[$mid]['full_ivods'][] = intval($s->id);
        if (in_array('ai-transcript', (array)($s->features ?? []))) {
            $signals[$mid]['ai_transcript'] = true;
        }
    }

    // 2b. speech：公報發言筆數
    $ret = Elastic::dbQuery('/{prefix}speech/_search', 'POST', json_encode([
        'size' => 0,
        'query' => ['terms' => ['meet_id' => $ids]],
        'aggs' => ['m' => ['terms' => ['field' => 'meet_id', 'size' => $batch * 2]]],
    ]));
    foreach ($ret->aggregations->m->buckets ?? [] as $b) {
        if (isset($signals[$b->key])) {
            $signals[$b->key]['speech_count'] = intval($b->doc_count);
            $signals[$b->key]['has_speech'] = $b->doc_count > 0;
        }
    }

    // 2c. meet_section：切分器、表決與三讀的段數
    $ret = Elastic::dbQuery('/{prefix}meet_section/_search', 'POST', json_encode([
        'size' => 0,
        'query' => ['terms' => ['meet_id' => $ids]],
        'aggs' => ['m' => [
            'terms' => ['field' => 'meet_id', 'size' => $batch * 2],
            'aggs' => [
                'seg' => ['terms' => ['field' => 'segmenter', 'size' => 10]],
                'tags' => ['terms' => ['field' => 'tags', 'include' => ['表決', '三讀'], 'size' => 10]],
            ],
        ]],
    ]));
    foreach ($ret->aggregations->m->buckets ?? [] as $b) {
        if (!isset($signals[$b->key])) {
            continue;
        }
        foreach ($b->seg->buckets ?? [] as $sb) {
            $signals[$b->key]['segmenters'][] = $sb->key;
        }
        foreach ($b->tags->buckets ?? [] as $tb) {
            if ($tb->key === '表決') {
                $signals[$b->key]['vote_sections'] = intval($tb->doc_count);
            } elseif ($tb->key === '三讀') {
                $signals[$b->key]['third_reading_sections'] = intval($tb->doc_count);
            }
        }
    }

    // 3. 寫回 meet（update，不動其他欄位）
    foreach ($signals as $mid => $sig) {
        Elastic::dbBulkInsert('meet', $mid, ['signals' => $sig]);
        $updated ++;
    }
    Elastic::dbBulkCommit();
}
error_log("enrich-signals: updated {$updated} meets");
