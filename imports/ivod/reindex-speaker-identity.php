<?php

// 對已有 speaker identity 的 Clip/Full，用時間重疊比對（IVodParser::alignPyannoteIdentities）
// 修正 ivod-transcript 的 SPEAKER_XX 對應到人的身分，更新 ivod-data 中的
// transcript.pyannote（speaker_name / speaker_score），並重新 index 進 ES。
//
// ivod-pyannote/{id}.json 的 embedding 可能來自獨立重跑的 pyannote
// （見 backfill-pyannote-run.php），SPEAKER_XX 編號跟 ivod-transcript 的原始
// diarization 不保證相同，舊版直接假設編號相同、會貼錯身分——本版一律
// 重新計算並覆蓋，不會因為「已經有 speaker_name」就跳過。
//
// 同時會把每個 speaker cluster 的原始 embedding 存進 ivod-data/ES 的
// transcript.speakers（label+embedding+total_seconds）。這樣日後調整
// IVodParser::identifySpeaker 的門檻或 speaker_identity 新增聲紋時，直接對 ES 既有 embedding
// 做 kNN 重新比對即可（見 recompute-speaker-identity.php），不需要再重新
// 掃過 ivod-pyannote/*.json 本地檔案、更不需要重新跑 ASR/pyannote。
//
// 使用：
//   php reindex-speaker-identity.php
//   limit=500 php reindex-speaker-identity.php
//   dry_run=1 limit=20 php reindex-speaker-identity.php   # 只印出變更，不寫檔不寫 ES
//   only_ids=17742,17566 php reindex-speaker-identity.php # 只處理指定 ID

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/IVodParser.php');

// config.php 的 https_proxy 是給 ivod.ly.gov.tw 下載專用（process-wide putenv，
// 會被 curl 自動套用到「所有」https 請求）；這支只打 ES，清掉避免 Elastic 連線
// 被不相關的 proxy 拖慢／搞壞（實測同一個 bulk commit 走 proxy 會失敗或卡住，
// 清掉後 0.4 秒完成）。
putenv('https_proxy');

$ivod_dir = __DIR__;
$limit    = intval(getenv('limit') ?: 0);
$dry_run  = (bool)getenv('dry_run');
$only_ids = getenv('only_ids') ? array_flip(explode(',', getenv('only_ids'))) : null;
$done = $updated = $skipped = $errors = 0;

$files = glob($ivod_dir . '/ivod-pyannote/*.json');
rsort($files); // 最新優先

