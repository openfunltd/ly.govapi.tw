<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');

$skip_files = [
            "201204066-1.pdf", // https://ppg.ly.gov.tw/ppg/SittingRelatedDocumentQuestionsEyReply/download/agenda1/02/pdf/08/01/06/LCEWA01_080106_00138.pdf
            '201302270-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/201302270/details?meetingDate=102/03/01
            '2015091507-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2015091507/details?meetingDate=104/09/18

            // 以下應該是缺標題，有機會修正的 TODO
            '2016121401-1.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2016121401/details?meetingDate=105/12/16
            '2019120401-1.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2019120401/details?meetingDate=108/12/06
            '2023052495-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2023052495/details?meetingDate=112/05/26
            '2024041742-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/2024041742/details?meetingDate=113/04/23

            // 直接把公報丟過來了，沒有編號
            '2017030201-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2017030201/details?meetingDate=106/03/03
            '2023010401-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2023010401/details?meetingDate=112/01/09'
            '2017092103-12.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2017092103/details?meetingDate=106/09/22
            '2018112701-0.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2018112701/details?meetingDate=107/11/30
            '2018112701-1.pdf', // https://ppg.ly.gov.tw/ppg/sittings/yuan-sittings/2018112701/details?meetingDate=107/11/30
];

function norm_text($s) { return preg_replace('/\s+/u', '', (string)$s); }
// 委員名字只比字母：原住民名字的分隔符在不同 PDF 字型下會變（〄／‧／．／・）
function norm_names($names) { return array_map(fn($n) => preg_replace('/[^\p{L}\p{N}]+/u', '', $n), (array)$names); }
// 同一則質詢在不同次院會重刊，文字只差斷行空白或舊字型 OCR（貣／起、尌／就）；委員相同且案由去空白後幾乎一樣就當同一則
function same_interpellation($a, $b)
{
    if (norm_names($a->legislators ?? []) != norm_names($b->legislators ?? [])) return false;
    $x = norm_text($a->reason ?? ''); $y = norm_text($b->reason ?? '');
    if ($x === $y) return true;
    similar_text($x, $y, $pct);
    return $pct >= 85;
}

// 一份關係文書 PDF → 解析 → 併入「屆-會期-編號」的紀錄
function process_pdf($file, $meet_id, $meetingNo, $page_url)
{
    global $skip_files;
    if (in_array(basename($file), $skip_files)) return;
    $cmd = sprintf("pdftotext -layout %s -", escapeshellarg($file));
    $content = `$cmd`;
    error_log("{$file} " . ($page_url ?? '(no page)'));
    $info = GazetteParser::parseInterpellation($content);
    // 原始來源：PPG 院會頁面與關係文書 PDF 網址（files/*.pdf.url 有紀錄的才有）。
    // 原始資料本身有錯（例如印發日期漏字）時不在這裡修，靠這兩個欄位回報給立法院
    $source_pdf = file_exists($file . '.url') ? trim(file_get_contents($file . '.url')) : null;
    foreach ($info->interpellations as $parsed) {
        $parts = explode('-', $parsed->id); // parser 給的是 屆-會期-會次-編號
        $listing = (object)[
            'meet_id' => $meet_id,
            'sessionTimes' => $info->sessionTimes,
            'meetingNo' => $meetingNo,
            'printed_at' => $parsed->printed_at ?? null,
            'page_start' => $parsed->page_start,
            'page_end' => $parsed->page_end,
            'source_page' => $page_url,
            // #page= 用 PDF 實體頁（parser 數換頁符算的 pdf_page）。page_start 是關係文書頁碼「質 N」，不是 PDF 頁
            'source_pdf' => $source_pdf ? $source_pdf . '#page=' . intval($parsed->pdf_page ?: 1) : null,
        ];
        store_interpellation($info->term, $info->sessionPeriod, intval($parts[3]), $parsed, $listing);
    }
}

