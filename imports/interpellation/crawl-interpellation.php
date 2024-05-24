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
                throw new Exception("{$hit->_id} has different meetingNo: {$meetingNo} vs {$meet_data->meetingNo}");
            }
        }
    }

    if (array_key_exists($meetingNo, $showed_meets)) {
        continue;
    }
    $showed_meets[$meetingNo] = true;
    $target = __DIR__ . "/files/{$meetingNo}-0.pdf";
    if (!file_exists($target)) {
        $content = Importer::getURL($meet_data->ppg_url);
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
            file_put_contents($target, "no data in url: {$meet_data->ppg_url}");
            continue;
        }
        $data_dom = $doc->getElementById($id);
        $files = [];
        foreach ($data_dom->getElementsByTagName('div') as $div_dom) {
            if ($div_dom->getAttribute('class') !== 'card-body') {
                continue;
            }
            if (strpos($div_dom->nodeValue, '關係文書') === false) {
                continue;
            }
            $a_dom = $div_dom->getElementsByTagName('a')->item(0);
            $files[] = $a_dom->getAttribute('href');
        }

        foreach ($files as $idx => $file) {
            $target = __DIR__ . "/files/{$meetingNo}-{$idx}.pdf";
            system(sprintf("wget -4 -O %s %s", escapeshellarg($target), escapeshellarg($file)));
            // TODO: 應該要全部下載完再一次存入，這樣比較不會下載一半
        }
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
        file_put_contents(__DIR__ . '/tmp.txt', $content);
        error_log("{$file} {$meet_data->ppg_url}");
        $info = GazetteParser::parseInterpellation($content);
        foreach ($info->interpellations as $interpellation) {
            $interpellation->meet_id = $hit->_id;
            $interpellation->meetingNo = $meetingNo;
            $interpellation->term = $info->term;
            $interpellation->sessionPeriod = $info->sessionPeriod;
            $interpellation->sessionTimes = $info->sessionTimes;
            Elastic::dbBulkInsert('interpellation', $interpellation->id, $interpellation);
        }
    }
}
Elastic::dbBulkCommit();
