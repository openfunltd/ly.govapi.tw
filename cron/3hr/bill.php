<?php

include(__DIR__ . '/../../init.inc.php');
include(__DIR__ . '/../../imports/Importer.php');

$term = getenv('term');
if (!$term) {
    $term = 11;
    putenv('term=' . $term);
}

// 從線上抓取所有資料，並存入 cache/bill-list.jsonl.$term.$date
$target_jsonl = sprintf(__DIR__ . '/../../cache/bill-list.jsonl.%02d.%d', $term, date('Ymd'));
if (true) { //!file_exists($target_jsonl)) {
    $cmd = sprintf("php %s > %s",
        escapeshellarg(__DIR__ . '/../../imports/bill/crawl-list.php'),
        escapeshellarg($target_jsonl)
    );
    system($cmd, $ret);
    if ($ret) {
        throw new Exception("{$cmd} failed");
    }
}

foreach ([
    'check-updated-bill.php',
    'crawl-entry.php',
    'crawl-doc.php',
    'parse-doc.php',
] as $script) {
    $cmd = sprintf("php %s %s",
        escapeshellarg(__DIR__ . '/../../imports/bill/' . $script),
        escapeshellarg($target_jsonl)
    );
    system($cmd);
}

$data_dir = __DIR__ . '/../../imports/bill';
$types = BillParser::getBillTypes();
$sources = BillParser::getBillSources();
$list = BillParser::getListFromFileAndDir($target_jsonl, "{$data_dir}/bill-html");

