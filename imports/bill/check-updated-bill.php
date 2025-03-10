<?php

include(__DIR__ . '/../../BillParser.php');


// 抓最近 100 則黨團協商會議的相關議案
$url = "https://v2.ly.govapi.tw/meets?會議種類=黨團協商&output_fields=日期&output_fields=議事網資料&limit=1000";
$obj = json_decode(file_get_contents($url));
$bill_latest_meets = [];
foreach ($obj->meets as $meet) {
    foreach ($meet->議事網資料 ?? [] as $record) {
        foreach ($record->關係文書->議案 ?? [] as $bill) {
            if (isset($bill_latest_meets[$bill->議案編號])) {
                continue;
            }
            $bill_latest_meets[$bill->議案編號] = $meet->日期[0];
        }
    }
}

$fp = fopen($_SERVER['argv'][1], 'r');
$seq = 0;
$total = intval(`wc -l {$_SERVER['argv'][1]}`);
while ($obj = json_decode(fgets($fp))) {
    $billNo = $obj->id;
    $seq ++;
    if ($seq % 1000 == 0) {
        error_log("{$seq} / {$total}");
    }
    if (!file_exists(__DIR__ . "/bill-html/{$billNo}.gz")) {
        continue;
    }
    if (array_key_exists($billNo, $bill_latest_meets)) {
        if (filemtime(__DIR__ . "/bill-html/{$billNo}.gz") < strtotime($bill_latest_meets[$billNo])) {
            error_log("{$billNo} 因為黨團協商已經過期");
            unlink(__DIR__ . "/bill-html/{$billNo}.gz");
            continue;
        }
    }
    //error_log($billNo);
    $content = gzdecode(file_get_contents(__DIR__ . "/bill-html/{$billNo}.gz"));
    if (strpos($content, '403 Forbidden')) {
        error_log("{$billNo} 403 Forbidden");
        unlink(__DIR__ . "/bill-html/{$billNo}.gz");
        continue;
    }
    if ($obj->billType == 20 and strpos($content, '委員會發文') and false === strpos($content, '關聯議案')) {
        error_log("{$billNo} 無關聯議案");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
        continue;
    }
    try {
        $values = BillParser::parseBillDetail($billNo, $content);
    } catch (Exception $e) {
        continue;
    }
    $values->{'議案狀態'} = trim(preg_replace('#\(.*\)$#', '', trim($values->{'議案狀態'})));
    $obj->content5 = trim(preg_replace('#\(.*\)$#', '', $obj->content5));
    if ($values->{'議案狀態'} != $obj->content5) {
        error_log(json_encode($obj, JSON_UNESCAPED_UNICODE));
        error_log("{$values->billNo} html={$values->{'議案狀態'}} list={$obj->content5}");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }
    // 沒有相關附件就重新抓取
    if ($obj->billType == 20 and !strpos(json_encode($values->{'相關附件'}, JSON_UNESCAPED_UNICODE), '關係文書DOC')) {
        error_log("{$billNo} 無相關附件");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }

    // 如果沒有議案流程日期就重新抓取
    if ($obj->billType == 20 and !($values->議案流程[0]->日期 ?? false)) {
        error_log("{$billNo} 無議案流程日期");
        rename(__DIR__ . "/bill-html/{$billNo}.gz", __DIR__ . "/bill-html/old/{$billNo}.gz");
    }
}
