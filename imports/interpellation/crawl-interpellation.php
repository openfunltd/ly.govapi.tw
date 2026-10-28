<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../Importer.php');
$cmd = [
    'size' => 10000,
    'query' => [
        'query_string' => [
            'query' => 'meet_type.keyword:院會 AND _exists_:meet_data.meetingNo',
        ],
    ],
    'sort' => [
        'term' => 'desc',
        'sessionPeriod' => 'desc',
    ],
];
$obj = Elastic::dbQuery("/{prefix}meet/_search", 'GET', json_encode($cmd));
$showed_meets = [];
foreach ($obj->hits->hits as $hit) {
    $source = LYLib::buildMeet($hit->_source);

    if (!is_array($source->meet_data)) {
        echo json_encode($source, JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }
    if ($hit->_source->term < intval(getenv('min_term') ?: 10)) { // min_term=8 可一次性重跑第 8、9 屆（例如補欄位）
        break;
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
        if (in_array(basename($file), [
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
        ])) {
            continue;
        }
        $cmd = (sprintf("pdftotext -layout %s -", escapeshellarg($file)));
        $content = `$cmd`;
        //file_put_contents(__DIR__ . '/tmp.txt', $content);
        error_log("{$file} {$page_url}");
        $info = GazetteParser::parseInterpellation($content);
        // 原始來源：PPG 院會頁面（一定有）與關係文書 PDF 網址（2026-10-28 之後抓的才有紀錄）。
        // 原始資料本身有錯（例如印發日期漏字）時不在這裡修，靠這兩個欄位回報給立法院
        $source_pdf = file_exists($file . '.url') ? trim(file_get_contents($file . '.url')) : null;
        foreach ($info->interpellations as $interpellation) {
            $interpellation->meet_id = $hit->_id;
            $interpellation->meetingNo = $meetingNo;
            $interpellation->source_page = $page_url;
            $interpellation->source_pdf = $source_pdf;
            // page_start 就是 PDF 實體頁碼（已核對），加 #page= 可直接開到該頁
            $interpellation->source_pdf_url = $source_pdf ? $source_pdf . '#page=' . intval($interpellation->page_start ?: 1) : null;
            $interpellation->source_file = basename($file);
            $interpellation->term = $info->term;
            $interpellation->sessionPeriod = $info->sessionPeriod;
            $interpellation->sessionTimes = $info->sessionTimes;

            $target_file = __DIR__ . "/interpellation-data/{$interpellation->id}";
            if (!file_Exists($target_file)) {
                Importer::addImportLog([
                    'event' => 'interpellation-new',
                    'group' => 'interpellation',
                    'message' => sprintf("新增質詢(%s) %s", $interpellation->id, mb_substr($interpellation->reason ?? '', 0, 60)),
                    'data' => json_encode([
                        'id' => $interpellation->id,
                        '質詢委員' => $interpellation->legislators ?? [],
                        '事由' => $interpellation->reason ?? '',
                        '會議編號' => $meetingNo,
                    ], JSON_UNESCAPED_UNICODE),
                ], $commit = false);
            } else if (file_get_contents($target_file) == json_encode($interpellation, JSON_UNESCAPED_UNICODE)) {
                continue;
            } else {
                error_log("書面質詢資料不該變動，警告！{$interpellation->id}");
            }
            file_put_contents($target_file, json_encode($interpellation, JSON_UNESCAPED_UNICODE));
            Elastic::dbBulkInsert('interpellation', $interpellation->id, $interpellation);
        }
    }
}
Elastic::dbBulkCommit();
Importer::addImportLog(null, $commit = true);