foreach ($list as $idx => $v) {
    list($filename, $time, $obj) = $v;
    list($billNo) = explode('.', $filename);

    $bill_html_path = "{$data_dir}/bill-html/{$billNo}.gz";
    $bill_data_path = "{$data_dir}/bill-data/{$billNo}.json.gz";
    $bill_doc_path = "{$data_dir}/bill-docgz/{$billNo}.doc.gz";
    $mtime = $html_mtime = filemtime($bill_html_path);
    $parsed_doc_file = [];
    if (file_exists($bill_doc_path) and filesize($bill_doc_path) == 5 and file_get_contents($bill_doc_path) == 'array') {
        $parsed_doc_file = glob("{$data_dir}/bill-doc-parsed/tikahtml/{$billNo}-*.doc.gz");
        // 如果想要重新 parse 審查報告，可以把下面這行註解取消
        //$mtime = time();
    } else if (file_exists("{$data_dir}/bill-doc-parsed/tikahtml/{$billNo}.doc.gz")) {
        $parsed_doc_file[] = "{$data_dir}/bill-doc-parsed/tikahtml/{$billNo}.doc.gz";
    } else if (file_exists("{$data_dir}/bill-doc-parsed/html/{$billNo}.doc.gz")) {
        $parsed_doc_file[] = "{$data_dir}/bill-doc-parsed/html/{$billNo}.doc.gz";
    }

    if ($parsed_doc_file) {
        foreach ($parsed_doc_file as $f) {
            $mtime = max($mtime, filemtime($f));
        }
    }
    if (file_exists($bill_data_path) and fileatime($bill_data_path) >= $mtime) {
        continue;
    }

    error_log($idx . ' ' . $billNo);
    $content = gzdecode(file_get_contents($bill_html_path));
    try {
        $values = BillParser::parseBillDetail($billNo, $content);
    } catch (Exception $e) {
        error_log("{$billNo} error: " . $e->getMessage());
        continue;
    }
    $values->{'議案類別'} = $types[$obj->billType];
    $values->{'提案來源'} = $sources[$obj->proposalType];
    if ($billNo == '1020304070200400') {
        $values->{'提案人'} = ['親民黨立法院黨團', '李桐豪'];
    }
    $values = BillParser::addBillInfo($values);
    $values->mtime = date('c', $html_mtime);
    if (!count($parsed_doc_file)) {
        $content = null;
    } elseif (count($parsed_doc_file) > 1) {
        $content = '';
        foreach ($parsed_doc_file as $f) {
            $content .= gzdecode(file_get_contents($f));
        }
    } elseif (strpos($parsed_doc_file[0], 'tikahtml')) {
        $content = gzdecode(file_get_contents($parsed_doc_file[0]));
    } else if (strpos($parsed_doc_file[0], '/html/')) {
        $obj = json_decode(gzdecode(file_get_contents($parsed_doc_file[0])));
        $content = (base64_decode($obj->content));
    } else {
    }

    while (!is_null($content) and strlen($content) > 10) {
        if (in_array($billNo, [
            '1060519070202300', // 看起來對錯文件
            '1021223070201700', // 看起來對錯文件
            '1030102070200300', // 看起來對錯文件
            '1020415070202900', // 看起來對錯文件
        ])) {
            break;
        }
        if (property_exists($values, '屆期') and $values->{'屆期'}) {
            $obj->term = $values->{'屆期'};
        }
        $docdata = BillParser::parseBillDoc($billNo, $content, $obj);
        if (property_exists($docdata, '字號') and $docdata->{'字號'}) {
            $values->{'字號'} = $docdata->{'字號'};
            // 移掉全形空白
            $values->{'字號'} = str_replace('　', '', $values->{'字號'});
            if ($billNo == '1070810071002400') {
                $values->{'字號'} = '院總第887號政府提案第16100號';
            }
            if (preg_match('#^院總第(\d+)號(.*)提案第(\d+)號(之(\d*))?$#u', $values->{'字號'}, $matches)) {
            } else if (preg_match('#^院總第(\d+)號(.*)提案第(\d+)號((\d+))$#u', $values->{'字號'}, $matches)) {
            } else {
                break;
                throw new Exception('字號格式不正確: ' . $values->{'字號'});
            }
            if ($matches[2] == '委員') {
                $type = '委';
            } elseif ($matches[2] == '政府') {
                $type = '政';
            } else {
                throw new Exception('字號格式不正確: ' . $values->{'字號'});
            }
            $values->{'提案編號'} = sprintf("%d%s%d",
                intval($matches[1]),
                $type,
                intval($matches[3])
            );
            if (count($matches) > 4 and $matches[4] and intval($matches[5])) {
                $values->{'提案編號'} .= '之' . intval($matches[5]);
            }
        }

        foreach (['案由', '說明', '提案人', '連署人', '對照表'] as $k) {
            if (property_exists($docdata, $k) and $docdata->{$k} and (!property_exists($values, $k) or !$values->{$k})) {
                $values->{$k} = $docdata->{$k};
            }
        }
        $values = BillParser::linkToLawContent($values);
        break;
    }

    if (file_exists($bill_data_path)) {
        $origin_values = json_decode(gzdecode(file_get_contents($bill_data_path)));
        $changes = [];
        if ($origin_values->{'議案狀態'} != $values->{'議案狀態'}) {
            $changes[] = '議案狀態';
        }
        if (json_encode($origin_values->{'議案流程'}) != json_encode($values->{'議案流程'})) {
            $changes[] = '議案流程';
        }
        if ($changes) {
            Importer::addImportLog([
                'event' => 'bill-change',
                'group' => 'bill',
                'message' => sprintf("議案 %s 有變動: %s", $billNo, implode(',', $changes)),
                'data' => json_encode([
                    'billNo' => $billNo,
                    'changes' => $changes,
                ], JSON_UNESCAPED_UNICODE),
            ], $commit = false);
        }
    } else {
        Importer::addImportLog([
            'event' => 'bill-new',
            'group' => 'bill',
            'message' => sprintf("新增議案 %s", $billNo),
            'data' => json_encode([
                'billNo' => $billNo,
            ], JSON_UNESCAPED_UNICODE),
        ], $commit = false);
    }

    file_put_contents($bill_data_path, gzencode(json_encode($values, JSON_UNESCAPED_UNICODE)));
    touch($bill_data_path, $mtime);
    Elastic::dbBulkInsert('bill', $billNo, $values);
}

Importer::addImportLog(null, $commit = true);
Elastic::dbBulkCommit();
