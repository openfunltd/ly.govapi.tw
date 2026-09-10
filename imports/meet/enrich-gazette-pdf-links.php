<?php
/**
 * enrich-gazette-pdf-links.php
 *
 * 為每個 meet-sub-data/{meet_id}.json 中的「公報發言紀錄:*」記錄
 * 加入 pdf_url, doc_url, agendarec_url, pdf_page_offset 欄位。
 *
 * 來源：
 * - meet-data/{meet_id}.json 的 ppg_data（前一次 cron 由 link-meet.php 寫入）
 * - gazette/gazette-data/{gazette_id}.json 的 sub_documents（供院會使用）
 *
 * 本腳本需在 parse-speech-from-gazette.php 之後、link-meet.php 之前執行，
 * 讓 link-meet.php 能把已加料的欄位一起寫進最終的 meet-data。
 */

include(__DIR__ . '/../../init.inc.php');

$MEET_DATA_DIR    = __DIR__ . '/meet-data';
$MEET_SUB_DIR     = __DIR__ . '/meet-sub-data';
$GAZETTE_DATA_DIR = __DIR__ . '/../gazette/gazette-data';

/**
 * 在 sub_documents 陣列中依印刷頁碼找到對應的子文件。
 * 回傳 [pdf_url, doc_url, page_start]，找不到則三者皆為 null。
 */
function find_sub(array $subs, $ps): array
{
    if ($ps === null) return [null, null, null];
    $ps_int = intval($ps);
    $extract = fn($s) => [$s['pdf_url'], $s['doc_urls'][0] ?? null, $s['page_start']];

    foreach ($subs as $sub) {
        if (($sub['page_start'] ?? null) === $ps_int) return $extract($sub);
    }
    foreach ($subs as $sub) {
        $s0 = $sub['page_start'] ?? null;
        $s1 = $sub['page_end']   ?? null;
        if ($s0 !== null && $s1 !== null && $s0 <= $ps_int && $ps_int < $s1) return $extract($sub);
    }
    foreach ($subs as $sub) {
        $s0 = $sub['page_start'] ?? null;
        $s1 = $sub['page_end']   ?? null;
        if ($s0 !== null && $s1 !== null && $s0 <= $ps_int && $ps_int <= $s1) return $extract($sub);
    }
    return [null, null, null];
}

foreach (glob($MEET_SUB_DIR . '/*.json') as $sub_file) {
    $meet_id        = basename($sub_file, '.json');
    $meet_data_file = $MEET_DATA_DIR . "/{$meet_id}.json";

    // ppg_data 來自前次 cron 寫入的 meet-data。新會議第一次跑時尚無此檔，下次再補。
    if (!file_exists($meet_data_file)) continue;

    $md = json_decode(file_get_contents($meet_data_file));

    // 從 ppg_data 的 links 建立 lcidc_to_pdf / lcidc_to_doc（委員會用）
    // 以及 fallback_pdf（院會的議事錄 PDF）
    $lcidc_to_pdf = [];
    $lcidc_to_doc = [];
    $fallback_pdf = null;
    foreach ($md->ppg_data ?? [] as $pp) {
        $pdf_url     = null;
        $doc_url_ppg = null;
        $doc_lcidc   = null;
        foreach ($pp->links ?? [] as $link) {
            $href = $link->href      ?? '';
            $ft   = $link->filetype ?? '';
            if ($ft === 'pdf') {
                if (strpos($href, 'LCIDC01_') !== false) {
                    $pdf_url = $href;
                } elseif (!$fallback_pdf &&
                          (strpos($href, 'SittingYuanInfo') !== false ||
                           strpos($href, 'agendarec') !== false)) {
                    $fallback_pdf = $href;
                }
            } elseif ($ft === 'doc') {
                if (preg_match('#LCIDC01_(\w+)\.doc#i', $href, $m)) {
                    $doc_lcidc   = $m[1];
                    $doc_url_ppg = $href;
                }
            }
        }
        if ($doc_lcidc && $pdf_url)     $lcidc_to_pdf[$doc_lcidc] = $pdf_url;
        if ($doc_lcidc && $doc_url_ppg) $lcidc_to_doc[$doc_lcidc] = $doc_url_ppg;
    }

    // 院會：延遲載入各公報的 sub_documents
    $gazette_subs = [];

    $sub_data = json_decode(file_get_contents($sub_file), true);
    $changed  = false;

    foreach ($sub_data as $key => &$rec) {
        if (strpos($key, '公報發言紀錄:') !== 0) continue;

        $lids = (array)($rec['agenda_lcidc_ids'] ?? []);
        $ps   = $rec['page_start'] ?? null;
        $gid  = $rec['gazette_id'] ?? null;

        if ($lcidc_to_pdf) {
            // 委員會：以 doc lcidc 查詢
            $pdf = null;
            foreach ($lids as $lid) {
                if (isset($lcidc_to_pdf[$lid])) { $pdf = $lcidc_to_pdf[$lid]; break; }
            }
            if (!$pdf) $pdf = $fallback_pdf;
            $doc = null;
            foreach ($lids as $lid) {
                if (isset($lcidc_to_doc[$lid])) { $doc = $lcidc_to_doc[$lid]; break; }
            }
            $agendarec  = null;
            $pdf_sub_ps = null;
        } else {
            // 院會：以 page_start 對 sub_documents 查詢
            if ($gid && !array_key_exists($gid, $gazette_subs)) {
                $gpath = $GAZETTE_DATA_DIR . "/{$gid}.json";
                $gazette_subs[$gid] = file_exists($gpath)
                    ? (json_decode(file_get_contents($gpath), true)['sub_documents'] ?? [])
                    : [];
            }
            [$pdf, $doc, $pdf_sub_ps] = find_sub($gazette_subs[$gid] ?? [], $ps);
            $agendarec = $fallback_pdf;
            if (!$pdf) $pdf = $fallback_pdf;
        }

        $pdf_offset = $pdf_sub_ps !== null
            ? ($pdf_sub_ps - 1)
            : ($ps !== null ? (intval($ps) - 1) : 0);

        $new_fields = [
            'pdf_url'         => $pdf,
            'doc_url'         => $doc,
            'agendarec_url'   => $agendarec,
            'pdf_page_offset' => $pdf_offset,
        ];
        foreach ($new_fields as $k => $v) {
            if (!array_key_exists($k, $rec) || $rec[$k] !== $v) {
                $rec[$k] = $v;
                $changed  = true;
            }
        }
    }
    unset($rec);

    if ($changed) {
        error_log("enrich-gazette-pdf-links: {$meet_id}");
        file_put_contents($sub_file, json_encode($sub_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