foreach ($files as $pyann_path) {
    if ($limit && $done >= $limit) break;

    $id = basename($pyann_path, '.json');
    if (!ctype_digit($id)) continue;  // 排除 research-*.json 等非 ID 檔名
    if ($only_ids !== null && !isset($only_ids[$id])) continue;
    $v = intval($id);

    $data_path = $ivod_dir . "/ivod-data/{$v}.json";
    if (!file_exists($data_path)) { $skipped++; continue; }

    $pyann = json_decode(file_get_contents($pyann_path));
    if (!$pyann || empty($pyann->speakers)) { $errors++; continue; }

    $has_identity = false;
    foreach ($pyann->speakers as $spk) {
        if (!empty($spk->identity)) { $has_identity = true; break; }
    }

    $ivod = json_decode(file_get_contents($data_path));
    if (!$ivod) { $errors++; continue; }

    $pyannote_segs = $ivod->transcript->pyannote ?? null;
    if (!$pyannote_segs) { $skipped++; continue; }

    // 院會議事人員（宣讀人員）唸稿區段：內容模式偵測，跟是否比對到委員身分無關，
    // 所以即使這支影片沒有任何 speaker identity 也要照跑（見 findReadingWindows 說明）。
    $reading_windows = IVodParser::findReadingWindows($ivod->transcript->whisperx ?? []);

    $transcript_segs = [];
    foreach ($pyannote_segs as $seg) {
        $transcript_segs[] = [$seg->start, $seg->end, $seg->speaker];
    }

    $identities = [];
    $meet_id = $ivod->meet->id ?? '';
    $date    = $ivod->date ?? '';
    if ($has_identity) {
        // 主席／召委跟一般委員共用同一套 speaker_identity（存的是本人聲紋，不分角色）；
        // 另外查這場會議公報記載當天出現過的主席名單（可能不只一位，見
        // IVodParser::getSessionChair 說明），比對到的人在名單內才加 is_chair。
        $identities = IVodParser::alignPyannoteIdentities($transcript_segs, $pyann->speakers);
    }

    // 身分一律以 kNN（IVodParser::identifySpeaker）為準：委員、官員、宣讀人員（role=reader）
    // 都走同一條路。「請宣讀→完畢」內容規則只在 kNN 完全沒比對到身分的 speaker 上當
    // fallback——同一支影片同一個 SPEAKER_XX 就是同一個人，只要有一段落在宣讀視窗內，
    // 這個編號的所有段落都算宣讀人員（見 IVodParser::findReadingSpeakerLabels）。
    $reading_labels = array_diff_key(
        IVodParser::findReadingSpeakerLabels($transcript_segs, $reading_windows),
        $identities
    );

    // 把 embedding 存進 transcript.speakers，供 recompute-speaker-identity.php 日後快速重算。
    // `speaker` 存的是對齊後的 transcript label（跟 transcript.pyannote[].speaker 同一套編號），
    // `embed_id` 才是 ivod-pyannote 原始編號——舊影片的 embedding 來自另一次獨立 pyannote，
    // 兩套編號不同，沒對齊上的 embedding speaker 不存（對不回逐字稿也沒用）。
    $label_by_eid = array_flip(IVodParser::alignPyannoteSpeakers($transcript_segs, $pyann->speakers));
    $speakers_field = [];
    foreach ($pyann->speakers as $spk) {
        if (empty($spk->embedding)) continue;
        if (!isset($label_by_eid[$spk->id])) continue;
        $speakers_field[] = [
            'speaker'       => $label_by_eid[$spk->id],
            'embed_id'      => $spk->id,
            'embedding'     => $spk->embedding,
            'total_seconds' => $spk->total_seconds ?? null,
        ];
    }
    $speakers_changed = json_encode($ivod->transcript->speakers ?? null) !== json_encode($speakers_field ?: null);
    if ($speakers_changed) {
        $ivod->transcript->speakers = $speakers_field;
    }

    if (!$reading_labels && !$identities && !$speakers_changed) { $skipped++; continue; }

    $changed = false;
    foreach ($pyannote_segs as $seg) {
        if (isset($identities[$seg->speaker])) {
            $new_name    = $identities[$seg->speaker]->label;
            $new_score   = $identities[$seg->speaker]->score;
            $new_is_chair = IVodParser::isSessionChair($meet_id, $date, $identities[$seg->speaker]->person_name) ? true : null;
            if (($seg->speaker_name ?? null) !== $new_name
                || ($seg->speaker_score ?? null) !== $new_score
                || ($seg->is_chair ?? null) !== $new_is_chair) {
                $seg->speaker_name  = $new_name;
                $seg->speaker_score = $new_score;
                if ($new_is_chair) {
                    $seg->is_chair = true;
                } else {
                    unset($seg->is_chair);
                }
                $changed = true;
            }
        } elseif (isset($reading_labels[$seg->speaker])) {
            // kNN 沒比對到任何身分時的 fallback：內容規則判斷為宣讀人員
            if (($seg->speaker_name ?? null) !== '宣讀人員' || isset($seg->speaker_score) || isset($seg->is_chair)) {
                $seg->speaker_name = '宣讀人員';
                unset($seg->speaker_score, $seg->is_chair);
                $changed = true;
            }
        } elseif (isset($seg->speaker_name)) {
            // 之前（編號對不上時）誤貼的身分，清掉
            unset($seg->speaker_name, $seg->speaker_score, $seg->is_chair);
            $changed = true;
        }
    }

    if (!$changed && !$speakers_changed) { $skipped++; continue; }

    if ($dry_run) {
        $names = [];
        foreach ($pyannote_segs as $seg) {
            if (isset($seg->speaker_name)) $names[$seg->speaker] = $seg->speaker_name;
        }
        echo "[dry] {$v}: " . json_encode($names, JSON_UNESCAPED_UNICODE) . "\n";
        $updated++; $done++;
        continue;
    }

    // 存回 ivod-data
    file_put_contents($data_path, json_encode($ivod, JSON_UNESCAPED_UNICODE));

    // 重新 index 進 ES
    Elastic::dbBulkInsert('ivod', $ivod->id, $ivod);
    $updated++;
    $done++;

    if ($done % 500 === 0) {
        Elastic::dbBulkCommit();
        error_log("reindex-speaker-identity: {$done} done, {$updated} updated");
    }
}

if (!$dry_run) Elastic::dbBulkCommit();
error_log("reindex-speaker-identity: done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}");
echo "done={$done}, updated={$updated}, skipped={$skipped}, errors={$errors}\n";