// id 是「屆-會期-編號」（質詢編號每會期連續）。同一則若在之後的院會關係文書再刊一次，只在 listings 多一筆；
// 編號相同、內容卻不同（來源編號錯，第 8 屆有幾組）的後出現者 id 加「-會次」後綴
function store_interpellation($term, $sessionPeriod, $no, $parsed, $listing)
{
    global $data_dir, $dry, $rebuild;
    $base = "{$term}-{$sessionPeriod}-{$no}";
    $record = null;
    $candidates = array_merge(
        file_exists("{$data_dir}/{$base}") ? ["{$data_dir}/{$base}"] : [],
        glob("{$data_dir}/{$base}-*") ?: []
    );
    foreach ($candidates as $f) {
        $r = json_decode(file_get_contents($f));
        if (same_interpellation($r, $parsed)) { $record = $r; break; }
    }
    $is_new = false;
    if (is_null($record)) {
        $is_new = true;
        $record = (object)[
            'id' => count($candidates) ? "{$base}-{$listing->sessionTimes}" : $base,
            'term' => $term, 'sessionPeriod' => $sessionPeriod, 'no' => $no,
            'legislators' => $parsed->legislators ?? [], 'reason' => $parsed->reason ?? null, 'description' => $parsed->description ?? null,
            'listings' => [],
        ];
    }
    $before = json_encode($record, JSON_UNESCAPED_UNICODE);
    $listings = array_values(array_filter($record->listings, fn($l) => $l->meet_id !== $listing->meet_id));
    $listings[] = $listing;
    usort($listings, fn($a, $b) => [$a->sessionTimes, $a->printed_at ?? ''] <=> [$b->sessionTimes, $b->printed_at ?? '']);
    $record->listings = $listings;
    $record->meet_ids = array_values(array_unique(array_column($listings, 'meet_id')));
    // 頂層欄位（含文字）以最早刊登那次為準；之後的重刊只影響 listings
    $first = $listings[0];
    if ($first->meet_id === $listing->meet_id) {
        $record->legislators = $parsed->legislators ?? [];
        $record->reason = $parsed->reason ?? null;
        $record->description = $parsed->description ?? null;
    }
    foreach (['meet_id', 'sessionTimes', 'meetingNo', 'printed_at', 'page_start', 'page_end', 'source_page', 'source_pdf'] as $k) {
        $record->{$k} = $first->{$k};
    }
    // 來自關係文書：若先前由議事錄補錄過（supplement-from-proceedings.php，編號是推算的），這裡接手
    if ($is_new) {
        foreach (glob("{$data_dir}/{$term}-{$sessionPeriod}-*") as $f) {
            $r = json_decode(file_get_contents($f));
            if (!empty($r->no_inferred) and same_interpellation($r, $parsed)) {
                error_log("議事錄補錄的 {$r->id} 由關係文書 {$record->id} 接手" . ($r->id !== $record->id ? "（推算編號不同，刪除舊紀錄）" : ""));
                unlink($f);
                if (!$dry and $r->id !== $record->id) Elastic::dbQuery("/{prefix}interpellation/_doc/{$r->id}", 'DELETE');
            }
        }
    }
    $record->source = '關係文書';
    unset($record->no_inferred);
    $after = json_encode($record, JSON_UNESCAPED_UNICODE);
    if (!$is_new and $after === $before) return;
    if ($is_new and !$rebuild and !$dry) {
        Importer::addImportLog([
            'event' => 'interpellation-new',
            'group' => 'interpellation',
            'message' => sprintf("新增質詢(%s) %s", $record->id, mb_substr($record->reason ?? '', 0, 60)),
            'data' => json_encode([
                'id' => $record->id,
                '質詢委員' => $record->legislators,
                '事由' => $record->reason ?? '',
                '會議編號' => $listing->meetingNo,
            ], JSON_UNESCAPED_UNICODE),
        ], $commit = false);
    } elseif (!$is_new and !$rebuild) {
        error_log(($listing->meet_id === $first->meet_id ? "書面質詢文字變動，警告！" : "重刊，加入 listing：") . "{$record->id} ({$listing->meet_id})");
    }
    file_put_contents("{$data_dir}/{$record->id}", $after);
    if (!$dry) {
        Elastic::dbBulkInsert('interpellation', $record->id, $record);
    }
}
// 參數：
//   min_term=8   處理到第 8 屆（預設 10；回填舊屆用）
//   rebuild=1    砍掉 interpellation-data/ 與 ES 的全部質詢後從本機 PDF 重建（id 制度改變時用；不發 import log）
//   dry=1        不寫 ES、不發 import log；資料寫到 data_dir（預設 /tmp/interpellation-dry），用來試跑
//   data_dir=…   覆蓋資料目錄
$dry = (bool)getenv('dry');
$rebuild = (bool)getenv('rebuild');
$min_term = intval(getenv('min_term') ?: 10);
$data_dir = getenv('data_dir') ?: ($dry ? sys_get_temp_dir() . '/interpellation-dry' : __DIR__ . '/interpellation-data');
if (!is_dir($data_dir)) mkdir($data_dir, 0775, true);
// 2026-11 id 從「屆-會期-會次-編號」改成「屆-會期-編號」，資料目錄用 .schema 標記版本，舊制目錄不准直接跑（要 rebuild=1）
if (!$rebuild and trim(@file_get_contents($data_dir . '/.schema')) !== '2') {
    error_log("{$data_dir} 不是新制（.schema != 2），請先 rebuild=1 min_term=8 重建");
    exit(1);
}
if ($rebuild) {
    foreach (glob($data_dir . '/*') as $f) unlink($f);
    file_put_contents($data_dir . '/.schema', "2\n");
    if (!$dry) {
        Elastic::dbQuery('/{prefix}interpellation/_delete_by_query?conflicts=proceed&refresh=true', 'POST', json_encode(['query' => ['match_all' => new StdClass]]));
    }
    error_log("rebuild: cleared {$data_dir}" . ($dry ? '' : ' and ES'));
}

$cmd = [
    'size' => 10000,
    'query' => [
        'query_string' => [
            'query' => 'meet_type.keyword:院會 AND _exists_:meet_data.meetingNo',
        ],
    ],
];
$obj = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode($cmd));
// 由舊到新處理：質詢編號是每會期連續編號，同一則在之後的院會再刊一次時要併進最早那次的紀錄，
// 編號相同但內容不同的少數例外（來源編號錯）則後出現的那筆加會次後綴，順序固定結果才固定
$hits = array_filter($obj->hits->hits, fn($h) => ($h->_source->term ?? 0) >= $min_term);
usort($hits, fn($a, $b) => [$a->_source->term, $a->_source->sessionPeriod, $a->_source->sessionTimes] <=> [$b->_source->term, $b->_source->sessionPeriod, $b->_source->sessionTimes]);
$processed_nos = [];
$showed_meets = [];
foreach ($hits as $hit) {
    $source = LYLib::buildMeet($hit->_source);

    if (!is_array($source->meet_data)) {
        echo json_encode($source, JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }

    $meetingNo = null;
    foreach ($source->meet_data as $meet_data) {
        if ($meet_data->meetingNo == '2022101901') {
            continue;
        }
        if (is_null($meetingNo)) {
            $meetingNo = $meet_data->meetingNo;
        } else {
            if ($meetingNo !== $meet_data->meetingNo) {
                echo $meet_data->ppg_url . "\n";
                continue;
                throw new Exception("{$hit->_id} has different meetingNo: {$meetingNo} vs {$meet_data->meetingNo}");
            }
        }
    }

    if (array_key_exists($meetingNo, $showed_meets)) {
        continue;
    }
    $showed_meets[$meetingNo] = true;
    $processed_nos[$meetingNo] = true;
    // 用日期最晚、且日期有效的那一天的頁面（有些 meet_data 的 date 是空的，會拼出 meetingDate=0/..）
    $page_url = null; $last_date = null;
    foreach ($source->meet_data as $md) {
        // 用 meetingDateDesc（民國 YYY/MM/DD，本來就是 PPG 網址要的格式）；meet_data.date 有些壞掉
        // （例如院會-10-1-1 存成 1911-10-28），不要信
        if (empty($md->meetingDateDesc) || !preg_match('#^(\d{2,3})/(\d{1,2})/(\d{1,2})#', $md->meetingDateDesc, $dm)) continue;
        $iso = sprintf('%04d-%02d-%02d', $dm[1] + 1911, $dm[2], $dm[3]);
        if ($last_date === null || strtotime($iso) > strtotime($last_date)) {
            $last_date = $iso;
            $page_url = sprintf("https://ppg.ly.gov.tw/ppg/sittings/%s/details?meetingDate=%d/%02d/%02d", $md->meetingNo, $dm[1], $dm[2], $dm[3]);
        }
    }
    if (!$page_url) continue;
    $target = __DIR__ . "/files/{$meetingNo}-0.pdf";
    // 質詢事項分頁是會後一段時間（常常幾週）才出現在 PPG 頁面上。以前第一次來沒看到就寫 stub
    // 然後永不重試，2025-04 起幾乎每場都卡在這裡。現在：真的 PDF（>=300B）才算抓到；stub 或空檔
    // 在會議日期 180 天內每輪重查，更舊的就不再試。
    $recent = (time() - strtotime($last_date)) < (intval(getenv('retry_days') ?: 180)) * 86400; // retry_days=700 可回補更舊的
    $have_real = file_exists($target) && filesize($target) >= 300;
    if (!$have_real && (!file_exists($target) || $recent)) {
        $content = Importer::getURL($page_url);
        $doc = new DOMDocument;
        $content = str_replace('<head>', '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">', $content);
        @$doc->loadHTML($content);
        $id = null;
        foreach ($doc->getElementsByTagName('button') as $button) {
            if (strpos($button->nodeValue, '質詢事項-本院委員質詢部分') === false) {
                continue;
            }
            $id = ltrim($button->getAttribute('data-bs-target'), '#');
        }
        if (is_null($id)) {
            file_put_contents($target, "no data in url: {$page_url}");
            continue;
        }
        $data_dom = $doc->getElementById($id);
        $files = [];
        // 分頁裡的關係文書 PDF（LCEWA01_xxx_nnnnn.pdf）全部收；LCEWA06/07 是目次，不收
        foreach ($data_dom->getElementsByTagName('a') as $a_dom) {
            $href = $a_dom->getAttribute('href');
            if (preg_match('#/download/.*LCEWA01_\d+_\d+\.pdf$#', $href) && !in_array($href, $files)) {
                $files[] = $href;
            }
        }
        if (!$files) {
            file_put_contents($target, "no document in url: {$page_url}");
            continue;
        }
        foreach ($files as $idx => $file) {
            // 用跟抓頁面同一套 curl（走 config.php 的 proxy、IPv4、重試），wget 對 PPG 常逾時
            try {
                $pdf = Importer::getURL($file, 180);
            } catch (Exception $e) {
                $pdf = '';
            }
            if (strlen($pdf) < 300 || strpos($pdf, '%PDF') !== 0) {
                error_log("download failed: {$file}");
                if ($idx === 0) continue 2; // 第一份沒拿到就整場下一輪再來；其餘缺哪份跳哪份
                continue;
            }
            file_put_contents(__DIR__ . "/files/{$meetingNo}-{$idx}.pdf", $pdf);
            file_put_contents(__DIR__ . "/files/{$meetingNo}-{$idx}.pdf.url", $file); // 記下來源，供 source_pdf 欄位
        }
        $target = __DIR__ . "/files/{$meetingNo}-0.pdf";
        clearstatcache(true, $target); // 前面 file_exists/filesize 查過 stub，PHP 會快取舊大小
    }
    if (filesize($target) < 300) {
        continue;
    }
    foreach (glob(__DIR__ . "/files/{$meetingNo}-*.pdf") as $file) {
        process_pdf($file, $hit->_id, $meetingNo, $page_url);
    }
}

// ES 的 meet 沒有 meetingNo 的院會（例：院會-8-1-6，open data 把 201204066 標成全院委員會）不會進上面的迴圈，
// 但 files/ 裡可能已有它的 PDF：補掃一次，會議代碼直接從文件標題的屆／會期／次推
foreach (glob(__DIR__ . '/files/*-0.pdf') as $file) {
    $meetingNo = substr(basename($file), 0, -6);
    if (isset($processed_nos[$meetingNo]) or filesize($file) < 300) continue;
    $first_line = trim(strtok(shell_exec('pdftotext -l 1 -layout ' . escapeshellarg($file) . ' - 2>/dev/null') ?: '', "\n"));
    if (!preg_match('#立法院第 (\d+) 屆第 (\d+) 會期第 (\d+) 次會議議案關係文書#u', $first_line, $m)) continue;
    if (intval($m[1]) < $min_term) continue;
    $meet_id = "院會-{$m[1]}-{$m[2]}-{$m[3]}";
    $processed_nos[$meetingNo] = true;
    foreach (glob(__DIR__ . "/files/{$meetingNo}-*.pdf") as $f) {
        try {
            process_pdf($f, $meet_id, $meetingNo, null);
        } catch (Exception $e) {
            error_log("{$f}: " . $e->getMessage());
        }
    }
}

if (!$dry) {
    Elastic::dbBulkCommit();
    Importer::addImportLog(null, $commit = true);
}
error_log("done: " . count(glob($data_dir . '/*')) . " interpellations in {$data_dir}");
